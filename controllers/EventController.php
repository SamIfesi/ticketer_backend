<?php

class EventController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/events
  //
  // FIX #3: Replaced e.tickets_sold (removed column) with a
  // LEFT JOIN on v_event_sales for live accurate counts.
  // Also excludes soft-deleted events.
  public function index(): void
  {
    // Grab optional filter params from query string
    $page       = max(1, (int) $this->request->query('page', '1'));
    $limit      = min(50, max(1, (int) $this->request->query('limit', '12')));
    $offset     = ($page - 1) * $limit;
    $search     = trim($this->request->query('search', ''));
    $categoryId = $this->request->query('category', '');
    $dateFilter = $this->request->query('date', ''); // 'upcoming' | 'past' | ''

    // Build query dynamically based on filters
    $conditions = ["e.status = 'published'", "e.deleted_at IS NULL"];
    $params     = [];

    if (!empty($search)) {
      $conditions[] = '(e.title LIKE ? OR e.description LIKE ? OR e.location LIKE ?)';
      $params[]     = "%{$search}%";
      $params[]     = "%{$search}%";
      $params[]     = "%{$search}%";
    }

    if (!empty($categoryId)) {
      $conditions[] = 'e.category_id = ?';
      $params[]     = $categoryId;
    }

    if ($dateFilter === 'upcoming') {
      $conditions[] = 'e.start_date >= NOW()';
    } elseif ($dateFilter === 'past') {
      $conditions[] = 'e.end_date < NOW()';
    }

    $where = implode(' AND ', $conditions);

    $result = EventCache::rememberList(
      [
        'page' => $page,
        'limit' => $limit,
        'search' => $search,
        'category' => $categoryId,
        'date' => $dateFilter,
      ],
      function () use ($conditions, $params, $where, $page, $limit, $offset) {
        // Get total count for pagination
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM events e WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated events with organizer name and category name
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.slug,
                e.description,
                e.location,
                e.banner_image,
                e.start_date,
                e.end_date,
                e.total_tickets,
                e.status,
                e.created_at,
                COALESCE(s.tickets_sold, 0)      AS tickets_sold,
                COALESCE(s.tickets_available, 0)  AS tickets_available,
                u.name  AS organizer_name,
                c.name  AS category_name,
                c.icon  AS category_icon
            FROM events e
            JOIN users       u ON u.id = e.organizer_id
            LEFT JOIN categories  c ON c.id    = e.category_id
            LEFT JOIN v_event_sales s ON s.event_id = e.id
            WHERE {$where}
            ORDER BY CASE WHEN e.end_date < NOW() THEN 1 ELSE 0 END ASC, e.start_date ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);

        return [
          'events'     => $stmt->fetchAll(),
          'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
          ],
        ];
      }
    );

    Response::success($result);
  }

  // GET /api/events/:id
  // GET /api/events/:slug
  // NEW: expose checkin_mode / checkin_days so the frontend can
  // decide whether to show "x/y days used" on the attendee's
  // ticket views.
  public function show(array $params): void
  {
    $identifier = $params['id'];

    $event = EventCache::rememberDetail($identifier, function () use ($identifier) {
      $isNumeric = ctype_digit((string) $identifier);
      $column    = $isNumeric ? 'e.id' : 'e.slug';

      $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.slug,
                e.description,
                e.location,
                e.banner_image,
                e.contact_email,
                e.contact_phone,
                e.start_date,
                e.end_date,
                e.total_tickets,
                e.status,
                e.checkin_mode,
                e.checkin_days,
                e.created_at,
                COALESCE(s.tickets_sold, 0)      AS tickets_sold,
                COALESCE(s.tickets_available, 0)  AS tickets_available,
                u.id     AS organizer_id,
                u.name   AS organizer_name,
                c.name   AS category_name,
                c.icon   AS category_icon
            FROM events e
            JOIN users       u ON u.id = e.organizer_id
            LEFT JOIN categories  c ON c.id    = e.category_id
            LEFT JOIN v_event_sales s ON s.event_id = e.id
            WHERE {$column} = ?
              AND e.status = 'published'
              AND e.deleted_at IS NULL
        ");
      $stmt->execute([$identifier]);
      $event = $stmt->fetch();

      if (!$event) {
        return null;
      }

      // Also fetch the ticket types for this event
      $stmt = $this->db->prepare("
      SELECT id, name, description, price, quantity, quantity_sold, quantity_held, sales_end_at
      FROM ticket_types
      WHERE event_id = ?
      ORDER BY price ASC
  ");
      $stmt->execute([$event['id']]);
      $ticketTypes = $stmt->fetchAll();

      // Held tickets are invisible to the public: shrink the visible
      // capacity instead of exposing the hold itself.
      $totalHeld = 0;
      foreach ($ticketTypes as &$type) {
        $held = (int) $type['quantity_held'];
        $totalHeld += $held;
        $type['quantity']  = (int) $type['quantity'] - $held;
        $type['available'] = $type['quantity'] - (int) $type['quantity_sold'];
        unset($type['quantity_held']);
      }
      unset($type);

      $event['total_tickets']     = max(0, (int) $event['total_tickets'] - $totalHeld);
      $event['tickets_available'] = max(0, (int) $event['tickets_available'] - $totalHeld);

      $event['ticket_types'] = $ticketTypes;

      return $event;
    });

    if (!$event) {
      Response::notFound('Event not found.');
    }

    Response::success(['event' => $event]);
  }

  // POST /api/events
  // Protected: organizer or dev only
  //
  // NEW: checkin_mode / checkin_days are now actually persisted
  // to the events table (previously computed but never inserted).
  public function store(): void
  {
    $input  = $this->request->body;
    $errors = [];

    $phone = preg_replace('/[\s-]+/', '', trim($input['contact_phone'] ?? ''));
    $contactEmail = trim($input['contact_email'] ?? '');

    if (!empty($contactEmail) && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
      $errors['contact_email'] = 'Enter a valid enquiry email address.';
    }
    if (!empty($phone) && !preg_match('/^(\+234|234|0)(7|8|9)[0-1]\d{8}$/', $phone)) {
      $errors['contact_phone'] = 'Enter a valid enquiry phone number.';
    }

    if (empty($input['title'])) {
      $errors['title'] = 'Event title is required.';
    }
    if (empty($input['start_date'])) {
      $errors['start_date'] = 'Start date is required.';
    }
    if (empty($input['end_date'])) {
      $errors['end_date'] = 'End date is required.';
    }
    if (!empty($input['start_date']) && !empty($input['end_date'])) {
      if (strtotime($input['end_date']) <= strtotime($input['start_date'])) {
        $errors['end_date'] = 'End date must be after start date.';
      }
    }
    if (!isset($input['total_tickets']) || (int) $input['total_tickets'] < 1) {
      $errors['total_tickets'] = 'Total tickets must be at least 1.';
    }

    $checkinMode = in_array($input['checkin_mode'] ?? '', [Constants::CHECKIN_MODE_SINGLE, Constants::CHECKIN_MODE_MULTI_DAY], true)
      ? $input['checkin_mode']
      : Constants::CHECKIN_MODE_SINGLE;

    $checkinDays = $checkinMode === Constants::CHECKIN_MODE_MULTI_DAY
      ? max(1, min(30, (int) ($input['checkin_days'] ?? 1)))
      : 1;

    $payoutPlan = in_array($input['payout_plan'] ?? '', [Constants::PAYOUT_PLAN_STANDARD, Constants::PAYOUT_PLAN_EARLY], true)
      ? $input['payout_plan']
      : Constants::PAYOUT_PLAN_STANDARD;

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // ── NEW: block publishing if no bank details ──
    $requestedStatus = $input['status'] ?? Constants::EVENT_DRAFT;
    if ($requestedStatus === Constants::EVENT_PUBLISHED) {
      $bankStmt = $this->db->prepare("
            SELECT id FROM organizer_payment_details
            WHERE user_id = ? AND is_verified = 1
        ");
      $bankStmt->execute([$this->request->user['id']]);
      if (!$bankStmt->fetch()) {
        NotificationService::bankDetailsRequired((int)$this->request->user['id']);
        Response::error(
          'You must add your bank account details before publishing an event. Go to Payment Settings.',
          403
        );
      }
    }
    // ── END NEW ──

    // Generate a URL slug from the title
    $slug = $this->generateSlug($input['title']);

    // Make sure slug is unique
    $stmt = $this->db->prepare('SELECT id FROM events WHERE slug = ?');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
      // Append a random string if slug already exists
      $slug = $slug . '-' . substr(uniqid(), -4);
    }

    $stmt = $this->db->prepare("
            INSERT INTO events
                (organizer_id, category_id, title, slug, description, location, banner_image,
                 contact_email, contact_phone, start_date, end_date, total_tickets, status,
                 checkin_mode, checkin_days, payout_plan)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

    $stmt->execute([
      $this->request->user['id'],
      $input['category_id']  ?? null,
      trim($input['title']),
      $slug,
      $input['description']  ?? null,
      $input['location']     ?? null,
      $input['banner_image'] ?? null,
      $contactEmail ?: null,
      $phone ?: null,
      $input['start_date'],
      $input['end_date'],
      (int) $input['total_tickets'],
      $input['status']       ?? Constants::EVENT_DRAFT,
      $checkinMode,
      $checkinDays,
      $payoutPlan
    ]);

    $eventId = $this->db->lastInsertId();

    // If ticket types were sent, insert them too
    if (!empty($input['ticket_types']) && is_array($input['ticket_types'])) {
      $this->insertTicketTypes($eventId, $input['ticket_types']);
    }

    // Fetch and return the newly created event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);

    EventCache::bumpListVersion(); // new event → listing pages need refreshing

    Response::success(['event' => $stmt->fetch()], 'Event created successfully.', 201);
  }

  // PUT /api/events/:id
  // Protected: organizer (own events only) or dev
  public function update(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    // Organizers can only edit their own events
    // Dev can edit any event
    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('You can only edit your own events.');
    }

    $input = $this->request->body;
    $errors = [];
    $phone  = preg_replace('/[\s-]+/', '', trim($input['contact_phone'] ?? ''));

    if (!empty($input['start_date']) && !empty($input['end_date'])) {
      if (strtotime($input['end_date']) <= strtotime($input['start_date'])) {
        $errors['end_date'] = 'End date must be after start date.';
      }
    }
    if (!empty($input['contact_email']) && !filter_var($input['contact_email'], FILTER_VALIDATE_EMAIL)) {
      $errors['contact_email'] = 'Enter a valid enquiry email address.';
    }
    if (!empty($phone) && !preg_match('/^(\+234|234|0)(7|8|9)[0-1]\d{8}$/', $phone)) {
      $errors['contact_phone'] = 'Enter a valid enquiry phone number.';
    }

    // ── Check-in mode / days — only touch what was actually sent ──
    $checkinModeProvided = array_key_exists('checkin_mode', $input);
    $checkinDaysProvided = array_key_exists('checkin_days', $input);

    $checkinMode = null; // null => COALESCE keeps existing DB value
    $checkinDays = null;
    $payoutPlan = null; // null => COALESCE keeps existing DB value

    if ($checkinModeProvided) {
      $checkinMode = in_array($input['checkin_mode'], [Constants::CHECKIN_MODE_SINGLE, Constants::CHECKIN_MODE_MULTI_DAY], true)
        ? $input['checkin_mode']
        : Constants::CHECKIN_MODE_SINGLE;
    }

    if (array_key_exists('payout_plan', $input)) {
      $payoutPlan = in_array($input['payout_plan'], [Constants::PAYOUT_PLAN_STANDARD, Constants::PAYOUT_PLAN_EARLY], true)
        ? $input['payout_plan']
        : Constants::PAYOUT_PLAN_STANDARD;
    }

    if ($checkinModeProvided || $checkinDaysProvided) {
      // Whichever mode ends up in effect (new one if sent, else the
      // event's current one) decides whether days collapses to 1.
      $effectiveMode = $checkinMode ?? $event['checkin_mode'] ?? Constants::CHECKIN_MODE_SINGLE;

      $checkinDays = $effectiveMode === Constants::CHECKIN_MODE_MULTI_DAY
        ? max(1, min(30, (int) ($input['checkin_days'] ?? $event['checkin_days'] ?? 1)))
        : 1;
    }

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // ── NEW: block publishing without bank details ──
    if (isset($input['status']) && $input['status'] === Constants::EVENT_PUBLISHED) {
      $bankStmt = $this->db->prepare("
        SELECT id FROM organizer_payment_details
        WHERE user_id = ? AND is_verified = 1
      ");
      $bankStmt->execute([$this->request->user['id']]);
      if (!$bankStmt->fetch()) {
        NotificationService::bankDetailsRequired((int)$this->request->user['id']);
        Response::error(
          'You must add your bank account details before publishing an event. Go to Payment Settings.',
          403
        );
      }
    }
    // ── END NEW ──

    // ── NEW: ticket-type guards (holds validation + price-change confirmation) ──
    $priceChanges = [];
    if (!empty($input['ticket_types']) && is_array($input['ticket_types'])) {
      $existingStmt = $this->db->prepare("
        SELECT id, name, price, quantity, quantity_sold, quantity_held
        FROM ticket_types WHERE event_id = ?
      ");
      $existingStmt->execute([$eventId]);
      $existingTypes = [];
      foreach ($existingStmt->fetchAll() as $row) {
        $existingTypes[(int) $row['id']] = $row;
      }

      foreach ($input['ticket_types'] as $type) {
        $typeId = (int) ($type['id'] ?? 0);
        if (!$typeId || !isset($existingTypes[$typeId])) continue;
        $old = $existingTypes[$typeId];

        // Holds can't be negative or exceed what's left unsold
        $newQty  = isset($type['quantity'])      ? (int) $type['quantity']      : (int) $old['quantity'];
        $newHeld = isset($type['quantity_held']) ? (int) $type['quantity_held'] : (int) $old['quantity_held'];
        if ($newHeld < 0 || $newHeld + (int) $old['quantity_sold'] > $newQty) {
          $errors['ticket_types'] = "Held tickets for \"{$old['name']}\" can't exceed the unsold quantity ("
            . max(0, $newQty - (int) $old['quantity_sold']) . ').';
        }

        // Price-change guard (published events only)
        if (isset($type['price']) && $event['status'] === Constants::EVENT_PUBLISHED) {
          $oldPrice = (float) $old['price'];
          $newPrice = (float) $type['price'];
          $sold     = (int) $old['quantity_sold'];
          $goesFree = $newPrice == 0.0 && $oldPrice > 0.0;

          if ($newPrice !== $oldPrice && ($goesFree || $sold > 0)) {
            $priceChanges[] = [
              'ticket_type_id' => $typeId,
              'tier'           => $old['name'],
              'old_price'      => $oldPrice,
              'new_price'      => $newPrice,
              'quantity_sold'  => $sold,
              'goes_free'      => $goesFree,
            ];
          }
        }
      }

      if (!empty($errors)) {
        Response::validationError($errors);
      }

      if (!empty($priceChanges) && empty($input['confirm_price_change'])) {
        Response::conflict(
          'You are changing the price of a live ticket tier. Please confirm to continue.',
          ['code' => 'PRICE_CHANGE_CONFIRMATION_REQUIRED', 'price_changes' => $priceChanges]
        );
      }
    }
    // ── END NEW ──

    // Upsert ticket types
    if (!empty($input['ticket_types']) && is_array($input['ticket_types'])) {
      foreach ($input['ticket_types'] as $type) {
        if (!empty($type['id'])) {
          // Update existing ticket type
          $this->db->prepare("
            UPDATE ticket_types SET
              name         = COALESCE(?, name),
              description  = COALESCE(NULLIF(?, ''), description),
              price        = COALESCE(?, price),
              quantity     = COALESCE(?, quantity),
              quantity_held = COALESCE(?, quantity_held),
              sales_end_at = ?..
            WHERE id = ? AND event_id = ?
          ")->execute([
            $type['name']         ?? null,
            $type['description']  ?? '',
            $type['price']        ?? null,
            $type['quantity']     ?? null,
            isset($type['quantity_held']) ? (int) $type['quantity_held'] : null,
            $type['sales_end_at'] ?: null,
            (int) $type['id'],
            $eventId,
          ]);
        } else {
          // Insert new ticket type
          if (empty($type['name']) || !isset($type['price']) || empty($type['quantity'])) {
            continue;
          }
          $this->db->prepare("
            INSERT INTO ticket_types
              (event_id, name, description, price, quantity, sales_end_at)
            VALUES (?, ?, ?, ?, ?, ?)
          ")->execute([
            $eventId,
            trim($type['name']),
            $type['description']  ?? null,
            (float) $type['price'],
            (int)   $type['quantity'],
            $type['sales_end_at'] ?: null,
          ]);
        }
      }
    }
    // Update event fields
    $contactEmailProvided = array_key_exists('contact_email', $input);
    $contactPhoneProvided = array_key_exists('contact_phone', $input);

    $stmt = $this->db->prepare("
      UPDATE events SET
        category_id   = COALESCE(?, category_id),
        title         = COALESCE(?, title),
        description   = COALESCE(?, description),
        location      = COALESCE(?, location),
        banner_image  = COALESCE(?, banner_image),
        contact_email = CASE WHEN ? THEN ? ELSE contact_email END,
        contact_phone = CASE WHEN ? THEN ? ELSE contact_phone END,
        start_date    = COALESCE(?, start_date),
        end_date      = COALESCE(?, end_date),
        total_tickets = COALESCE(?, total_tickets),
        status        = COALESCE(?, status),
        checkin_mode  = COALESCE(?, checkin_mode),
        checkin_days  = COALESCE(?, checkin_days),
        payout_plan   = COALESCE(?, payout_plan)
      WHERE id = ?
    ");

    $stmt->execute([
      $input['category_id']   ?? null,
      $input['title']         ?? null,
      $input['description']   ?? null,
      $input['location']      ?? null,
      $input['banner_image']  ?? null,
      $contactEmailProvided,
      $contactEmailProvided ? (trim($input['contact_email']) ?: null) : null,
      $contactPhoneProvided,
      $contactPhoneProvided ? (trim($input['contact_phone']) ?: null) : null,
      $input['start_date']    ?? null,
      $input['end_date']      ?? null,
      $input['total_tickets'] ?? null,
      $input['status']        ?? null,
      $checkinMode,
      $checkinDays,
      $payoutPlan,
      $eventId,
    ]);

    // Return updated event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $updatedEvent = $stmt->fetch();

    // ── NEW: audit trail for confirmed price changes ──
    foreach ($priceChanges as $c) {
      try {
        $desc = mb_substr(
          "Event #{$eventId} tier \"{$c['tier']}\" price {$c['old_price']} -> {$c['new_price']} ({$c['quantity_sold']} already sold)",
          0,
          255
        );
        $this->db->prepare("
          INSERT INTO activity_logs (user_id, action, description, ip_address)
          VALUES (?, 'ticket_price_changed', ?, ?)
        ")->execute([$userId, $desc, $_SERVER['REMOTE_ADDR'] ?? null]);
      } catch (Exception $e) {
        error_log('Price change log error: ' . $e->getMessage());
      }
    }
    // ── END NEW ──

    // ── NEW: update payout hold_until if end_date changed ──
    if (!empty($input['end_date'])) {
      PayoutService::setHoldUntil($eventId, $updatedEvent['end_date']);
    }
    // ── END NEW ──

    // Edited events can be looked up under either their old or new slug
    // (slug itself is never changed by update()), so clear both the id
    // and slug keys, and let listing pages pick up the change too.
    EventCache::forgetDetail($eventId, $updatedEvent['slug']);
    EventCache::bumpListVersion();

    Response::success(['event' => $updatedEvent], 'Event updated successfully.');
  }

  // DELETE /api/events/:id
  // Protected: organizer (own events) or admin or dev
  //
  // FIX #4: Clarified intent — this is a CANCELLATION, not a
  // deletion. Status is set to 'cancelled'. The event remains
  // visible to the organizer in their dashboard. Tickets and
  // bookings are preserved.
  //
  // To fully soft-delete (hide from organizer dashboard too),
  // admin uses PUT /api/admin/events/:id/status with 'deleted'.
  // That stamps deleted_at and triggers the activity_log entry.
  public function destroy(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    // Organizers can only delete their own events
    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('You can only cancel your own events.');
    }

    // Instead of hard deleting, mark as cancelled
    // This preserves booking history for users who already bought tickets
    $this->db->prepare("UPDATE events SET status = 'cancelled' WHERE id = ?")
      ->execute([$eventId]);

    // ── NEW: strike system + notify attendees ──
    $wasFlagged = PayoutService::recordCancellation((int)$event['organizer_id']);
    PayoutService::cancelPayout($eventId);

    NotificationService::notifyEventAttendees(
      $this->db,
      $eventId,
      'event_cancelled',
      "Event Cancelled — {$event['title']}",
      "{$event['title']} has been cancelled by the organizer. Please contact support if you need a refund.",
      "/my-bookings"
    );
    // ── END NEW ──

    EventCache::forgetDetail($eventId, $event['slug']);
    EventCache::bumpListVersion();

    Response::success(null, 'Event cancelled successfully. All existing bookings and tickets are preserved.');
  }

  // GET /api/organizer/events
  //
  // FIX #3: Replaced e.tickets_sold with v_event_sales join.
  // FIX: Excludes soft-deleted events from organizer view.
  public function myEvents(): void
  {
    $userId = $this->request->user['id'];
    $role   = $this->request->user['role'];

    if ($role === Constants::ROLE_DEV) {
      // Dev sees every event on the platform
      $stmt = $this->db->prepare("
                SELECT
                    e.*,
                    COALESCE(s.tickets_sold, 0)      AS tickets_sold,
                    COALESCE(s.tickets_available, 0)  AS tickets_available,
                    COALESCE(s.total_revenue, 0)      AS total_revenue,
                    u.name AS organizer_name,
                    c.name AS category_name
                FROM events e
                JOIN users u ON u.id = e.organizer_id
                LEFT JOIN categories  c ON c.id    = e.category_id
                LEFT JOIN v_event_sales s ON s.event_id = e.id
                ORDER BY e.created_at DESC
            ");
      $stmt->execute();
    } else {
      $stmt = $this->db->prepare("
                SELECT
                    e.*,
                    COALESCE(s.tickets_sold, 0)      AS tickets_sold,
                    COALESCE(s.tickets_available, 0)  AS tickets_available,
                    COALESCE(s.total_revenue, 0)      AS total_revenue,
                    c.name AS category_name
                FROM events e
                LEFT JOIN categories  c ON c.id    = e.category_id
                LEFT JOIN v_event_sales s ON s.event_id = e.id
                WHERE e.organizer_id = ?
                  AND e.deleted_at IS NULL
                ORDER BY e.created_at DESC
            ");
      $stmt->execute([$userId]);
    }
    $events = array_map([$this, 'castEventFields'], $stmt->fetchAll());
    Response::success(['events' => $events]);
  }

  private function castEventFields(array $event): array
  {
    $event['id']                 = (int) $event['id'];
    $event['organizer_id']       = (int) $event['organizer_id'];
    $event['category_id']        = $event['category_id'] !== null ? (int) $event['category_id'] : null;
    $event['total_tickets']      = (int) $event['total_tickets'];
    $event['tickets_sold']       = (int) $event['tickets_sold'];
    $event['tickets_available']  = (int) $event['tickets_available'];
    $event['total_revenue']      = (float) $event['total_revenue'];
    if (isset($event['checkin_days'])) {
      $event['checkin_days'] = (int) $event['checkin_days'];
    }
    return $event;
  }
  // PRIVATE HELPERS

  /**
   * Convert an event title to a URL-friendly slug
   * "My Event Title!" → "my-event-title"
   */
  private function generateSlug(string $title): string
  {
    $slug = strtolower(trim($title));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
  }

  /**
   * Insert ticket types for a newly created event
   */
  private function insertTicketTypes(int $eventId, array $ticketTypes): void
  {
    $stmt = $this->db->prepare("
            INSERT INTO ticket_types (event_id, name, description, price, quantity, sales_end_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

    foreach ($ticketTypes as $type) {
      if (empty($type['name']) || !isset($type['price']) || empty($type['quantity'])) {
        continue; // skip incomplete ticket types
      }
      $stmt->execute([
        $eventId,
        trim($type['name']),
        $type['description']  ?? null,
        (float) $type['price'],
        (int)   $type['quantity'],
        $type['sales_end_at'] ?? null,
      ]);
    }
  }

  public function showOwn(array $params): void
  {
    $identifier = $params['id'];
    $userId     = $this->request->user['id'];
    $role       = $this->request->user['role'];

    // Accept either a numeric event id or a slug on the same route.
    // Ownership check still runs on organizer_id, so this stays safe.
    $isNumeric = ctype_digit((string) $identifier);
    $column    = $isNumeric ? 'e.id' : 'e.slug';

    $stmt = $this->db->prepare("
        SELECT e.*,
            COALESCE(s.tickets_sold, 0)      AS tickets_sold,
            COALESCE(s.tickets_available, 0)  AS tickets_available,
            COALESCE(s.total_revenue, 0)      AS total_revenue,
            u.name AS organizer_name,
            c.name AS category_name
        FROM events e
        JOIN users u ON u.id = e.organizer_id
        LEFT JOIN categories c ON c.id = e.category_id
        LEFT JOIN v_event_sales s ON s.event_id = e.id
        WHERE {$column} = ?
          AND e.deleted_at IS NULL
          AND (e.organizer_id = ? OR ? = 'dev' OR ? = 'admin')
    ");
    $stmt->execute([$identifier, $userId, $role, $role]);
    $event = $stmt->fetch();

    if (!$event) Response::notFound('Event not found.');

    // Always resolve to the real numeric id for anything downstream —
    // never trust $identifier past this point, it may be a slug.
    $eventId = (int) $event['id'];

    $stmt = $this->db->prepare("
      SELECT
          tt.id, tt.name, tt.description, tt.price, tt.quantity,
          tt.quantity_sold, tt.quantity_held, tt.sales_end_at,
          COALESCE(SUM(CASE WHEN b.payment_status = 'paid'
                            THEN b.total_amount END), 0)            AS revenue,
          COALESCE(SUM(CASE WHEN b.payment_status = 'paid' AND b.total_amount = 0
                            THEN b.quantity END), 0)                AS free_sold,
          COALESCE(SUM(CASE WHEN b.payment_status = 'paid' AND b.total_amount > 0
                            THEN b.quantity END), 0)                AS paid_sold
      FROM ticket_types tt
      LEFT JOIN bookings b
             ON b.ticket_type_id = tt.id AND b.deleted_at IS NULL
      WHERE tt.event_id = ?
      GROUP BY tt.id
      ORDER BY tt.price ASC
      ");
    $stmt->execute([$eventId]);
    $types = $stmt->fetchAll();
    foreach ($types as &$t) {
      $t['revenue']   = (float) $t['revenue'];
      $t['free_sold'] = (int) $t['free_sold'];
      $t['paid_sold'] = (int) $t['paid_sold'];
    }
    unset($t);
    $event['ticket_types'] = $types;

    Response::success(['event' => $event]);
  }
}
