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
    if (Constants::splitMode()) return 0.0;
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
    $stmt = self::db()->prepare("SELECT end_date, payout_plan FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    $eventEndDate = $event['end_date'] ?? date('Y-m-d H:i:s', strtotime('+1 day'));
    $payoutPlan   = $event['payout_plan'] ?? Constants::PAYOUT_PLAN_STANDARD;
    $holdUntil = $payoutPlan === Constants::PAYOUT_PLAN_EARLY
      ? date('Y-m-d H:i:s', strtotime('+' . Constants::PAYOUT_HOLD_HOURS_EARLY . ' hours'))
      : date('Y-m-d H:i:s', strtotime('+' . Constants::PAYOUT_HOLD_HOURS . ' hours', strtotime($eventEndDate)));

    // Split mode rows are already settled by Paystack — never queue them
    $initialStatus = Constants::splitMode()
      ? Constants::PAYOUT_SPLIT_SETTLED
      : Constants::PAYOUT_PENDING;

    // Upsert — if row exists for this event, add to it. hold_until is
    // only set on the INSERT branch, never slid forward by later
    // bookings (otherwise a steady trickle of sales could push a
    // standard-plan payout out indefinitely).
    //
    // If this event was already fully paid out (payout_status='paid')
    // and a fresh booking just landed — the normal case on an 'early'
    // plan event that's still selling tickets — flip it back to
    // 'pending' and reset attempts so triggerPayout()/the worker will
    // release the new delta. See triggerPayout()'s total_paid_out
    // handling for how the delta itself is computed.
    self::db()->prepare("
            INSERT INTO event_payouts
                (event_id, organizer_id, gross_revenue, platform_fee_percentage,
                 platform_fee_amount, organizer_amount, payout_status, hold_until)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                gross_revenue        = gross_revenue + VALUES(gross_revenue),
                platform_fee_amount  = platform_fee_amount + VALUES(platform_fee_amount),
                organizer_amount     = organizer_amount + VALUES(organizer_amount),
                payout_status        = IF(payout_status = 'paid', 'pending', payout_status),
                attempts             = IF(payout_status = 'paid', 0, attempts),
                updated_at           = NOW()
        ")->execute([
      $eventId,
      $organizerId,
      $bookingAmount,
      $feePercentage,
      $split['platform_fee'],
      $split['organizer_amount'],
      $initialStatus,
      $holdUntil,
    ]);
  }

  // Recalculate hold_until when event end_date is set/updated
  // Called from EventController when event is completed/ends
  public static function setHoldUntil(int $eventId, string $eventEndDate): void
  {
    $stmt = self::db()->prepare("SELECT payout_plan FROM events WHERE id = ?");
    $stmt->execute([$eventId]);

    if ($stmt->fetchColumn() === Constants::PAYOUT_PLAN_EARLY) {
      return;
    }

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
  //
  // Pays only the DELTA between organizer_amount (lifetime accumulated
  // total) and total_paid_out (what's already been sent). This is what
  // makes payouts repeatable for 'early' plan events that keep selling
  // tickets after their first payout — without it, payout_status would
  // stay 'paid' forever after the first transfer and every booking
  // after that would silently never reach the organizer.
  // Trigger a payout transfer to the organizer
  // Called by payout_worker.php (auto) or PayoutController (manual)
  // $triggeredBy = null for auto worker, user_id for manual
  //
  // Pays only the DELTA between organizer_amount (lifetime accumulated
  // total) and total_paid_out (what's already been sent) — this is what
  // makes payouts repeatable for 'early' plan events that keep selling
  // tickets after their first payout.
  //
  // The claim (SELECT + status check + UPDATE to 'processing') is done
  // as a single atomic UPDATE ... WHERE status IN (...) instead of a
  // read-then-write pair, so two near-simultaneous calls for the same
  // event (double-click, retried request, etc.) can't both pass the
  // check and both send a transfer.
  public static function triggerPayout(int $eventId, ?int $triggeredBy = null): array
  {
    // Split mode: Paystack already settles the organizer directly.
    // The Transfer API must never be called from here.
    if (Constants::splitMode()) {
      return ['success' => false, 'message' => 'Split mode is active: the organizer is settled directly by Paystack. Nothing to trigger.'];
    }

    $db = self::db();

    // Fetch the row first just to give a useful error message if it
    // doesn't exist or is frozen/cancelled — this read is NOT what
    // decides whether we're allowed to proceed, the atomic claim below is.
    $stmt = $db->prepare("SELECT * FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $payout = $stmt->fetch();

    if (!$payout) {
      return ['success' => false, 'message' => 'No payout record found for this event.'];
    }

    if ($payout['payout_status'] === Constants::PAYOUT_FROZEN) {
      return ['success' => false, 'message' => 'Payout is frozen. Unfreeze it first.'];
    }

    if ($payout['payout_status'] === Constants::PAYOUT_CANCELLED) {
      return ['success' => false, 'message' => 'Event was cancelled. No payout applicable.'];
    }

    $alreadyPaidOut = (float) ($payout['total_paid_out'] ?? 0);
    $amountDue      = round((float) $payout['organizer_amount'] - $alreadyPaidOut, 2);

    if ($amountDue <= 0) {
      return ['success' => false, 'message' => 'Nothing new to pay out — organizer is already settled.'];
    }

    // ── ATOMIC CLAIM ──
    // This single UPDATE is the real gate. It only succeeds (rowCount
    // > 0) if payout_status is STILL 'pending' or 'failed' at the
    // instant it runs — MySQL serializes concurrent UPDATEs to the
    // same row, so if two requests race here, only one of them can
    // possibly see rowCount() > 0. The loser sees 0 and stops.
    $claim = $db->prepare("
        UPDATE event_payouts
        SET payout_status = 'processing', attempts = attempts + 1,
            triggered_by = ?, updated_at = NOW()
        WHERE event_id = ? AND payout_status IN ('pending', 'failed')
    ");
    $claim->execute([$triggeredBy, $eventId]);

    if ($claim->rowCount() === 0) {
      return ['success' => false, 'message' => 'Payout is already being processed or is not currently payable. Please refresh and check its status.'];
    }

    // Fetch organizer payment details
    $stmt = $db->prepare("
            SELECT * FROM organizer_payment_details WHERE user_id = ? AND is_verified = 1
        ");
    $stmt->execute([$payout['organizer_id']]);
    $paymentDetails = $stmt->fetch();

    if (!$paymentDetails) {
      // We already claimed the row (status = 'processing') — release it
      // back so a future attempt (once bank details are added) can retry.
      $db->prepare("UPDATE event_payouts SET payout_status = 'failed', failure_reason = ?, failed_at = NOW() WHERE event_id = ?")
        ->execute(['Organizer has no verified bank details.', $eventId]);
      return ['success' => false, 'message' => 'Organizer has no verified bank details.'];
    }

    if ($paymentDetails['is_flagged']) {
      $db->prepare("UPDATE event_payouts SET payout_status = 'failed', failure_reason = ?, failed_at = NOW() WHERE event_id = ?")
        ->execute(['Organizer account is flagged.', $eventId]);
      return ['success' => false, 'message' => 'Organizer account is flagged. Payout blocked.'];
    }

    // Fetch event title for logging
    $stmt = $db->prepare("SELECT title FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    $eventTitle = $event['title'] ?? "Event #{$eventId}";

    try {
      $paystack  = new PaystackService();
      $reference = 'PAYOUT-' . $eventId . '-' . time();

      // Initiate the Paystack transfer — only for the outstanding delta
      $transfer = $paystack->initiateTransfer(
        $amountDue,
        $paymentDetails['paystack_recipient_code'],
        $reference,
        "Event payout: {$eventTitle}"
      );

      // Mark as paid AND accumulate what's been sent. accumulateRevenue()
      // flips this row back to 'pending' if more bookings land later,
      // so a subsequent trigger will only owe the next delta, never
      // the full lifetime sum again.
      $db->prepare("
                UPDATE event_payouts
                SET payout_status            = 'paid',
                    total_paid_out           = total_paid_out + ?,
                    paystack_transfer_code   = ?,
                    paystack_transfer_ref    = ?,
                    paid_at                  = NOW(),
                    updated_at               = NOW()
                WHERE event_id = ?
            ")->execute([
        $amountDue,
        $transfer['transfer_code'],
        $reference,
        $eventId,
      ]);

      // Audit log
      TransactionService::payoutSent(
        $eventId,
        (int) $payout['organizer_id'],
        $amountDue,
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
        $amountDue
      );

      // Notify admins + dev accounts
      self::notifyPayoutSent(
        $eventId,
        $eventTitle,
        (int) $payout['organizer_id'],
        $amountDue,
        $transfer['transfer_code'],
        $db
      );

      // Email the organizer + every dev account
      self::queuePayoutEmail(
        (int) $payout['organizer_id'],
        $eventTitle,
        $amountDue,
        true,
        $db
      );

      return [
        'success'       => true,
        'message'       => 'Payout initiated successfully.',
        'transfer_code' => $transfer['transfer_code'],
        'amount'        => $amountDue,
      ];
    } catch (Exception $e) {
      $reason = $e->getMessage();

      // Mark as failed — releases the claim so a later retry (worker's
      // next run, or an admin manually re-triggering) can pick it up.
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

      // Email the organizer + every dev account
      self::queuePayoutEmail(
        (int) $payout['organizer_id'],
        $eventTitle,
        $amountDue,
        false,
        $db
      );

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

    if ($payout['payout_status'] === Constants::PAYOUT_SPLIT_SETTLED) {
      return ['success' => false, 'message' => 'Cannot freeze — this event is settled directly to the organizer by Paystack (split mode).'];
    }

    // NOTE: 'paid' no longer means "fully settled forever" — but a
    // frozen row still blocks future triggers regardless of delta, so
    // freezing a 'paid' row (to stop a NEW delta from going out) is
    // intentionally still allowed. Only block freezing if there is
    // truly nothing outstanding and nothing more can accrue — that's
    // not knowable here, so we keep this permissive on purpose.

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
  // Cancel a payout (when event is cancelled — no money to organizer,
  // beyond whatever was already sent).
  //
  // If total_paid_out > 0 when this fires, money has ALREADY left the
  // platform for this organizer — cancelling the payout row stops any
  // future delta from going out, but it does NOT claw back what's
  // already been transferred. That has to happen manually (dispute the
  // organizer directly, hold their next payout on another event, etc.)
  // This alert exists so that gap is never silent.
  public static function cancelPayout(int $eventId): void
  {
    $db = self::db();

    // Check how much (if anything) was already paid out BEFORE we
    // touch the row, so we know whether to alert.
    $stmt = $db->prepare("SELECT total_paid_out, organizer_id FROM event_payouts WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $existing = $stmt->fetch();

    $db->prepare("
            INSERT INTO event_payouts (event_id, organizer_id, gross_revenue,
                platform_fee_percentage, platform_fee_amount, organizer_amount,
                payout_status, hold_until)
            SELECT id, organizer_id, 0, 0, 0, 0, 'cancelled', NOW()
            FROM events WHERE id = ?
            ON DUPLICATE KEY UPDATE
                payout_status = 'cancelled',
                updated_at    = NOW()
        ")->execute([$eventId]);

    $alreadyPaidOut = (float) ($existing['total_paid_out'] ?? 0);

    if ($alreadyPaidOut > 0) {
      $stmt = $db->prepare("SELECT title FROM events WHERE id = ?");
      $stmt->execute([$eventId]);
      $eventTitle = $stmt->fetchColumn() ?: "Event #{$eventId}";

      $orgStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
      $orgStmt->execute([$existing['organizer_id']]);
      $orgName = $orgStmt->fetchColumn() ?: "Organizer #{$existing['organizer_id']}";

      $adminStmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
      $adminStmt->execute();
      foreach ($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        NotificationService::adminPayoutClawbackNeeded(
          (int) $adminId,
          $eventId,
          $eventTitle,
          $orgName,
          $alreadyPaidOut
        );
      }
    }
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

  // Notify all admins AND dev accounts that a payout succeeded.
  // Separate from notifyAllAdmins() because it fans out to two
  // different NotificationService methods depending on role, and
  // covers a different event (success, not failure/flag).
  private static function notifyPayoutSent(
    int    $eventId,
    string $eventTitle,
    int    $organizerId,
    float  $amount,
    string $transferCode,
    PDO    $db
  ): void {
    $orgStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $orgStmt->execute([$organizerId]);
    $orgName = $orgStmt->fetchColumn() ?: "Organizer #{$organizerId}";

    $stmt = $db->prepare("SELECT id, role FROM users WHERE role IN ('admin', 'dev') AND is_active = 1");
    $stmt->execute();
    $recipients = $stmt->fetchAll();

    foreach ($recipients as $r) {
      if ($r['role'] === Constants::ROLE_DEV) {
        NotificationService::devPayoutSent((int) $r['id'], $eventId, $eventTitle, $orgName, $amount, $transferCode);
      } else {
        NotificationService::adminPayoutSent((int) $r['id'], $eventId, $eventTitle, $orgName, $amount, $transferCode);
      }
    }
  }

  // Queue a payout result email to the organizer and every dev account.
  // FIX: previously called with 6 arguments in triggerPayout()'s
  // success path against a 5-parameter signature — an uncaught
  // ArgumentCountError (extends Error, not Exception, so the
  // surrounding try/catch never caught it) that killed the request
  // or worker run immediately after the transfer and DB update had
  // already gone through.
  private static function queuePayoutEmail(
    int    $organizerId,
    string $eventTitle,
    float  $payoutAmount,
    bool   $successful,
    PDO    $db
  ): void {
    $stmt = $db->prepare("SELECT name, email FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$organizerId]);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $devStmt = $db->query("SELECT name, email FROM users WHERE role IN ('admin', 'dev') AND is_active = 1");
    $recipients = array_merge($recipients, $devStmt->fetchAll(PDO::FETCH_ASSOC));

    $payoutDate  = date('Y-m-d H:i:s');
    $amount      = number_format($payoutAmount, 2, '.', '');
    $queueMethod = $successful ? 'payoutSuccess' : 'payoutFailed';

    foreach ($recipients as $recipient) {
      if (empty($recipient['email'])) {
        continue;
      }

      QueueService::$queueMethod(
        $recipient['email'],
        $recipient['name'] ?: 'User',
        $eventTitle,
        $payoutDate,
        $amount
      );
    }
  }
}
