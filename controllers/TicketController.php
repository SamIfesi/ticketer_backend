<?php

class TicketController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/tickets/:id
  // Protected: ticket owner or event organizer or dev
  // Returns ticket details + QR code URL
  //
  // NEW: for multi_day events, also returns days_used/total_days
  // so the attendee's ticket page can show "2/3 days used"
  // instead of a status that never changes from "valid".
  // ============================================================
  public function show(array $params): void
  {
    $ticketId = (int) $params['id'];
    $userId   = $this->request->user['id'];
    $role     = $this->request->user['role'];

    $stmt = $this->db->prepare("
            SELECT
                t.id ,
                t.booking_id,
                t.qr_token,
                t.is_used,
                t.used_at,
                t.created_at,
                t.user_id,
                b.quantity,
                b.total_amount,
                b.unit_price,
                b.payment_status,
                e.id          AS event_id,
                e.title       AS event_title,
                e.location    AS event_location,
                e.start_date  AS event_start_date,
                e.end_date    AS event_end_date,
                e.banner_image,
                e.organizer_id,
                e.checkin_mode,
                e.checkin_days,
                tt.name       AS ticket_type,
                u.name        AS attendee_name,
                u.email       AS attendee_email
            FROM tickets t
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN events       e  ON e.id  = t.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN users        u  ON u.id  = t.user_id
            WHERE t.id = ?
        ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
      Response::notFound('Ticket not found.');
    }

    // Only the ticket owner, event organizer, or dev can view it
    $isOwner     = (int) $ticket['user_id']      === $userId;
    $isOrganizer = (int) $ticket['organizer_id'] === $userId;
    $isDev       = $role === Constants::ROLE_DEV;

    if (!$isOwner && !$isOrganizer && !$isDev) {
      Response::forbidden('You do not have access to this ticket.');
    }

    // Only show ticket if booking is paid
    if ($ticket['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('This ticket is not yet confirmed.', 400);
    }

    // Generate QR code image and attach public URL
    QRCodeService::generate($ticket['qr_token']);
    $ticket['qr_code_url'] = QRCodeService::getUrl($ticket['qr_token']);
    $ticket['status'] = $ticket['is_used'] ? 'used' : 'valid';

    // after computing $ticket['status'] = $ticket['is_used'] ? 'used' : 'valid';
    if ($ticket['checkin_mode'] === 'multi_day') {
      $stmt = $this->db->prepare("SELECT COUNT(*) FROM ticket_checkins WHERE ticket_id = ?");
      $stmt->execute([$ticket['id']]);
      $ticket['days_used']  = (int) $stmt->fetchColumn();
      $ticket['total_days'] = (int) $ticket['checkin_days'];
    }

    // ── NEW: multi-day progress ──
    if (($ticket['checkin_mode'] ?? Constants::CHECKIN_MODE_SINGLE) === Constants::CHECKIN_MODE_MULTI_DAY) {
      $stmt = $this->db->prepare("SELECT COUNT(*) FROM ticket_checkins WHERE ticket_id = ?");
      $stmt->execute([$ticket['id']]);
      $ticket['days_used']  = (int) $stmt->fetchColumn();
      $ticket['total_days'] = (int) $ticket['checkin_days'];
      // status stays "valid" for the ticket's whole lifetime in this mode —
      // it never flips to "used" since it can be scanned again on other days.
    }

    // Don't expose the raw token — React only needs the image URL
    unset($ticket['qr_token']);

    Response::success(['ticket' => $ticket]);
  }

  // ============================================================
  // GET /api/tickets/booking/:bookingId
  // Protected: booking owner or dev
  // Returns ALL tickets under one booking
  // (when a user bought multiple tickets at once)
  //
  // NEW: same days_used/total_days addition as show() above,
  // resolved once per booking rather than once per ticket since
  // every ticket under a booking shares the same event.
  // ============================================================
  public function byBooking(array $params): void
  {
    $bookingId = (int) $params['bookingId'];
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    $stmt = $this->db->prepare("
            SELECT id, user_id, payment_status FROM bookings WHERE id = ?
        ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    $isOwner = (int) $booking['user_id'] === $userId;
    $isDev   = $role === Constants::ROLE_DEV;

    if (!$isOwner && !$isDev) {
      Response::forbidden('You do not have access to these tickets.');
    }

    if ($booking['payment_status'] !== Constants::PAYMENT_PAID) {
      Response::error('This booking is not yet confirmed.', 400);
    }

    $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.qr_token,
                t.is_used,
                t.used_at,
                t.created_at,
                t.unit_price,
                e.title        AS event_title,
                e.location     AS event_location,
                e.start_date   AS event_start_date,
                e.checkin_mode,
                e.checkin_days,
                tt.name        AS ticket_type
            FROM tickets t
            JOIN events       e  ON e.id  = t.event_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE t.booking_id = ?
        ");
    $stmt->execute([$bookingId]);
    $tickets = $stmt->fetchAll();

    // Batch-fetch days_used for all tickets in this booking in one query
    // rather than N+1 queries per ticket.
    $ticketIds = array_column($tickets, 'id');
    $daysUsedByTicket = [];

    if (!empty($ticketIds) && !empty($tickets) && ($tickets[0]['checkin_mode'] ?? '') === Constants::CHECKIN_MODE_MULTI_DAY) {
      $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
      $stmt = $this->db->prepare("
                SELECT ticket_id, COUNT(*) AS days_used
                FROM ticket_checkins
                WHERE ticket_id IN ({$placeholders})
                GROUP BY ticket_id
            ");
      $stmt->execute($ticketIds);
      foreach ($stmt->fetchAll() as $row) {
        $daysUsedByTicket[(int) $row['ticket_id']] = (int) $row['days_used'];
      }
    }

    foreach ($tickets as &$ticket) {
      QRCodeService::generate($ticket['qr_token']);
      $ticket['qr_code_url'] = QRCodeService::getUrl($ticket['qr_token']);
      $ticket['status'] = $ticket['is_used'] ? 'used' : 'valid';

      if ($ticket['checkin_mode'] === 'multi_day') {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM ticket_checkins WHERE ticket_id = ?");
        $stmt->execute([$ticket['id']]);
        $ticket['days_used']  = (int) $stmt->fetchColumn();
        $ticket['total_days'] = (int) $ticket['checkin_days'];
      }

      if (($ticket['checkin_mode'] ?? Constants::CHECKIN_MODE_SINGLE) === Constants::CHECKIN_MODE_MULTI_DAY) {
        $ticket['days_used']  = $daysUsedByTicket[(int) $ticket['id']] ?? 0;
        $ticket['total_days'] = (int) $ticket['checkin_days'];
      }

      unset($ticket['qr_token']);
    }
    unset($ticket);

    Response::success(['tickets' => $tickets]);
  }

  // ============================================================
  // POST /api/tickets/checkin
  // Protected: organizer or dev only
  // Called when organizer scans a QR code at the gate
  //
  // Body: { qr_token, day_number? }  — day_number only matters
  // for multi_day events; ignored/optional for single-scan events.
  //
  // RACE-SAFETY NOTE:
  // Both branches below are written to be safe against two scans
  // of the same ticket landing at the database at (almost) the same
  // instant — e.g. an organizer double-tapping, or a flaky QR reader
  // firing twice. We do NOT do a "SELECT is_used, then UPDATE" or
  // "SELECT exists, then INSERT" pattern, because two concurrent
  // requests can both pass the SELECT before either write commits,
  // letting a ticket get checked in twice. Instead:
  //   - single mode: the UPDATE itself carries the "not already
  //     used" condition (WHERE is_used = 0), and rowCount() tells us
  //     whether we actually won the race.
  //   - multi-day mode: we attempt the INSERT directly and let the
  //     UNIQUE KEY (ticket_id, day_number) on ticket_checkins reject
  //     a duplicate — the database itself is the single source of
  //     truth for "was this already scanned today", not a prior read.
  // ============================================================
  public function checkin(): void
  {
    $qrToken  = trim($this->request->input('qr_token', ''));
    $dayInput = $this->request->input('day_number', null); // organizer-picked, optional
    $userId   = $this->request->user['id'];
    $role     = $this->request->user['role'];

    if (empty($qrToken)) {
      Response::validationError(['qr_token' => 'QR token is required.']);
    }

    $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.is_used,
                t.used_at,
                t.user_id,
                e.id          AS event_id,
                e.title       AS event_title,
                e.organizer_id,
                e.start_date,
                e.checkin_mode,
                e.checkin_days,
                u.name        AS attendee_name,
                tt.name       AS ticket_type
            FROM tickets t
            JOIN events       e  ON e.id  = t.event_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN users        u  ON u.id  = t.user_id
            WHERE t.qr_token = ?
        ");
    $stmt->execute([$qrToken]);
    $ticket = $stmt->fetch();

    // Case 1 — QR token not found
    if (!$ticket) {
      Response::error('Invalid ticket. This QR code is not recognized.', 404);
    }

    // Case 2 — Organizer scanning a ticket for someone else's event
    if (
      $role === Constants::ROLE_ORGANIZER &&
      (int) $ticket['organizer_id'] !== $userId
    ) {
      Response::forbidden('This ticket is not for one of your events.');
    }

    $checkinMode = $ticket['checkin_mode'] ?? Constants::CHECKIN_MODE_SINGLE;

    // ────────────────────────────────────────────────────────────
    // SINGLE MODE — one scan, then permanently invalid
    // ────────────────────────────────────────────────────────────
    if ($checkinMode === Constants::CHECKIN_MODE_SINGLE) {
      // Atomic claim: only succeeds if is_used is still 0 at the
      // moment this exact statement runs. If a concurrent request
      // already flipped it, rowCount() comes back 0 here and we know
      // we lost the race — no separate read-then-write window exists.
      $stmt = $this->db->prepare("
                UPDATE tickets
                SET is_used = 1, used_at = NOW()
                WHERE id = ? AND is_used = 0
            ");
      $stmt->execute([$ticket['id']]);

      if ($stmt->rowCount() === 0) {
        // Refetch so the error message reflects the real used_at,
        // whether it was already used before this request or a
        // concurrent scan just claimed it a moment ago.
        $refetch = $this->db->prepare("SELECT used_at FROM tickets WHERE id = ?");
        $refetch->execute([$ticket['id']]);
        $usedAt = $refetch->fetchColumn();

        Response::error(
          'This ticket was already used' .
            ($usedAt ? ' at ' . date('d M Y, g:ia', strtotime($usedAt)) . '.' : '.'),
          400
        );
        return;
      }

      NotificationService::ticketCheckedIn(
        (int) $ticket['user_id'],
        (int) $ticket['id'],
        $ticket['event_title']
      );

      Response::success([
        'attendee_name' => $ticket['attendee_name'],
        'ticket_type'   => $ticket['ticket_type'],
        'event_title'   => $ticket['event_title'],
        'status'        => 'used',
        'checked_in_at' => date('d M Y, g:ia'),
        'checkin_mode'  => Constants::CHECKIN_MODE_SINGLE,
      ], 'Valid ticket. Attendee checked in successfully.');
      return;
    }

    // ────────────────────────────────────────────────────────────
    // MULTI-DAY MODE — one scan per day, up to checkin_days
    // ────────────────────────────────────────────────────────────
    $totalDays = (int) $ticket['checkin_days'];

    // If the scanner didn't send a day, default to "which day of the
    // event is it today", clamped into the valid range so an odd
    // system clock never produces an out-of-bounds day number.
    $dayNumber = $dayInput !== null
      ? (int) $dayInput
      : max(1, min($totalDays, (int) floor(
        (strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($ticket['start_date'])))) / 86400
      ) + 1));

    if ($dayNumber < 1 || $dayNumber > $totalDays) {
      Response::error("Invalid day. This event only has {$totalDays} check-in day(s).", 400);
      return;
    }

    // Atomic claim: attempt the insert directly and let the UNIQUE
    // KEY (ticket_id, day_number) do the "already checked in today"
    // check for us — this closes the same race window as above.
    try {
      $this->db->prepare("
                INSERT INTO ticket_checkins (ticket_id, event_id, day_number, checked_in_by)
                VALUES (?, ?, ?, ?)
            ")->execute([$ticket['id'], $ticket['event_id'], $dayNumber, $userId]);
    } catch (PDOException $e) {
      // SQLSTATE 23000 = integrity constraint violation, which is
      // exactly what our UNIQUE KEY throws on a duplicate (ticket_id, day_number).
      if ((string) $e->getCode() === '23000') {
        $stmt = $this->db->prepare("
                    SELECT created_at FROM ticket_checkins WHERE ticket_id = ? AND day_number = ?
                ");
        $stmt->execute([$ticket['id'], $dayNumber]);
        $when = $stmt->fetchColumn();

        Response::error(
          "Already checked in for Day {$dayNumber}/{$totalDays}" .
            ($when ? ' at ' . date('d M Y, g:ia', strtotime($when)) . '.' : '.'),
          400
        );
        return;
      }
      // Anything else is a genuine unexpected DB error — don't swallow it.
      throw $e;
    }

    // How many of the total days has this ticket now used?
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM ticket_checkins WHERE ticket_id = ?");
    $stmt->execute([$ticket['id']]);
    $daysUsed = (int) $stmt->fetchColumn();

    NotificationService::ticketCheckedIn(
      (int) $ticket['user_id'],
      (int) $ticket['id'],
      $ticket['event_title']
    );

    Response::success([
      'attendee_name' => $ticket['attendee_name'],
      'ticket_type'   => $ticket['ticket_type'],
      'event_title'   => $ticket['event_title'],
      'status'        => 'valid',
      'checked_in_at' => date('d M Y, g:ia'),
      'checkin_mode'  => Constants::CHECKIN_MODE_MULTI_DAY,
      'day_number'    => $dayNumber,
      'days_used'     => $daysUsed,
      'total_days'    => $totalDays,
    ], "Day {$dayNumber}/{$totalDays} check-in successful.");
  }

  // ============================================================
  // GET /api/organizer/events/:id/checkins
  // Protected: organizer or dev
  // Returns all tickets + check-in status for an event
  //
  // NEW: branches by event.checkin_mode.
  //   - single: unchanged behaviour, reads t.is_used directly.
  //   - multi_day: accepts ?day=N (defaults to 1, clamped to
  //     checkin_days), reports whether each ticket was scanned on
  //     that specific day via EXISTS against ticket_checkins, PLUS
  //     a days_used/total_days count per ticket so the frontend can
  //     render "2/3" regardless of which day tab is being viewed.
  // ============================================================
  public function checkinList(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    $stmt = $this->db->prepare('SELECT organizer_id, checkin_mode, checkin_days FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('This is not your event.');
    }

    $checkinMode = $event['checkin_mode'] ?? Constants::CHECKIN_MODE_SINGLE;
    $totalDays   = (int) ($event['checkin_days'] ?? 1);

    // ────────────────────────────────────────────────────────────
    // MULTI-DAY MODE
    // ────────────────────────────────────────────────────────────
    if ($checkinMode === Constants::CHECKIN_MODE_MULTI_DAY) {
      $requestedDay = (int) $this->request->query('day', '1');
      $day = max(1, min($totalDays, $requestedDay ?: 1));

      $stmt = $this->db->prepare("
                SELECT
                    t.id,
                    u.name        AS attendee_name,
                    u.email       AS attendee_email,
                    tt.name       AS ticket_type,
                    (
                        SELECT COUNT(*) FROM ticket_checkins tc
                        WHERE tc.ticket_id = t.id
                    ) AS days_used,
                    (
                        SELECT MAX(tc.created_at) FROM ticket_checkins tc
                        WHERE tc.ticket_id = t.id
                    ) AS last_checked_in_at,
                    EXISTS(
                        SELECT 1 FROM ticket_checkins tc
                        WHERE tc.ticket_id = t.id AND tc.day_number = ?
                    ) AS is_used
                FROM tickets t
                JOIN users        u  ON u.id  = t.user_id
                JOIN bookings     b  ON b.id  = t.booking_id
                JOIN ticket_types tt ON tt.id = b.ticket_type_id
                WHERE t.event_id = ?
                ORDER BY is_used ASC, u.name ASC
            ");
      $stmt->execute([$day, $eventId]);
      $tickets = $stmt->fetchAll();

      foreach ($tickets as &$t) {
        $t['is_used']    = (bool) $t['is_used'];
        $t['days_used']  = (int) $t['days_used'];
        $t['total_days'] = $totalDays;
      }
      unset($t);

      $total          = count($tickets);
      $checkedInToday = count(array_filter($tickets, fn($t) => $t['is_used']));

      Response::success([
        'checkin_mode' => Constants::CHECKIN_MODE_MULTI_DAY,
        'checkin_days' => $totalDays,
        'day'          => $day,
        'summary'      => [
          'total'      => $total,
          'checked_in' => $checkedInToday,
          'remaining'  => $total - $checkedInToday,
        ],
        'tickets' => $tickets,
      ]);
      return;
    }

    // ────────────────────────────────────────────────────────────
    // SINGLE MODE — unchanged behaviour
    // ────────────────────────────────────────────────────────────
    $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.is_used,
                t.used_at,
                u.name        AS attendee_name,
                u.email       AS attendee_email,
                tt.name       AS ticket_type
            FROM tickets t
            JOIN users        u  ON u.id  = t.user_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE t.event_id = ?
            ORDER BY t.is_used ASC, u.name ASC
        ");
    $stmt->execute([$eventId]);
    $tickets = $stmt->fetchAll();

    foreach ($tickets as &$t) {
      $t['is_used'] = (bool) $t['is_used'];
    }
    unset($t);

    $total     = count($tickets);
    $checkedIn = count(array_filter($tickets, fn($t) => $t['is_used']));

    Response::success([
      'checkin_mode' => Constants::CHECKIN_MODE_SINGLE,
      'checkin_days' => 1,
      'summary' => [
        'total'      => $total,
        'checked_in' => $checkedIn,
        'remaining'  => $total - $checkedIn,
      ],
      'tickets' => $tickets,
    ]);
  }
}
