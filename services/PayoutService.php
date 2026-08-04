<?php

/**
 * PayoutService
 *
 * Handles everything related to organizer payouts:
 *   - Calculating platform fee vs organizer amount
 *   - Creating event_payout rows
 *   - Triggering Paystack transfers
 *   - Strike system for cancellations
 *   - Freezing payouts
 */
class PayoutService
{
  private static ?PDO $db = null;

  private static function db(): PDO
  {
    if (self::$db === null) {
      self::$db = Database::connect();
    }
    return self::$db;
  }

  // Calculate the split for a given amount and fee percentage
  // Returns ['platform_fee' => X, 'organizer_amount' => Y]
  public static function calculateSplit(float $grossAmount, float $feePercentage): array
  {
    $platformFee     = round($grossAmount * ($feePercentage / 100), 2);
    $organizerAmount = round($grossAmount - $platformFee, 2);

    return [
      'platform_fee'     => $platformFee,
      'organizer_amount' => $organizerAmount,
    ];
  }

  // Get the effective fee percentage for a booking
  // Priority: event-level override → organizer default
  public static function getFeePercentage(int $eventId, int $organizerId): float
  {
    // Check if event has its own override
    $stmt = self::db()->prepare("SELECT platform_fee_percentage FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if ($event && $event['platform_fee_percentage'] !== null) {
      return (float) $event['platform_fee_percentage'];
    }

    // Fall back to organizer's default rate
    $stmt = self::db()->prepare("
            SELECT platform_fee_percentage FROM organizer_payment_details WHERE user_id = ?
        ");
    $stmt->execute([$organizerId]);
    $details = $stmt->fetch();

    if ($details) {
      return (float) $details['platform_fee_percentage'];
    }

    // Safety fallback — should never hit this if bank details are required
    return 10.00;
  }

  // Create or update the event_payouts row when a booking is paid
  // Called from BookingController::verify() after every confirmed payment
  // Accumulates gross_revenue across multiple bookings for same event
  public static function accumulateRevenue(
    int   $eventId,
    int   $organizerId,
    float $bookingAmount,
    float $feePercentage
  ): void {
    $split = self::calculateSplit($bookingAmount, $feePercentage);
    $stmt = self::db()->prepare("SELECT id FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    $eventEndDate = $event['end_date'] ?? date('Y-m-d H:i:s', strtotime('+1 day'));

    $holdHours = Constants::PAYOUT_HOLD_HOURS;
    $holdUntil = date(
      'Y-m-d H:i:s',
      strtotime(
        "+{$holdHours} hours",
        strtotime($eventEndDate)
      )
    );

    // Upsert — if row exists for this event, add to it
    self::db()->prepare("
            INSERT INTO event_payouts
                (event_id, organizer_id, gross_revenue, platform_fee_percentage,
                 platform_fee_amount, organizer_amount, payout_status, hold_until)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
            ON DUPLICATE KEY UPDATE
                gross_revenue        = gross_revenue + VALUES(gross_revenue),
                platform_fee_amount  = platform_fee_amount + VALUES(platform_fee_amount),
                organizer_amount     = organizer_amount + VALUES(organizer_amount),
                updated_at           = NOW()
        ")->execute([
      $eventId,
      $organizerId,
      $bookingAmount,
      $feePercentage,
      $split['platform_fee'],
      $split['organizer_amount'],
      $holdUntil,
    ]);
  }

  // Recalculate hold_until when event end_date is set/updated
  // Called from EventController when event is completed/ends
  public static function setHoldUntil(int $eventId, string $eventEndDate): void
  {
    // Use string interpolation properly for constants
    $holdHours = Constants::PAYOUT_HOLD_HOURS;
    $holdUntil = date(
      'Y-m-d H:i:s',
      strtotime("+{$holdHours} hours", strtotime($eventEndDate))
    );

    self::db()->prepare("
            UPDATE event_payouts SET hold_until = ? WHERE event_id = ?
        ")->execute([$holdUntil, $eventId]);
  }

  // Trigger a payout transfer to the organizer
  // Called by payout_worker.php (auto) or PayoutController (manual)
  // $triggeredBy = null for auto worker, user_id for manual
  public static function triggerPayout(int $eventId, ?int $triggeredBy = null): array
  {
    $db = self::db();

    // Fetch payout row
    $stmt = $db->prepare("SELECT * FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $payout = $stmt->fetch();

    if (!$payout) {
      return ['success' => false, 'message' => 'No payout record found for this event.'];
    }

    if ($payout['payout_status'] === Constants::PAYOUT_PAID) {
      return ['success' => false, 'message' => 'Payout already completed.'];
    }

    if ($payout['payout_status'] === Constants::PAYOUT_FROZEN) {
      return ['success' => false, 'message' => 'Payout is frozen. Unfreeze it first.'];
    }

    if ($payout['payout_status'] === Constants::PAYOUT_CANCELLED) {
      return ['success' => false, 'message' => 'Event was cancelled. No payout applicable.'];
    }

    // Fetch organizer payment details
    $stmt = $db->prepare("
            SELECT * FROM organizer_payment_details WHERE user_id = ? AND is_verified = 1
        ");
    $stmt->execute([$payout['organizer_id']]);
    $paymentDetails = $stmt->fetch();

    if (!$paymentDetails) {
      return ['success' => false, 'message' => 'Organizer has no verified bank details.'];
    }

    if ($paymentDetails['is_flagged']) {
      return ['success' => false, 'message' => 'Organizer account is flagged. Payout blocked.'];
    }

    if ((float) $payout['organizer_amount'] <= 0) {
      return ['success' => false, 'message' => 'Organizer amount is zero. Nothing to transfer.'];
    }

    // Fetch event title for logging
    $stmt = $db->prepare("SELECT title FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    $eventTitle = $event['title'] ?? "Event #{$eventId}";

    // Mark as processing
    $db->prepare("
            UPDATE event_payouts
            SET payout_status = 'processing', attempts = attempts + 1,
                triggered_by = ?, updated_at = NOW()
            WHERE event_id = ?
        ")->execute([$triggeredBy, $eventId]);

    try {
      $paystack  = new PaystackService();
      $reference = 'PAYOUT-' . $eventId . '-' . time();

      // Initiate the Paystack transfer
      $transfer = $paystack->initiateTransfer(
        (float) $payout['organizer_amount'],
        $paymentDetails['paystack_recipient_code'],
        $reference,
        "Event payout: {$eventTitle}"
      );

      // Mark as paid
      $db->prepare("
                UPDATE event_payouts
                SET payout_status            = 'paid',
                    paystack_transfer_code   = ?,
                    paystack_transfer_ref    = ?,
                    paid_at                  = NOW(),
                    updated_at               = NOW()
                WHERE event_id = ?
            ")->execute([
        $transfer['transfer_code'],
        $reference,
        $eventId,
      ]);

      // Audit log
      TransactionService::payoutSent(
        $eventId,
        (int) $payout['organizer_id'],
        (float) $payout['organizer_amount'],
        $transfer['transfer_code'],
        $eventTitle,
        $triggeredBy ?? 0,
        $triggeredBy === null
      );

      // Notify organizer
      NotificationService::payoutSent(
        (int) $payout['organizer_id'],
        $eventId,
        $eventTitle,
        (float) $payout['organizer_amount']
      );

      return [
        'success'       => true,
        'message'       => 'Payout initiated successfully.',
        'transfer_code' => $transfer['transfer_code'],
        'amount'        => $payout['organizer_amount'],
      ];
    } catch (Exception $e) {
      $reason = $e->getMessage();

      // Mark as failed
      $db->prepare("
                UPDATE event_payouts
                SET payout_status  = 'failed',
                    failure_reason = ?,
                    failed_at      = NOW(),
                    updated_at     = NOW()
                WHERE event_id = ?
            ")->execute([$reason, $eventId]);

      // Audit log
      TransactionService::payoutFailed($eventId, (int) $payout['organizer_id'], $eventTitle, $reason);

      // Notify organizer
      NotificationService::payoutFailed((int) $payout['organizer_id'], $eventId, $eventTitle, $reason);

      // Notify all admins
      self::notifyAllAdmins($eventId, $eventTitle, $payout['organizer_id'], $db);

      return ['success' => false, 'message' => 'Payout failed: ' . $reason];
    }
  }

  // Freeze a payout (admin action — dispute or fraud report)
  public static function freezePayout(int $eventId, int $adminId, string $reason): array
  {
    $stmt = self::db()->prepare("SELECT payout_status FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $payout = $stmt->fetch();

    if (!$payout) {
      return ['success' => false, 'message' => 'No payout record found.'];
    }

    if ($payout['payout_status'] === Constants::PAYOUT_PAID) {
      return ['success' => false, 'message' => 'Cannot freeze — payout already sent.'];
    }

    self::db()->prepare("
            UPDATE event_payouts
            SET payout_status = 'frozen',
                freeze_reason = ?,
                frozen_by     = ?,
                frozen_at     = NOW(),
                updated_at    = NOW()
            WHERE event_id = ?
        ")->execute([$reason, $adminId, $eventId]);

    // Fetch organizer_id to notify them
    $stmt = self::db()->prepare("SELECT organizer_id FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();

    $stmt = self::db()->prepare("SELECT title FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if ($row && $event) {
      NotificationService::payoutFrozen(
        (int) $row['organizer_id'],
        $eventId,
        $event['title'],
        $reason
      );
    }

    return ['success' => true, 'message' => 'Payout frozen successfully.'];
  }

  // Unfreeze a payout (admin action)
  public static function unfreezePayout(int $eventId): array
  {
    $stmt = self::db()->prepare("SELECT payout_status FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $payout = $stmt->fetch();

    if (!$payout || $payout['payout_status'] !== Constants::PAYOUT_FROZEN) {
      return ['success' => false, 'message' => 'Payout is not frozen.'];
    }

    self::db()->prepare("
            UPDATE event_payouts
            SET payout_status = 'pending',
                freeze_reason = NULL,
                frozen_by     = NULL,
                frozen_at     = NULL,
                updated_at    = NOW()
            WHERE event_id = ?
        ")->execute([$eventId]);

    return ['success' => true, 'message' => 'Payout unfrozen. It will process in the next worker run.'];
  }

  // Cancel a payout (when event is cancelled — no money to organizer)
  public static function cancelPayout(int $eventId): void
  {
    self::db()->prepare("
            INSERT INTO event_payouts (event_id, organizer_id, gross_revenue,
                platform_fee_percentage, platform_fee_amount, organizer_amount,
                payout_status, hold_until)
            SELECT id, organizer_id, 0, 0, 0, 0, 'cancelled', NOW()
            FROM events WHERE id = ?
            ON DUPLICATE KEY UPDATE
                payout_status = 'cancelled',
                updated_at    = NOW()
        ")->execute([$eventId]);
  }

  // Strike system — increment cancellation count
  // Called from EventController when organizer cancels an event
  // Returns true if organizer just got flagged
  public static function recordCancellation(int $organizerId): bool
  {
    $db = self::db();

    // Increment the count
    $db->prepare("
            UPDATE organizer_payment_details
            SET cancellation_count = cancellation_count + 1,
                updated_at         = NOW()
            WHERE user_id = ?
        ")->execute([$organizerId]);

    // Check current count
    $stmt = $db->prepare("
            SELECT cancellation_count, is_flagged FROM organizer_payment_details WHERE user_id = ?
        ");
    $stmt->execute([$organizerId]);
    $details = $stmt->fetch();

    if (!$details) return false;

    $count     = (int) $details['cancellation_count'];
    $threshold = Constants::ORGANIZER_STRIKE_THRESHOLD;

    // Flag if threshold reached and not already flagged
    if ($count >= $threshold && !$details['is_flagged']) {
      $db->prepare("
                UPDATE organizer_payment_details
                SET is_flagged   = 1,
                    flag_reason  = ?,
                    updated_at   = NOW()
                WHERE user_id = ?
            ")->execute([
        "Automatically flagged after {$count} event cancellations.",
        $organizerId,
      ]);

      // Notify the organizer
      NotificationService::organizerFlagged($organizerId, $count);

      // Notify all admins
      self::notifyAllAdmins(0, '', $organizerId, $db, true, $count);

      return true; // was just flagged
    }

    return false;
  }

  // Admin clears organizer flag
  public static function clearFlag(int $organizerId, int $adminId): void
  {
    self::db()->prepare("
            UPDATE organizer_payment_details
            SET is_flagged         = 0,
                flag_reason        = NULL,
                cancellation_count = 0,
                updated_at         = NOW()
            WHERE user_id = ?
        ")->execute([$organizerId]);

    // Log in activity_logs
    try {
      self::db()->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, 'organizer_flag_cleared', ?, ?)
            ")->execute([
        $adminId,
        "Admin #{$adminId} cleared flag for organizer #{$organizerId}. Strike count reset.",
        $_SERVER['REMOTE_ADDR'] ?? null,
      ]);
    } catch (Exception $e) {
      error_log('PayoutService::clearFlag activity log error: ' . $e->getMessage());
    }
  }

  // Notify all admins (used for payout failures and flag events)
  private static function notifyAllAdmins(
    int    $eventId,
    string $eventTitle,
    int    $organizerId,
    PDO    $db,
    bool   $isFlagAlert = false,
    int    $strikes = 0
  ): void {
    $stmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin') AND is_active = 1");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch organizer name
    $orgStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $orgStmt->execute([$organizerId]);
    $orgName = $orgStmt->fetchColumn() ?: "Organizer #{$organizerId}";

    foreach ($admins as $adminId) {
      if ($isFlagAlert) {
        NotificationService::adminOrganizerFlagged((int) $adminId, $organizerId, $orgName, $strikes);
      } else {
        NotificationService::adminPayoutFailed((int) $adminId, $eventId, $eventTitle, $orgName);
      }
    }
  }
}
