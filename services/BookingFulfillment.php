<?php
class BookingFulfillment
{
  /** Returns ['status' => 'paid'|'already_paid'|'not_paid', 'booking' => array|null] */
  public static function run(string $reference, ?int $bookingId = null): array
  {
    $db = Database::connect();

    // Find by reference first; fall back to metadata booking_id
    // (covers references overwritten by resume()).
    $sql = "
      SELECT b.*, tt.name AS ticket_type_name, tt.id AS ticket_type_id,
             e.title AS event_title, e.location AS event_location,
             e.start_date AS event_start_date, e.organizer_id,
             u.name AS user_name, u.email AS user_email
      FROM bookings b
      JOIN ticket_types tt ON tt.id = b.ticket_type_id
      JOIN events e ON e.id = b.event_id
      JOIN users u ON u.id = b.user_id
      WHERE %s";

    $stmt = $db->prepare(sprintf($sql, 'b.paystack_reference = ?'));
    $stmt->execute([$reference]);
    $booking = $stmt->fetch();

    if (!$booking && $bookingId) {
      $stmt = $db->prepare(sprintf($sql, 'b.id = ?'));
      $stmt->execute([$bookingId]);
      $booking = $stmt->fetch();
    }
    if (!$booking) return ['status' => 'not_paid', 'booking' => null];

    if ($booking['payment_status'] === Constants::PAYMENT_PAID) {
      return ['status' => 'already_paid', 'booking' => $booking];
    }
    if ($booking['payment_status'] === 'refunded') {
      return ['status' => 'not_paid', 'booking' => $booking];
    }

    // Always confirm with Paystack, never trust the caller.
    $tx = (new PaystackService())->verifyTransaction($reference);
    if (($tx['status'] ?? '') !== 'success') {
      return [
        'status' => 'not_paid',
        'booking' => $booking,
        'paystack_status' => $tx['status'] ?? null
      ];
    }
    if ((int) $tx['amount'] < (int) round($booking['total_amount'] * 100)) {
      error_log("Amount mismatch on {$reference}");
      return ['status' => 'not_paid', 'booking' => $booking];
    }

    $db->beginTransaction();
    try {
      // pending OR failed -> paid (rescues bookings wrongly marked failed)
      $upd = $db->prepare("
        UPDATE bookings
        SET payment_status = 'paid', paid_at = NOW(), paystack_reference = ?
        WHERE id = ? AND payment_status IN ('pending','failed')");
      $upd->execute([$reference, $booking['id']]);

      if ($upd->rowCount() === 0) { // someone else won the race
        $db->rollBack();
        return ['status' => 'already_paid', 'booking' => $booking];
      }

      $booking['paystack_reference'] = $reference;

      $fee   = PayoutService::getFeePercentage(
        (int) $booking['event_id'],
        (int) $booking['organizer_id']
      );

      $split = PayoutService::calculateSplit(
        (float) $booking['total_amount'],
        $fee
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
        (float) $fee
      );

      // Issue tickets only if none exist yet (idempotent)
      $c = $db->prepare("SELECT COUNT(*) FROM tickets WHERE booking_id = ? AND deleted_at IS NULL");
      $c->execute([$booking['id']]);
      $existing = (int) $c->fetchColumn();

      $ins = $db->prepare("INSERT INTO tickets (booking_id, user_id, event_id, qr_token) VALUES (?,?,?,?)");
      for ($i = $existing; $i < (int) $booking['quantity']; $i++) {
        $ins->execute(
          [
            $booking['id'],
            $booking['user_id'],
            $booking['event_id'],
            TokenHelper::generateQRToken()
          ]
        );
        $tid = (int) $db->lastInsertId();
        $db->prepare("UPDATE tickets SET ticket_number = ? WHERE id = ?")
          ->execute([TokenHelper::generateDisplayId($tid, 'TK'), $tid]);
      }

      $db->commit();
    } catch (\Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      throw $e; // webhook returns 500 so Paystack retries
    }

    // Side effects after commit
    EventCache::forgetDetail(
      (int) $booking['event_id']
    );

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

    QueueService::generateTicket(
      (int) $booking['id'],
      10
    );

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

    return ['status' => 'paid', 'booking' => $booking];
  }
}
