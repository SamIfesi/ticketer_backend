<?php

class TransactionService
{
  private static ?PDO $db = null;

  private static function db(): PDO
  {
    if (self::$db === null) {
      self::$db = Database::connect();
    }
    return self::$db;
  }

  // Core append — NEVER update or delete from transaction_logs
  public static function log(
    int    $bookingId,
    int    $userId,
    int    $eventId,
    int    $organizerId,
    string $type,
    float  $amount,
    array  $meta = []
  ): bool {
    try {
      self::db()->prepare("
                INSERT INTO transaction_logs (
                    booking_id, user_id, event_id, organizer_id,
                    type, amount, currency,
                    paystack_reference, paystack_status,
                    quantity, unit_price,
                    platform_fee, organizer_amount,
                    ticket_type_name, event_title,
                    note, ip_address, performed_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
        $bookingId,
        $userId,
        $eventId,
        $organizerId,
        $type,
        $amount,
        $meta['currency']           ?? 'NGN',
        $meta['paystack_reference'] ?? null,
        $meta['paystack_status']    ?? null,
        $meta['quantity']           ?? 1,
        $meta['unit_price']         ?? 0.00,
        $meta['platform_fee']       ?? 0.00,
        $meta['organizer_amount']   ?? 0.00,
        $meta['ticket_type_name']   ?? null,
        $meta['event_title']        ?? null,
        $meta['note']               ?? null,
        $_SERVER['REMOTE_ADDR']     ?? null,
        $meta['performed_by']       ?? null,
      ]);
      return true;
    } catch (Exception $e) {
      error_log('TransactionService::log error: ' . $e->getMessage());
      return false;
    }
  }

  // Payment initiated — booking created, awaiting payment
  // amount = 0 (no money moved yet)
  public static function paymentInitiated(array $booking, int $organizerId): void
  {
    self::log(
      (int)   $booking['id'],
      (int)   $booking['user_id'],
      (int)   $booking['event_id'],
      $organizerId,
      'payment_initiated',
      0.00,
      [
        'paystack_reference' => $booking['paystack_reference'],
        'quantity'           => (int)   $booking['quantity'],
        'unit_price'         => (float) $booking['unit_price'],
        'ticket_type_name'   => $booking['ticket_type_name'] ?? null,
        'event_title'        => $booking['event_title']      ?? null,
        'note'               => 'Paystack transaction initialized. Awaiting payment.',
      ]
    );
  }

  // Payment confirmed — money received, tickets issued
  // Includes calculated platform fee and organizer cut
  public static function paymentConfirmed(
    array  $booking,
    float  $platformFee,
    float  $organizerAmount,
    string $paystackStatus = 'success'
  ): void {
    self::log(
      (int)   $booking['id'],
      (int)   $booking['user_id'],
      (int)   $booking['event_id'],
      (int)   $booking['organizer_id'],
      'payment_confirmed',
      (float) $booking['total_amount'],
      [
        'paystack_reference' => $booking['paystack_reference'],
        'paystack_status'    => $paystackStatus,
        'quantity'           => (int)   $booking['quantity'],
        'unit_price'         => (float) $booking['unit_price'],
        'platform_fee'       => $platformFee,
        'organizer_amount'   => $organizerAmount,
        'ticket_type_name'   => $booking['ticket_type_name'] ?? null,
        'event_title'        => $booking['event_title']      ?? null,
        'note'               => "Payment confirmed. Platform fee: ₦" . number_format($platformFee, 2) . ". Organizer receives: ₦" . number_format($organizerAmount, 2) . " after event.",
      ]
    );
  }

  // Payment failed
  public static function paymentFailed(array $booking, string $reason = ''): void
  {
    self::log(
      (int)   $booking['id'],
      (int)   $booking['user_id'],
      (int)   $booking['event_id'],
      (int)   $booking['organizer_id'],
      'payment_failed',
      0.00,
      [
        'paystack_reference' => $booking['paystack_reference'],
        'paystack_status'    => 'failed',
        'quantity'           => (int)   $booking['quantity'],
        'unit_price'         => (float) $booking['unit_price'],
        'ticket_type_name'   => $booking['ticket_type_name'] ?? null,
        'event_title'        => $booking['event_title']      ?? null,
        'note'               => $reason ?: 'Payment verification failed.',
      ]
    );
  }

  // Refund processed — amount is negative (money going back)
  public static function refundProcessed(
    array  $booking,
    int    $performedBy,
    string $note = ''
  ): void {
    self::log(
      (int)   $booking['id'],
      (int)   $booking['user_id'],
      (int)   $booking['event_id'],
      (int)   $booking['organizer_id'],
      'refund_processed',
      -abs((float) $booking['total_amount']),
      [
        'paystack_reference' => $booking['paystack_reference'],
        'quantity'           => (int)   $booking['quantity'],
        'unit_price'         => (float) $booking['unit_price'],
        'ticket_type_name'   => $booking['ticket_type_name'] ?? null,
        'event_title'        => $booking['event_title']      ?? null,
        'note'               => $note ?: 'Booking refunded. Platform absorbs Paystack charges.',
        'performed_by'       => $performedBy,
      ]
    );
  }

  // Payout sent — organizer paid after event
  public static function payoutSent(
    int    $eventId,
    int    $organizerId,
    float  $amount,
    string $transferCode,
    string $eventTitle,
    int    $performedBy,
    bool   $isAuto = true
  ): void {
    // For payout logs we use event_id as booking_id placeholder (0)
    // since payouts are per-event not per-booking
    self::log(
      0,
      $organizerId,
      $eventId,
      $organizerId,
      'payout_sent',
      $amount,
      [
        'paystack_reference' => $transferCode,
        'paystack_status'    => 'success',
        'event_title'        => $eventTitle,
        'note'               => $isAuto
          ? "Automatic payout triggered by worker after hold period."
          : "Manual payout triggered by admin (user #{$performedBy}).",
        'performed_by'       => $performedBy,
      ]
    );
  }

  // Payout failed
  public static function payoutFailed(
    int    $eventId,
    int    $organizerId,
    string $eventTitle,
    string $reason
  ): void {
    self::log(
      0,
      $organizerId,
      $eventId,
      $organizerId,
      'payout_failed',
      0.00,
      [
        'event_title' => $eventTitle,
        'note'        => 'Payout failed: ' . $reason,
      ]
    );
  }

  // Force pay (dev tool)
  public static function forcedPayment(array $booking, int $devUserId): void
  {
    self::log(
      (int)   $booking['id'],
      (int)   $booking['user_id'],
      (int)   $booking['event_id'],
      (int)   $booking['organizer_id'],
      'force_payment',
      (float) $booking['total_amount'],
      [
        'paystack_reference' => $booking['paystack_reference'],
        'quantity'           => (int)   $booking['quantity'],
        'unit_price'         => (float) $booking['unit_price'],
        'ticket_type_name'   => $booking['ticket_type_name'] ?? null,
        'event_title'        => $booking['event_title']      ?? null,
        'note'               => 'Payment manually forced by dev. No real Paystack transaction.',
        'performed_by'       => $devUserId,
      ]
    );
  }
}
