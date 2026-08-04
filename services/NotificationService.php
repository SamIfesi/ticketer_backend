<?php

class NotificationService
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
  // Core push — all helpers call this
  // ============================================================
  public static function push(
    int     $userId,
    string  $type,
    string  $title,
    string  $body,
    ?string $actionUrl   = null,
    ?int    $relatedId   = null,
    ?string $relatedType = null
  ): bool {
    try {
      self::db()->prepare("
                INSERT INTO notifications
                    (user_id, type, title, body, action_url, related_id, related_type)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$userId, $type, $title, $body, $actionUrl, $relatedId, $relatedType]);
      return true;
    } catch (Exception $e) {
      error_log('NotificationService::push error: ' . $e->getMessage());
      return false;
    }
  }

  // ============================================================
  // ATTENDEE NOTIFICATIONS
  // ============================================================

  public static function bookingConfirmed(
    int    $userId,
    int    $bookingId,
    string $eventTitle,
    int    $eventId,
    int    $quantity,
    float  $totalAmount
  ): void {
    $formatted = '₦' . number_format($totalAmount, 2);
    self::push(
      $userId,
      'booking_confirmed',
      "Booking Confirmed — {$eventTitle}",
      "Your payment of {$formatted} was successful. {$quantity} ticket(s) issued for {$eventTitle}. Show your QR code at the gate.",
      "/bookings/{$bookingId}",
      $eventId,
      'booking'
    );
  }

  public static function bookingFailed(
    int    $userId,
    int    $bookingId,
    int    $eventId,
    string $eventTitle
  ): void {
    self::push(
      $userId,
      'booking_failed',
      "Payment Failed — {$eventTitle}",
      "Your payment for {$eventTitle} could not be completed. No money has been charged. Please try again.",
      "/events/{$eventId}",
      $bookingId,
      'booking'
    );
  }

  public static function ticketCheckedIn(
    int    $userId,
    int    $ticketId,
    string $eventTitle
  ): void {
    self::push(
      $userId,
      'ticket_checkin',
      "Checked In — {$eventTitle}",
      "Your ticket was scanned and you've been checked in to {$eventTitle}. Enjoy the event!",
      "/ticket/{$ticketId}",
      $ticketId,
      'ticket'
    );
  }

  public static function eventCancelled(
    int    $userId,
    int    $eventId,
    string $eventTitle
  ): void {
    self::push(
      $userId,
      'event_cancelled',
      "Event Cancelled — {$eventTitle}",
      "{$eventTitle} has been cancelled. Please contact support if you need a refund.",
      "/my-bookings",
      $eventId,
      'event'
    );
  }

  public static function eventUpdated(
    int    $userId,
    int    $eventId,
    string $eventTitle,
    string $whatChanged = 'details'
  ): void {
    self::push(
      $userId,
      'event_updated',
      "Event Updated — {$eventTitle}",
      "The organizer has updated the {$whatChanged} for {$eventTitle}. Please review your booking.",
      "/events/{$eventId}",
      $eventId,
      'event'
    );
  }

  public static function eventReminder(
    int    $userId,
    int    $eventId,
    string $eventTitle,
    string $startDate
  ): void {
    $formatted = date('D, d M Y \a\t g:ia', strtotime($startDate));
    self::push(
      $userId,
      'event_reminder',
      "Reminder — {$eventTitle}",
      "{$eventTitle} is happening on {$formatted}. Make sure your ticket QR code is ready!",
      "/my-tickets",
      $eventId,
      'event'
    );
  }

  public static function roleChanged(int $userId, string $newRole): void
  {
    $label = ucfirst($newRole);
    self::push(
      $userId,
      'role_changed',
      "Your role has been updated",
      "Our administrator has updated your account role to {$label}. Your new permissions are now active.",
      "/profile",
      $userId,
      'user'
    );
  }

  public static function accountDeactivated(int $userId): void
  {
    self::push(
      $userId,
      'account_deactivated',
      "Your account has been deactivated",
      "Your account has been deactivated by an administrator. Please contact support for assistance.",
      null,
      $userId,
      'user'
    );
  }

  // ============================================================
  // ORGANIZER NOTIFICATIONS
  // ============================================================

  public static function newBookingReceived(
    int    $organizerId,
    int    $bookingId,
    string $attendeeName,
    string $eventTitle,
    int    $eventId,
    int    $quantity,
    float  $totalAmount
  ): void {
    $formatted = '₦' . number_format($totalAmount, 2);
    self::push(
      $organizerId,
      'new_booking',
      "New Booking — {$eventTitle}",
      "{$attendeeName} just booked {$quantity} ticket(s) for {$eventTitle} ({$formatted}).",
      "/organizer/events/{$eventId}/bookings",
      $bookingId,
      'booking'
    );
  }

  public static function lowTicketsWarning(
    int    $organizerId,
    int    $eventId,
    string $eventTitle,
    int    $remaining,
    int    $total
  ): void {
    $pct = round(($remaining / $total) * 100);
    self::push(
      $organizerId,
      'low_tickets',
      "Low Tickets — {$eventTitle}",
      "Only {$remaining} ticket(s) remaining ({$pct}%) for {$eventTitle}. Consider promoting the event.",
      "/organizer/events/{$eventId}",
      $eventId,
      'event'
    );
  }

  public static function payoutSent(
    int    $organizerId,
    int    $eventId,
    string $eventTitle,
    float  $amount
  ): void {
    $formatted = '₦' . number_format($amount, 2);
    self::push(
      $organizerId,
      'payout_sent',
      "Payout Sent — {$eventTitle}",
      "Your payout of {$formatted} for {$eventTitle} has been sent to your bank account. It should arrive within 1-2 business days.",
      "/organizer/payment-details",
      $eventId,
      'event'
    );
  }

  public static function payoutFailed(
    int    $organizerId,
    int    $eventId,
    string $eventTitle,
    string $reason = ''
  ): void {
    self::push(
      $organizerId,
      'payout_failed',
      "Payout Failed — {$eventTitle}",
      "We could not process your payout for {$eventTitle}. " . ($reason ?: 'Please ensure your bank details are correct.') . " Contact support if this persists.",
      "/organizer/payment-details",
      $eventId,
      'event'
    );
  }

  public static function payoutFrozen(
    int    $organizerId,
    int    $eventId,
    string $eventTitle,
    string $reason = ''
  ): void {
    self::push(
      $organizerId,
      'payout_frozen',
      "Payout Frozen — {$eventTitle}",
      "Your payout for {$eventTitle} has been temporarily frozen pending review. " . ($reason ?: 'Please contact support for more information.'),
      "/organizer/payment-details",
      $eventId,
      'event'
    );
  }

  public static function organizerFlagged(int $organizerId, int $strikes): void
  {
    self::push(
      $organizerId,
      'account_flagged',
      "Account Flagged — Action Required",
      "Your organizer account has been flagged after {$strikes} event cancellations. Your payouts are on hold pending admin review. Please contact support.",
      "/profile",
      $organizerId,
      'user'
    );
  }

  public static function bankDetailsRequired(int $organizerId): void
  {
    self::push(
      $organizerId,
      'bank_details_required',
      "Bank Details Required",
      "You need to add your bank account details before you can publish events. Go to Payment Settings to set this up.",
      "/organizer/payment-details",
      null,
      'user'
    );
  }

  // ============================================================
  // ADMIN NOTIFICATIONS
  // ============================================================

  public static function adminPayoutFailed(
    int    $adminId,
    int    $eventId,
    string $eventTitle,
    string $organizerName
  ): void {
    self::push(
      $adminId,
      'admin_payout_failed',
      "Payout Failed — Needs Attention",
      "Payout for \"{$eventTitle}\" (organizer: {$organizerName}) failed. Manual intervention required.",
      "/admin/payouts",
      $eventId,
      'event'
    );
  }

  public static function adminOrganizerFlagged(
    int    $adminId,
    int    $organizerId,
    string $organizerName,
    int    $strikes
  ): void {
    self::push(
      $adminId,
      'admin_organizer_flagged',
      "Organizer Flagged — {$organizerName}",
      "{$organizerName} has been automatically flagged after {$strikes} event cancellations. Their payouts are frozen. Please review.",
      "/admin/users/{$organizerId}",
      $organizerId,
      'user'
    );
  }

  public static function adminEventReminder(
    int    $adminId,
    int    $eventId,
    string $eventTitle,
    string $startDate,
    int    $attendeeCount
  ): void {
    $formatted = date('D, d M Y \a\t g:ia', strtotime($startDate));
    self::push(
      $adminId,
      'admin_event_reminder',
      "Event in 2 days — {$eventTitle}",
      "{$eventTitle} is happening on {$formatted}. {$attendeeCount} attendee(s) have paid tickets. Reminders have been sent.",
      "/admin/events",
      $eventId,
      'event'
    );
  }

  public static function newOrganizerApplication(
    int    $adminId,
    string $applicantName,
    string $orgName
  ): void {
    self::push(
      $adminId,
      'new_organizer_application',
      "New Organizer Application — {$orgName}",
      "{$applicantName} applied to become an organizer for \"{$orgName}\". Review in Organizer Applications.",
      "/admin/organizers/{$applicantName}",
      null,
      'application'
    );
  }
  
  // ============================================================
  // ORGANIZER APPLICATION NOTIFICATIONS
  // ============================================================
  
  public static function organizerApplicationSubmitted(
    int    $userId,
    string $orgName
  ): void {
    self::push(
      $userId,
      'organizer_application_submitted',
      "Organizer Application Submitted",
      "Your application to become an organizer for \"{$orgName}\" has been submitted. You will be notified once it is reviewed.",
      "/become-organizer",
      null,
      'application'
    );
  }

  public static function organizerApproved(int $userId, string $orgName): void
  {
    self::push(
      $userId,
      'organizer_approved',
      "Application Approved 🎉",
      "Congratulations! Your organizer application for \"{$orgName}\" has been approved. Add your bank details to start publishing events.",
      "/organizer/payment-details",
      null,
      'application'
    );
  }

  public static function organizerRejected(int $userId, string $orgName): void
  {
    self::push(
      $userId,
      'organizer_rejected',
      "Application Not Approved",
      "Your organizer application for \"{$orgName}\" was not approved at this time. You may apply again with updated information.",
      "/become-organizer",
      null,
      'application'
    );
  }

  // ============================================================
  // BULK — notify all paid attendees of an event
  // ============================================================
  public static function notifyEventAttendees(
    PDO     $db,
    int     $eventId,
    string  $type,
    string  $title,
    string  $body,
    ?string $actionUrl = null
  ): int {
    $stmt = $db->prepare("
            SELECT DISTINCT user_id FROM bookings
            WHERE event_id = ? AND payment_status = 'paid' AND deleted_at IS NULL
        ");
    $stmt->execute([$eventId]);
    $attendees = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($attendees as $userId) {
      if (self::push((int) $userId, $type, $title, $body, $actionUrl, $eventId, 'event')) {
        $count++;
      }
    }
    return $count;
  }
}
