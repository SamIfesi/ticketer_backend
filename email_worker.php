<?php

// EMAIL WORKER
// Handles email jobs (OTPs, ticket confirmations, password change notices,
// reminders, payout emails) AND reconciles stale pending bookings against
// Paystack so a paid-but-never-verified booking is never lost.
//
// Runs every minute via cron.
//
// HOW TO RUN LOCALLY:
//   php email_worker.php
//
// HOW TO RUN ON RAILWAY / DOCKER (cron):
//   * * * * * php /var/www/html/email_worker.php
// 
// HOW TO RUN IN EC2 / LINUX (cron):
//   * * * * * /usr/bin/php /var/www/html/email_worker.php

declare(strict_types=1);

set_time_limit(120);
ini_set('memory_limit', '128M');

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/config/RedisService.php';
require_once __DIR__ . '/helpers/TokenHelper.php';
require_once __DIR__ . '/services/MailService.php';
require_once __DIR__ . '/services/PaystackService.php';
require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/TransactionService.php';
require_once __DIR__ . '/services/PayoutService.php';
require_once __DIR__ . '/services/EventCache.php';
require_once __DIR__ . '/services/BookingFulfillment.php';

function logLine(string $msg): void
{
  echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Prevent overlapping runs ─────────────────────────────────
// Reconciliation calls Paystack, so a slow run must not stack with the next
// minute's run and double-process the same bookings.
$lockPath = sys_get_temp_dir() . '/ticketer_email_worker.lock';
$lockFp   = fopen($lockPath, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
  logLine('Another email worker run is still active. Exiting.');
  exit;
}

$db = Database::connect();

logLine('Email worker started.');

// ═════════════════════════════════════════════════════════════
// 1. PAYMENT RECONCILIATION
// ═════════════════════════════════════════════════════════════
// Old behaviour: blindly mark every pending booking older than 1 hour as
// 'failed'. That destroyed bookings whose payment actually succeeded but whose
// browser callback never arrived (closed popup / lost network).
//
// New behaviour:
//   - After 3 minutes, ask Paystack about each pending booking.
//   - success   -> fulfil it (paid + tickets + emails), same code as the webhook.
//   - not found / never paid and older than 2 hours -> mark failed.
//   - Paystack unreachable or still in progress -> leave pending, retry next run.
// Failed bookings from the last 24h are also re-checked once in a while, which
// rescues payments that were wrongly marked failed before this fix.
try {
  $stmt = $db->prepare("
      SELECT id, paystack_reference, payment_status, created_at,
             TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_minutes
      FROM bookings
      WHERE deleted_at IS NULL
        AND paystack_reference IS NOT NULL
        AND total_amount > 0
        AND (
              (payment_status = 'pending'
                 AND created_at <= DATE_SUB(NOW(), INTERVAL 3 MINUTE))
           OR (payment_status = 'failed'
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 AND MINUTE(NOW()) % 10 = 0)
            )
      ORDER BY created_at ASC
      LIMIT 25
  ");
  $stmt->execute();
  $toCheck = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($toCheck)) {
    logLine('Reconciling ' . count($toCheck) . ' booking(s) against Paystack.');
  }

  foreach ($toCheck as $b) {
    $bookingId = (int) $b['id'];
    $ref       = $b['paystack_reference'];

    try {
      $result = BookingFulfillment::run($ref, $bookingId);

      if ($result['status'] === 'paid') {
        logLine("✓ Booking #{$bookingId} ({$ref}) confirmed by reconciliation — tickets issued.");
        continue;
      }

      if ($result['status'] === 'already_paid') {
        continue;
      }

      // not_paid: only give up on pending bookings that are old enough
      if (
        $b['payment_status'] === 'pending'
        && (int) $b['age_minutes'] >= 120
        && in_array($result['paystack_status'], ['abandoned', 'failed', 'reversed'], true)
      ) {
        $db->prepare("
            UPDATE bookings SET payment_status = 'failed'
            WHERE id = ? AND payment_status = 'pending'
        ")->execute([$bookingId]);
        logLine("Booking #{$bookingId} marked failed (Paystack: {$result['paystack_status']}).");
      }
    } catch (\Throwable $e) {
      $msg = $e->getMessage();

      // Paystack says the reference was never used to start a payment
      if (
        $b['payment_status'] === 'pending'
        && (int) $b['age_minutes'] >= 120
        && stripos($msg, 'not found') !== false
      ) {
        $db->prepare("
            UPDATE bookings
             SET payment_status = 'failed'
            WHERE id = ? AND payment_status = 'pending'
        ")->execute([$bookingId]);
        logLine("Booking #{$bookingId} marked failed (reference unknown to Paystack).");
      } else {
        // Network blip, rate limit, etc. — leave it pending and retry next run.
        logLine("Reconcile skipped for booking #{$bookingId}: {$msg}");
      }
    }

    usleep(300000); // 300ms between Paystack calls
  }
} catch (\Throwable $e) {
  // Reconciliation must never stop emails from sending.
  logLine('Reconciliation error: ' . $e->getMessage());
}

// ═════════════════════════════════════════════════════════════
// 2. EMAIL JOBS
// ═════════════════════════════════════════════════════════════
$stmt = $db->prepare("
    SELECT * FROM jobs
    WHERE status       = 'pending'
      AND queue        = 'email'
      AND available_at <= NOW()
      AND attempts      < max_attempts
    ORDER BY created_at ASC
    LIMIT 20
");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
  logLine('No pending email jobs. Exiting.');
  exit;
}

logLine('Found ' . count($jobs) . ' email job(s) to process.');

foreach ($jobs as $job) {
  $jobId   = $job['id'];
  $type    = $job['type'];
  $payload = json_decode($job['payload'], true);

  logLine("Processing job #{$jobId} — type: {$type}");

  // Claim the job atomically so another worker instance can't take it.
  $claim = $db->prepare("
      UPDATE jobs
      SET status = 'processing', attempts = attempts + 1
      WHERE id = ? AND status = 'pending'
  ");
  $claim->execute([$jobId]);
  if ($claim->rowCount() === 0) {
    logLine("Job #{$jobId} already claimed elsewhere. Skipping.");
    continue;
  }

  try {
    $success = false;
    $mailer  = new MailService();

    switch ($type) {

      // OTP (registration + email change)
      case 'send_otp':
        $success = $mailer->sendOTP(
          $payload['email'],
          $payload['name'],
          $payload['otp'],
          $payload['type']
        );
        break;

      // Ticket purchase confirmation
      case 'send_ticket_confirmation':
        $bookingRef = $payload['booking_reference'] ?? '';
        $success = $mailer->sendTicketConfirmation(
          $payload['email'],
          $payload['name'],
          $payload['event_title'],
          $payload['event_date'],
          $payload['event_location'],
          $payload['ticket_type'],
          (int) $payload['quantity'],
          (float) $payload['total_amount'],
          $payload['page_url'],
          $bookingRef ? "booking-{$bookingRef}-confirm" : ''
        );
        break;

      // Password changed notification
      case 'send_password_changed':
        $success = $mailer->sendPasswordChanged(
          $payload['email'],
          $payload['name']
        );
        break;

      // Welcome email
      case 'send_welcome':
        $success = $mailer->sendWelcome(
          $payload['email'],
          $payload['name']
        );
        break;

      // Forgot password OTP
      case 'send_forgot_password_otp':
        $success = $mailer->sendForgotPasswordOTP(
          $payload['email'],
          $payload['name'],
          $payload['otp']
        );
        break;

      // Event reminder
      case 'send_event_reminder':
        $success = $mailer->sendEventReminder(
          $payload['email'],
          $payload['name'],
          $payload['event_title'],
          $payload['event_date'],
          $payload['event_location'],
          $payload['page_url']
        );
        break;

      // Payout successful
      case 'payout_success':
        $success = $mailer->payoutSuccess(
          $payload['email'],
          $payload['name'],
          $payload['event_title'],
          $payload['payout_date'],
          $payload['payout_amount']
        );
        break;

      // Payout failed
      case 'payout_failed':
        $success = $mailer->payoutFailed(
          $payload['email'],
          $payload['name'],
          $payload['event_title'],
          $payload['payout_date'],
          $payload['payout_amount']
        );
        break;

      default:
        throw new Exception("Unexpected job type '{$type}' in email queue.");
    }

    if (!$success) {
      throw new Exception("Mailer returned false for job type '{$type}'. Check mail service logs.");
    }

    $db->prepare("
        UPDATE jobs
        SET status = 'done', completed_at = NOW()
        WHERE id = ?
    ")->execute([$jobId]);

    logLine("✓ Job #{$jobId} ({$type}) completed.");
  } catch (\Throwable $e) {
    $error       = $e->getMessage();
    $attemptsNow = (int) $job['attempts'] + 1;
    $newStatus   = $attemptsNow >= (int) $job['max_attempts'] ? 'failed' : 'pending';

    // Backoff: 1 min -> 5 min -> 15 min
    $backoffSeconds = match ($attemptsNow) {
      1       => 60,
      2       => 300,
      default => 900,
    };
    $retryAt = date('Y-m-d H:i:s', strtotime("+{$backoffSeconds} seconds"));

    logLine("✗ Job #{$jobId} failed (attempt {$attemptsNow}): {$error}");

    if ($newStatus === 'failed') {
      logLine("⚠ Job #{$jobId} permanently failed after {$attemptsNow} attempts.");
    }

    $db->prepare("
        UPDATE jobs
        SET status       = ?,
            error        = ?,
            available_at = CASE WHEN ? = 'pending' THEN ? ELSE available_at END
        WHERE id = ?
    ")->execute([$newStatus, $error, $newStatus, $retryAt, $jobId]);
  }

  usleep(200000); // 200ms between sends to respect mail API rate limits
}

logLine('Email worker finished. Processed ' . count($jobs) . ' job(s).');
