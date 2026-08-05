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

    Response::success([
      'events'     => $stmt->fetchAll(),
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/events/:id
  // GET /api/events/:slug
  // NEW: expose checkin_mode / checkin_days so the frontend can
  // decide whether to show "x/y days used" on the attendee's
  // ticket views.
  public function show(array $params): void
  {
    $identifier = $params['id'];
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
      Response::notFound('Event not found.');
    }

    // Also fetch the ticket types for this event
    $stmt = $this->db->prepare("
            SELECT id, name, description, price, quantity, quantity_sold, sales_end_at
            FROM ticket_types
            WHERE event_id = ?
            ORDER BY price ASC
        ");
    $stmt->execute([$event['id']]);
    $ticketTypes = $stmt->fetchAll();

    // Add available count to each ticket type
    foreach ($ticketTypes as &$type) {
      $type['available'] = (int) $type['quantity'] - (int) $type['quantity_sold'];
    }

    $event['ticket_types'] = $ticketTypes;

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
                 checkin_mode, checkin_days)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
    ]);

    $eventId = $this->db->lastInsertId();

    // If ticket types were sent, insert them too
    if (!empty($input['ticket_types']) && is_array($input['ticket_types'])) {
      $this->insertTicketTypes($eventId, $input['ticket_types']);
    }

    // Fetch and return the newly created event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);

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

    if ($checkinModeProvided) {
      $checkinMode = in_array($input['checkin_mode'], [Constants::CHECKIN_MODE_SINGLE, Constants::CHECKIN_MODE_MULTI_DAY], true)
        ? $input['checkin_mode']
        : Constants::CHECKIN_MODE_SINGLE;
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
              sales_end_at = ?
            WHERE id = ? AND event_id = ?
          ")->execute([
            $type['name']         ?? null,
            $type['description']  ?? '',
            $type['price']        ?? null,
            $type['quantity']     ?? null,
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
        checkin_days  = COALESCE(?, checkin_days)
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
      $eventId,
    ]);

    // Return updated event
    $stmt = $this->db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $updatedEvent = $stmt->fetch();

    // ── NEW: update payout hold_until if end_date changed ──
    if (!empty($input['end_date'])) {
      PayoutService::setHoldUntil($eventId, $updatedEvent['end_date']);
    }
    // ── END NEW ──

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
        SELECT id, name, description, price, quantity, quantity_sold, sales_end_at
        FROM ticket_types WHERE event_id = ? ORDER BY price ASC
    ");
    $stmt->execute([$eventId]);
    $event['ticket_types'] = $stmt->fetchAll();

    Response::success(['event' => $event]);
  }
}
