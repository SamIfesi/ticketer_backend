<?php

class QueueService
{
  private static ?PDO $db = null;

  private static function db(): PDO
  {
    if (self::$db === null) {
      self::$db = Database::connect();
    }
    return self::$db;
  }

  // ============================================================
  // Core push — all helpers call this.
  //
  // $queue: 'email' | 'pdf'
  //   'email' → picked up by email_worker.php (every minute)
  //   'pdf'   → picked up by pdf_worker.php   (every 5 minutes)
  //
  // Usage:
  //   QueueService::push('send_otp', [
  //       'email' => 'user@example.com',
  //       'name'  => 'Sam',
  //       'otp'   => '123456',
  //       'type'  => 'register',
  //   ]);
  // ============================================================
  public static function push(
    string $type,
    array  $payload,
    int    $delaySeconds = 0,
    string $queue        = 'email'
  ): bool {
    try {
      self::db()->prepare("
        INSERT INTO jobs (type, payload, queue, status, available_at)
        VALUES (?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND))
      ")->execute([
        $type,
        json_encode($payload),
        $queue,
        $delaySeconds,
      ]);

      return true;
    } catch (Exception $e) {
      error_log('QueueService::push error: ' . $e->getMessage());
      return false;
    }
  }

  // ============================================================
  // EMAIL JOBS  →  queue: 'email'
  // ============================================================

  public static function sendOTP(string $email, string $name, string $otp, string $type): void
  {
    self::push('send_otp', [
      'email' => $email,
      'name'  => $name,
      'otp'   => $otp,
      'type'  => $type,
    ], 0, 'email');
  }

  public static function sendWelcome(string $email, string $name): void
  {
    self::push('send_welcome', [
      'email' => $email,
      'name'  => $name,
    ]);
  }

  public static function sendForgotPasswordOTP(string $email, string $name, string $otp): void
  {
    self::push('send_forgot_password_otp', [
      'email' => $email,
      'name'  => $name,
      'otp'  => $otp,
    ], 0, 'email');
  }

  public static function sendTicketConfirmation(
    string $email,
    string $name,
    string $eventTitle,
    string $eventDate,
    string $eventLocation,
    string $ticketType,
    int    $quantity,
    float  $totalAmount,
    string $pageUrl,
    string $bookingReference
  ): void {
    self::push('send_ticket_confirmation', [
      'email'          => $email,
      'name'           => $name,
      'event_title'    => $eventTitle,
      'event_date'     => $eventDate,
      'event_location' => $eventLocation,
      'ticket_type'    => $ticketType,
      'quantity'       => $quantity,
      'total_amount'   => $totalAmount,
      'page_url'       => $pageUrl,
      'booking_reference' => $bookingReference,
    ], 0, 'email');
  }

  public static function sendEventReminder(
    string $email,
    string $name,
    string $eventTitle,
    string $eventDate,
    string $eventLocation,
    string $pageUrl
  ): void {
    self::push('send_event_reminder', [
      'email'          => $email,
      'name'           => $name,
      'event_title'    => $eventTitle,
      'event_date'     => $eventDate,
      'event_location' => $eventLocation,
      'page_url'       => $pageUrl,
    ], 0, 'email');
  }

  public static function sendPasswordChanged(string $email, string $name): void
  {
    self::push('send_password_changed', [
      'email' => $email,
      'name'  => $name,
    ], 0, 'email');
  }

  // ============================================================
  // PDF JOBS  →  queue: 'pdf'
  // ============================================================

  /**
   * Queue a ticket PDF generation for a single paid booking.
   *
   * Delayed by $delaySeconds to let the booking transaction
   * fully commit before Browsershot tries to read it.
   *
   * @param int $bookingId     The confirmed paid booking ID
   * @param int $delaySeconds  Seconds to wait before processing (default 10)
   */
  public static function generateTicket(int $bookingId, int $delaySeconds = 10): void
  {
    self::push('generate_ticket', [
      'booking_id' => $bookingId,
    ], $delaySeconds, 'pdf');
  }

  /**
   * Queue bulk ticket PDF generation for multiple bookings.
   * Useful for admin re-generation or backfill tasks.
   *
   * @param int[] $bookingIds
   */
  public static function generateTicketBulk(array $bookingIds): void
  {
    // Split into chunks of 10 so no single job runs forever
    $chunks = array_chunk($bookingIds, 10);

    foreach ($chunks as $index => $chunk) {
      // Stagger each chunk by 60s so worker runs don't overlap
      self::push('generate_tickets_bulk', [
        'booking_ids' => $chunk,
      ], $index * 60, 'pdf');
    }
  }
}
