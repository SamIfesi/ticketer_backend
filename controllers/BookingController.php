<?php

class BookingController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // POST /api/bookings
  // Protected: attendee or dev
  // Step 1 of payment flow — creates a pending booking
  // and returns Paystack payment details to React
  // ============================================================
  public function store(): void
  {
    $userId       = $this->request->user['id'];
    $userEmail    = $this->request->user['email'];
    $ticketTypeId = (int) $this->request->input('ticket_type_id');
    $quantity     = max(1, (int) $this->request->input('quantity', 1));

    if (!$ticketTypeId) {
      Response::validationError(['ticket_type_id' => 'Please select a ticket type.']);
    }

    // 1. Fetch ticket type + event details
    $stmt = $this->db->prepare("
            SELECT
                tt.*,
                e.id         AS event_id,
                e.title      AS event_title,
                e.status     AS event_status,
                e.location   AS event_location,
                e.start_date AS event_start_date
            FROM ticket_types tt
            JOIN events e ON e.id = tt.event_id
            WHERE tt.id = ?
        ");
    $stmt->execute([$ticketTypeId]);
    $ticketType = $stmt->fetch();

    if (!$ticketType) {
      Response::notFound('Ticket type not found.');
    }

    // 2. Make sure the event is still published
    if ($ticketType['event_status'] !== Constants::EVENT_PUBLISHED) {
      Response::error('This event is no longer available.', 400);
    }

    // 3. Make sure the event hasn't started yet
    if (strtotime($ticketType['event_start_date']) < time()) {
      Response::error('Ticket sales for this event have ended.', 400);
    }

    // 4. Check if sales deadline has passed (if one was set)
    if ($ticketType['sales_end_at'] && strtotime($ticketType['sales_end_at']) < time()) {
      Response::error('Ticket sales for this tier have ended.', 400);
    }

    // 5. Cap quantity at 10 per order
    if ($quantity > 10) {
      Response::error('You can only purchase up to 10 tickets per order.', 400);
    }

    $unitPrice   = (float) $ticketType['price'];
    $totalAmount = $unitPrice * $quantity;

    // ── FIX: wrap availability check + insert in a transaction
    //    with a row lock to prevent race conditions
    $this->db->beginTransaction();

    try {
      // 6. Lock the ticket type row so no concurrent request
      //    can pass the availability check before we commit
      $stmt = $this->db->prepare("
                SELECT quantity, quantity_sold, (quantity - quantity_sold) AS available
                FROM ticket_types
                WHERE id = ?
                FOR UPDATE
            ");
      $stmt->execute([$ticketTypeId]);
      $locked    = $stmt->fetch();
      $available = (int) $locked['available'];

      if ($quantity > $available) {
        $this->db->rollBack();
        Response::error(
          $available === 0
            ? 'Sorry, this ticket is sold out. Please contact the organizer for more information.'
            : "Only {$available} ticket(s) remaining.",
          400
        );
        return;
      }

      // ── FREE TICKET ──────────────────────────────────────
      if ($totalAmount == 0) {
        $stmt = $this->db->prepare("
                    INSERT INTO bookings
                        (user_id, event_id, ticket_type_id, quantity, unit_price, total_amount, payment_status, paid_at)
                    VALUES
                        (?, ?, ?, ?, 0, 0, 'paid', NOW())
                ");
        $stmt->execute([
          $userId,
          $ticketType['event_id'],
          $ticketTypeId,
          $quantity,
        ]);
        $bookingId = (int) $this->db->lastInsertId();

        // Generate + save the professional booking number now that we have a real id
        $bookingNumber = TokenHelper::generateDisplayId($bookingId, 'BK');
        $this->db->prepare("UPDATE bookings SET booking_number = ? WHERE id = ?")
          ->execute([$bookingNumber, $bookingId]);

        // FIX: manually increment quantity_sold for free tickets
        // The trg_booking_paid trigger only fires on UPDATE not INSERT
        // so we need to do this manually here
        $this->db->prepare("
                    UPDATE ticket_types
                    SET quantity_sold = quantity_sold + ?
                    WHERE id = ?
                ")->execute([$quantity, $ticketTypeId]);

        // Issue one ticket row per quantity purchased
        $tickets = [];
        $stmt    = $this->db->prepare("
                    INSERT INTO tickets (booking_id, user_id, event_id, qr_token)
                    VALUES (?, ?, ?, ?)
                ");
        for ($i = 0; $i < $quantity; $i++) {
          $qrToken = TokenHelper::generateQRToken();
          $stmt->execute([
            $bookingId,
            $userId,
            $ticketType['event_id'],
            $qrToken,
          ]);
          $ticketId     = (int) $this->db->lastInsertId();
          $ticketNumber = TokenHelper::generateDisplayId($ticketId, 'TK');
          $this->db->prepare("UPDATE tickets SET ticket_number = ? WHERE id = ?")
            ->execute([$ticketNumber, $ticketId]);

          $tickets[] = [
            'id'            => $ticketId,
            'ticket_number' => $ticketNumber,
            'qr_token'      => $qrToken,
          ];
        }

        $this->db->commit();

        // Send confirmation email outside the transaction
        QueueService::sendTicketConfirmation(
          $userEmail,
          $this->request->user['name'],
          $ticketType['event_title'],
          $ticketType['event_start_date'],
          $ticketType['event_location'] ?? '',
          $ticketType['name'],
          $quantity,
          0,
          Environment::get('FRONTEND_URL') . '/my-tickets',
          'free-' . $bookingId
        );
        QueueService::generateTicket($bookingId, 10); // Queue ticket PDF generation

        Response::success([
          'booking_id'     => $bookingId,
          'booking_number' => $bookingNumber,
          'free'           => true,
          'tickets'        => $tickets,
        ], 'Your free ticket has been issued!');
        return;
      }

      // ── PAID TICKET ──────────────────────────────────────
      // 7. Generate a unique Paystack reference
      $reference = TokenHelper::generatePaystackReference();

      // 8. Create a PENDING booking — stays pending until payment verified
      $stmt = $this->db->prepare("
                INSERT INTO bookings
                    (user_id, event_id, ticket_type_id, quantity, unit_price, total_amount, paystack_reference, payment_status)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
      $stmt->execute([
        $userId,
        $ticketType['event_id'],
        $ticketTypeId,
        $quantity,
        $unitPrice,
        $totalAmount,
        $reference,
      ]);
      $bookingId = (int) $this->db->lastInsertId();

      // Generate + save the professional booking number.
      $bookingNumber = TokenHelper::generateDisplayId($bookingId, 'BK');
      $this->db->prepare("UPDATE bookings SET booking_number = ? WHERE id = ?")
        ->execute([$bookingNumber, $bookingId]);

      // Commit before calling Paystack — no point holding
      // a DB lock while waiting on an external HTTP request
      $this->db->commit();

      // 9. Initialize transaction with Paystack
      try {
        $paystack    = new PaystackService();
        $transaction = $paystack->initializeTransaction(
          $userEmail,
          $totalAmount,
          $reference,
          [
            'booking_id'  => $bookingId,
            'event_title' => $ticketType['event_title'],
            'quantity'    => $quantity,
          ]
        );

        // ── NEW: transaction audit log ──
        $orgStmt = $this->db->prepare("SELECT organizer_id FROM events WHERE id = ?");
        $orgStmt->execute([$ticketType['event_id']]);
        $organizerId = (int) $orgStmt->fetchColumn();

        TransactionService::paymentInitiated([
          'id'                 => $bookingId,
          'user_id'            => $userId,
          'event_id'           => $ticketType['event_id'],
          'paystack_reference' => $reference,
          'quantity'           => $quantity,
          'unit_price'         => $unitPrice,
          'ticket_type_name'   => $ticketType['name'],
          'event_title'        => $ticketType['event_title'],
        ], $organizerId);
        // ── END NEW ──

        Response::success([
          'booking_id'        => $bookingId,
          'booking_number'    => $bookingNumber,
          'reference'         => $reference,
          'amount'            => $totalAmount,
          'authorization_url' => $transaction['authorization_url'],
          'access_code'       => $transaction['access_code'],
        ], 'Payment initialized. Complete your payment to confirm booking.');
      } catch (Exception $e) {
        // If Paystack initialization fails, clean up the pending booking
        $this->db->prepare('DELETE FROM bookings WHERE id = ?')->execute([$bookingId]);
        error_log('Paystack init error: ' . $e->getMessage());
        Response::error('Payment initialization failed. Please try again.', 500);
      }
    } catch (Exception $e) {
      if ($this->db->inTransaction()) {
        $this->db->rollBack();
      }
      error_log('Booking store error: ' . $e->getMessage());
      Response::error('Could not complete booking. Please try again.', 500);
    }
  }

  public function resume(): void
  {
    $userId       = $this->request->user['id'];
    $ticketTypeId = (int) $this->request->input('ticket_type_id');
    $quantity     = max(1, (int) $this->request->input('quantity', 1));

    // Look for a recent pending booking (within 45 minutes)
    $stmt = $this->db->prepare("
        SELECT b.*, tt.name AS ticket_type_name, e.title AS event_title
        FROM bookings b
        JOIN ticket_types tt ON tt.id = b.ticket_type_id
        JOIN events e ON e.id = b.event_id
        WHERE b.user_id        = ?
          AND b.ticket_type_id = ?
          AND b.quantity       = ?
          AND b.payment_status = 'pending'
          AND b.deleted_at     IS NULL
          AND b.created_at     >= DATE_SUB(NOW(), INTERVAL 45 MINUTE)
        ORDER BY b.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $ticketTypeId, $quantity]);
    $existing = $stmt->fetch();

    if (!$existing) {
      Response::success(['has_pending' => false]);
      return;
    }

    // Re-initialize with the same reference
    try {
      $paystack    = new PaystackService();
      $userEmail   = $this->request->user['email'];
      $newReference = TokenHelper::generatePaystackReference();

      $transaction = $paystack->initializeTransaction(
        $userEmail,
        (float) $existing['total_amount'],
        $newReference,
        [
          'booking_id'  => $existing['id'],
          'event_title' => $existing['event_title'],
          'quantity'    => $existing['quantity'],
        ]
      );

      // Update the booking with the new reference
      $this->db->prepare(
        "UPDATE bookings SET paystack_reference = ? WHERE id = ?"
      )->execute([$newReference, $existing['id']]);

      Response::success([
        'has_pending'    => true,
        'booking_id'     => (int) $existing['id'],
        'booking_number' => $existing['booking_number'],
        'reference'      => $newReference,
        'amount'         => (float) $existing['total_amount'],
        'access_code'    => $transaction['access_code'],
      ]);
    } catch (Exception $e) {
      Response::success(['has_pending' => false]);
    }
  }

  // ============================================================
  // POST /api/bookings/verify
  // Protected: attendee or dev
  // Step 2 — verifies Paystack payment and issues tickets.
  //
  // quantity_sold is handled by trg_booking_paid trigger which
  // fires automatically when payment_status is updated to 'paid'
  // ============================================================
  public function verify(): void
  {
    $reference = trim($this->request->input('reference', ''));
    $userId    = $this->request->user['id'];

    if (empty($reference)) {
      Response::validationError(['reference' => 'Payment reference is required.']);
    }

    // 1. Find the booking by reference
    $stmt = $this->db->prepare("
            SELECT
                b.*,
                tt.name      AS ticket_type_name,
                tt.id        AS ticket_type_id,
                tt.quantity  AS ticket_type_quantity,
                tt.quantity_sold AS ticket_type_quantity_sold,
                e.title      AS event_title,
                e.location   AS event_location,
                e.start_date AS event_start_date,
                e.organizer_id,
                u.name       AS user_name,
                u.email      AS user_email
            FROM bookings b
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN events e        ON e.id  = b.event_id
            JOIN users u         ON u.id  = b.user_id
            WHERE b.paystack_reference = ?
        ");
    $stmt->execute([$reference]);
    $booking = $stmt->fetch();

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    // 2. Make sure this booking belongs to the requesting user
    if ((int) $booking['user_id'] !== $userId) {
      Response::forbidden('This booking does not belong to you.');
    }

    // 3. If already paid, return success without reprocessing
    //    (handles page refresh on success screen)
    if ($booking['payment_status'] === Constants::PAYMENT_PAID) {
      Response::success([
        'booking_id'     => (int) $booking['id'],
        'booking_number' => $booking['booking_number'],
        'event'          => $booking['event_title'],
        'tickets_issued' => (int) $booking['quantity'],
      ], 'Booking already confirmed.');
      return;
    }

    // 4. Reject if booking is in a terminal failed/cancelled state
    if (in_array($booking['payment_status'], ['failed', 'cancelled', 'refunded'])) {
      Response::error('This booking can no longer be processed.', 400);
      return;
    }

    // 5. Verify with Paystack
    try {
      $paystack    = new PaystackService();
      $transaction = $paystack->verifyTransaction($reference);

      // 6. Check Paystack confirms payment was successful
      if ($transaction['status'] !== 'success') {
        $this->db->prepare("
                    UPDATE bookings SET payment_status = 'failed' WHERE id = ?
                ")->execute([$booking['id']]);

        // ── NEW ── log failed transaction and notify user
        TransactionService::paymentFailed($booking, 'Paystack returned: ' . $transaction['status']);
        NotificationService::bookingFailed(
          (int)$booking['user_id'],
          (int)$booking['id'],
          (int)$booking['event_id'],
          $booking['event_title']
        );
        // ── END NEW ──

        Response::error('Payment was not successful. Please try again.', 400);
        return;
      }

      // 7. Double-check amount paid matches what we expected
      //    Prevents paying ₦1 for a ₦10,000 ticket
      $amountPaidKobo = (int) $transaction['amount'];
      $expectedKobo   = (int) ($booking['total_amount'] * 100);

      if ($amountPaidKobo < $expectedKobo) {
        error_log("Amount mismatch on reference {$reference}: paid {$amountPaidKobo}, expected {$expectedKobo}");
        Response::error('Payment amount does not match. Please contact support.', 400);
        return;
      }

      // 8. Mark booking as paid inside a transaction
      //    trg_booking_paid trigger fires here and increments quantity_sold
      $this->db->beginTransaction();

      try {
        $updateStmt = $this->db->prepare("
                    UPDATE bookings
                    SET payment_status = 'paid', paid_at = NOW()
                    WHERE id = ? AND payment_status = 'pending'
                ");
        $updateStmt->execute([$booking['id']]);

        // If no rows were updated, another request already processed this
        if ($updateStmt->rowCount() === 0) {
          $this->db->rollBack();
          Response::success([
            'booking_id'     => (int) $booking['id'],
            'booking_number' => $booking['booking_number'],
            'event'          => $booking['event_title'],
            'tickets_issued' => (int) $booking['quantity'],
          ], 'Booking already confirmed.');
          return;
        }

        // ── NEW: fee calculation + audit + notifications + payout accumulation ──
        $feePercentage      = PayoutService::getFeePercentage((int)$booking['event_id'], (int)$booking['organizer_id']);
        $split           = PayoutService::calculateSplit((float)$booking['total_amount'], $feePercentage);


        TransactionService::paymentConfirmed(
          $booking,
          $split['platform_fee'],
          $split['organizer_amount'],
          $transaction['status']
        );

        PayoutService::accumulateRevenue(
          (int)   $booking['event_id'],
          (int)   $booking['organizer_id'],
          (float) $booking['total_amount'],
          (float) $feePercentage
        );

        NotificationService::bookingConfirmed(
          (int)   $booking['user_id'],
          (int)   $booking['id'],
          $booking['event_title'],
          (int)   $booking['event_id'],
          (int)   $booking['quantity'],
          (float) $booking['total_amount']
        );

        NotificationService::newBookingReceived(
          (int)   $booking['organizer_id'],
          (int)   $booking['id'],
          $booking['user_name'],
          $booking['event_title'],
          (int)   $booking['event_id'],
          (int)   $booking['quantity'],
          (float) $booking['total_amount']
        );

        // Low tickets warning — fires if ≤10% tickets remain
        $salesStmt = $this->db->prepare("
            SELECT quantity, quantity_sold
            FROM ticket_types
            WHERE id = ?
        ");
        $salesStmt->execute([$booking['ticket_type_id']]);
        $sales = $salesStmt->fetch();
        if ($sales) {
          $remaining = (int) $sales['quantity'] - (int) $sales['quantity_sold'];
          $pct = $sales['quantity'] > 0 ? ($remaining / $sales['quantity']) : 1;
          if ($pct <= 0.10 && $remaining > 0) {
            NotificationService::lowTicketsWarning(
              (int) $booking['organizer_id'],
              (int) $booking['event_id'],
              $booking['event_title'],
              $remaining,
              (int) $sales['quantity']
            );
          }
        }
        // ── END NEW ──

        // 9. Issue one ticket row per quantity purchased.
        // This is the ONLY place tickets are created for paid bookings,
        // so ticket_number generation belongs here — one per real ticket id.
        $tickets = [];
        $stmt    = $this->db->prepare("
                    INSERT INTO tickets (booking_id, user_id, event_id, qr_token)
                    VALUES (?, ?, ?, ?)
                ");
        for ($i = 0; $i < (int) $booking['quantity']; $i++) {
          $qrToken = TokenHelper::generateQRToken();
          $stmt->execute([
            $booking['id'],
            $booking['user_id'],
            $booking['event_id'],
            $qrToken,
          ]);

          $ticketId     = (int) $this->db->lastInsertId();
          $ticketNumber = TokenHelper::generateDisplayId($ticketId, 'TK');
          $this->db->prepare("UPDATE tickets SET ticket_number = ? WHERE id = ?")
            ->execute([$ticketNumber, $ticketId]);

          $tickets[] = [
            'id'            => $ticketId,
            'ticket_number' => $ticketNumber,
            'qr_token'      => $qrToken,
          ];
        }

        $this->db->commit();
      } catch (Exception $e) {
        if ($this->db->inTransaction()) {
          $this->db->rollBack();
        }
        error_log('Booking verify transaction error: ' . $e->getMessage());
        Response::error('Could not confirm booking. Please contact support.', 500);
        return;
      }

      // 10. Queue ticket PDF generation (runs after email to avoid Chromium contention)
      // Delayed by 10s to let the email job start first and
      // avoid two Chromium instances competing for resources.
      QueueService::generateTicket((int) $booking['id'], 10);

      // 11. Send confirmation email outside the transaction
      QueueService::sendTicketConfirmation(
        $booking['user_email'],
        $booking['user_name'],
        $booking['event_title'],
        $booking['event_start_date'],
        $booking['event_location'],
        $booking['ticket_type_name'],
        (int) $booking['quantity'],
        (float) $booking['total_amount'],
        Environment::get('FRONTEND_URL') . '/my-bookings',
        $booking['paystack_reference']
      );

      Response::success([
        'booking_id'     => (int) $booking['id'],
        'booking_number' => $booking['booking_number'],
        'event'          => $booking['event_title'],
        'tickets_issued' => count($tickets),
      ], 'Payment confirmed! Your tickets have been issued.');
    } catch (Exception $e) {
      error_log('Paystack verify error: ' . $e->getMessage());
      Response::error('Could not verify payment. Please contact support.', 500);
    }
  }

  // ============================================================
  // GET /api/bookings/mine
  // ============================================================
  public function myBookings(): void
  {
    $userId = $this->request->user['id'];

    $stmt = $this->db->prepare("
            SELECT
                b.id,
                b.booking_number,
                b.quantity,
                b.unit_price,
                b.total_amount,
                b.payment_status,
                b.paid_at,
                b.refunded_at,
                b.created_at,
                e.id         AS event_id,
                e.title      AS event_title,
                e.location   AS event_location,
                e.start_date AS event_start_date,
                e.banner_image,
                tt.name      AS ticket_type
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE b.user_id    = ?
              AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
        ");
    $stmt->execute([$userId]);

    Response::success(['bookings' => $stmt->fetchAll()]);
  }

  // ============================================================
  // GET /api/bookings/:id
  // ============================================================
  public function show(array $params): void
  {
    $bookingId = (int) $params['id'];
    $userId    = $this->request->user['id'];
    $role      = $this->request->user['role'];

    $stmt = $this->db->prepare("
            SELECT
                b.*,
              b.booking_number,
                e.title        AS event_title,
                e.location     AS event_location,
                e.start_date   AS event_start_date,
                e.organizer_id,
                tt.name        AS ticket_type,
                u.name         AS attendee_name,
                u.email        AS attendee_email
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            JOIN users        u  ON u.id  = b.user_id
            WHERE b.id         = ?
              AND b.deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    // Only the attendee, the event organizer, or dev can view this
    $isOwner     = (int) $booking['user_id']      === $userId;
    $isOrganizer = (int) $booking['organizer_id'] === $userId;
    $isDev       = $role === Constants::ROLE_DEV;

    if (!$isOwner && !$isOrganizer && !$isDev) {
      Response::forbidden('You do not have access to this booking.');
    }

    // Fetch tickets for this booking
    $stmt = $this->db->prepare("
            SELECT id, ticket_number, qr_token, is_used, used_at, created_at
            FROM tickets
            WHERE booking_id = ? AND deleted_at IS NULL
        ");
    $stmt->execute([$bookingId]);
    $booking['tickets'] = $stmt->fetchAll();

    Response::success(['booking' => $booking]);
  }

  // ============================================================
  // GET /api/organizer/events/:id/bookings
  // ============================================================
  public function eventBookings(array $params): void
  {
    $eventId = (int) $params['id'];
    $userId  = $this->request->user['id'];
    $role    = $this->request->user['role'];

    // Confirm the event exists
    $stmt = $this->db->prepare('SELECT organizer_id FROM events WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
      Response::notFound('Event not found.');
    }

    // Organizers can only view bookings for their own events
    if ($role === Constants::ROLE_ORGANIZER && (int) $event['organizer_id'] !== $userId) {
      Response::forbidden('You can only view bookings for your own events.');
    }

    $stmt = $this->db->prepare("
            SELECT
                b.id,
                b.booking_number,
                b.quantity,
                b.unit_price,
                b.total_amount,
                b.payment_status,
                b.paid_at,
                b.refunded_at,
                b.created_at,
                u.name  AS attendee_name,
                u.email AS attendee_email,
                tt.name AS ticket_type
            FROM bookings b
            JOIN users        u  ON u.id  = b.user_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE b.event_id       = ?
              AND b.payment_status = 'paid'
              AND b.deleted_at     IS NULL
            ORDER BY b.paid_at DESC
        ");
    $stmt->execute([$eventId]);

    Response::success(['bookings' => $stmt->fetchAll()]);
  }
}
