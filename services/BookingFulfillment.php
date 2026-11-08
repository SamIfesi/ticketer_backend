<?php

/**
 * BookingFulfillment
 *
 * Single, idempotent place where a Paystack payment becomes a paid booking
 * with tickets. Called by:
 *   - BookingController::verify()   (browser callback)
 *   - WebhookController::paystack() (charge.success from Paystack)
 *   - email_worker.php              (reconciliation of stale pending bookings)
 *
 * Safe to call any number of times for the same booking.
 *
 * Returns:
 *   ['status' => 'paid' | 'already_paid' | 'not_paid',
 *    'booking' => array|null,
 *    'paystack_status' => string|null]
 */
class BookingFulfillment
{
  public static function run(string $reference, ?int $bookingId = null): array
  {
    $db = Database::connect();

    $baseSql = "
      SELECT b.*,
             tt.name  AS ticket_type_name,
             e.title  AS event_title,
             e.location   AS event_location,
             e.start_date AS event_start_date,
             e.organizer_id,
             u.name  AS user_name,
             u.email AS user_email
      FROM bookings b
      JOIN ticket_types tt ON tt.id = b.ticket_type_id
      JOIN events e        ON e.id  = b.event_id
      JOIN users u         ON u.id  = b.user_id
      WHERE %s
    ";

    // 1. Find by reference; fall back to booking id (covers a reference that
    //    was overwritten by resume() after the user already paid on the old one).
    $stmt = $db->prepare(sprintf($baseSql, 'b.paystack_reference = ?'));
    $stmt->execute([$reference]);
    $booking = $stmt->fetch();

    if (!$booking && $bookingId) {
      $stmt = $db->prepare(sprintf($baseSql, 'b.id = ?'));
      $stmt->execute([$bookingId]);
      $booking = $stmt->fetch();
    }

    if (!$booking) {
      return [
        'status' => 'not_paid',
        'booking' => null,
        'paystack_status' => null
      ];
    }

    if ($booking['payment_status'] === Constants::PAYMENT_PAID) {
      return [
        'status' => 'already_paid',
        'booking' => $booking,
        'paystack_status' => 'success'
      ];
    }

    if ($booking['payment_status'] === Constants::PAYMENT_REFUNDED) {
      return [
        'status' => 'not_paid',
        'booking' => $booking,
        'paystack_status' => 'refunded'
      ];
    }

    // 2. Always confirm with Paystack — never trust the caller.
    $tx             = (new PaystackService())->verifyTransaction($reference);
    $paystackStatus = $tx['status'] ?? null;

    if ($paystackStatus !== 'success') {
      // abandoned / pending / failed: leave the booking alone, the caller decides.
      return [
        'status' => 'not_paid',
        'booking' => $booking,
        'paystack_status' => $paystackStatus
      ];
    }

    // 3. Amount check (prevents paying ₦1 for a ₦10,000 ticket)
    $paidKobo     = (int) $tx['amount'];
    $expectedKobo = (int) round(((float) $booking['total_amount']) * 100);

    if ($paidKobo < $expectedKobo) {
      error_log("
      BookingFulfillment: amount mismatch on {$reference}: paid {$paidKobo},
      expected {$expectedKobo}");
      return [
        'status' => 'not_paid',
        'booking' => $booking,
        'paystack_status' => 'amount_mismatch'
      ];
    }

    // 4. Flip to paid + issue tickets atomically.
    $db->beginTransaction();

    try {
      // pending OR failed -> paid. Rescues bookings that were wrongly marked
      // failed while the money had actually been received.
      $upd = $db->prepare("
        UPDATE bookings
        SET payment_status = 'paid', paid_at = NOW(), paystack_reference = ?
        WHERE id = ? AND payment_status IN ('pending', 'failed')
      ");
      $upd->execute([$reference, $booking['id']]);

      if ($upd->rowCount() === 0) {
        // Another request (webhook vs browser) won the race.
        $db->rollBack();
        return [
          'status' => 'already_paid',
          'booking' => $booking,
          'paystack_status' => 'success'
        ];
      }

      $booking['paystack_reference'] = $reference;
      $wasFailed = $booking['payment_status'] === Constants::PAYMENT_FAILED;

      $feePercentage = PayoutService::getFeePercentage(
        (int) $booking['event_id'],
        (int) $booking['organizer_id']
      );

      $split         = PayoutService::calculateSplit(
        (float) $booking['total_amount'],
        $feePercentage
      );

      TransactionService::paymentConfirmed(
        $booking,
        $split['platform_fee'],
        $split['organizer_amount'],
        'success'
      );

      PayoutService::accumulateRevenue(
        (int) $booking['event_id'],
        (int) $booking['organizer_id'],
        (float) $booking['total_amount'],
        (float) $feePercentage
      );

      // Idempotent ticket issue: only create the ones that don't exist yet.
      $c = $db->prepare("SELECT COUNT(*) FROM tickets WHERE booking_id = ? AND deleted_at IS NULL");
      $c->execute([$booking['id']]);
      $existing = (int) $c->fetchColumn();

      $ins = $db->prepare("
        INSERT INTO tickets (booking_id, user_id, event_id, qr_token)
        VALUES (?, ?, ?, ?)
      ");
      $setNumber = $db->prepare("UPDATE tickets SET ticket_number = ? WHERE id = ?");

      $issued = $existing;
      for ($i = $existing; $i < (int) $booking['quantity']; $i++) {
        $ins->execute([
          $booking['id'],
          $booking['user_id'],
          $booking['event_id'],
          TokenHelper::generateQRToken(),
        ]);
        $ticketId = (int) $db->lastInsertId();
        $setNumber->execute([TokenHelper::generateDisplayId($ticketId, 'TK'), $ticketId]);
        $issued++;
      }

      $db->commit();
    } catch (\Throwable $e) {
      if ($db->inTransaction()) {
        $db->rollBack();
      }
      error_log("BookingFulfillment: transaction failed for {$reference}: " . $e->getMessage());
      throw $e; // webhook answers 500 so Paystack retries; verify() shows a safe message
    }

    // 5. Side effects AFTER commit — a failure here must never undo a paid booking.
    try {
      if ($wasFailed) {
        error_log("BookingFulfillment: RESCUED booking #{$booking['id']} ({$reference}) that was marked failed.");
      }

      EventCache::forgetDetail((int) $booking['event_id']);

      NotificationService::bookingConfirmed(
        (int) $booking['user_id'],
        (int) $booking['id'],
        $booking['event_title'],
        (int) $booking['event_id'],
        (int) $booking['quantity'],
        (float) $booking['total_amount']
      );

      NotificationService::newBookingReceived(
        (int) $booking['organizer_id'],
        (int) $booking['id'],
        $booking['user_name'],
        $booking['event_title'],
        (int) $booking['event_id'],
        (int) $booking['quantity'],
        (float) $booking['total_amount']
      );

      // Low-ticket warning (<= 10% left)
      $s = $db->prepare("SELECT quantity, quantity_sold, quantity_held FROM ticket_types WHERE id = ?");
      $s->execute([$booking['ticket_type_id']]);
      $sales = $s->fetch();
      // Held tickets aren't for sale, so measure scarcity against what buyers can actually see
      $effectiveQty = $sales ? (int) $sales['quantity'] - (int) $sales['quantity_held'] : 0;
      if ($sales && $effectiveQty > 0) {
        $remaining = $effectiveQty - (int) $sales['quantity_sold'];
        if ($remaining > 0 && ($remaining / $effectiveQty) <= 0.10) {
          NotificationService::lowTicketsWarning(
            (int) $booking['organizer_id'],
            (int) $booking['event_id'],
            $booking['event_title'],
            $remaining,
            $effectiveQty
          );
        }
      }

      QueueService::generateTicket((int) $booking['id'], 10);

      QueueService::sendTicketConfirmation(
        $booking['user_email'],
        $booking['user_name'],
        $booking['event_title'],
        $booking['event_start_date'],
        $booking['event_location'] ?? '',
        $booking['ticket_type_name'],
        (int) $booking['quantity'],
        (float) $booking['total_amount'],
        Environment::get('FRONTEND_URL') . '/my-bookings',
        $reference
      );
    } catch (\Throwable $e) {
      error_log("BookingFulfillment: post-commit side effect failed for {$reference}: " . $e->getMessage());
    }

    $booking['tickets_issued'] = $issued;

    return ['status' => 'paid', 'booking' => $booking, 'paystack_status' => 'success'];
  }
}
