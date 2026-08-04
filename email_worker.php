<?php

// EMAIL WORKER
// Handles only email jobs: OTPs, ticket confirmations,
// password change notifications.
//
// Runs every minute via cron — fast, lightweight jobs only.
// No Chromium, no heavy processes.
//
// HOW TO RUN LOCALLY:
//   php email_worker.php
//
// HOW TO RUN ON RAILWAY / DOCKER (cron):
//   * * * * * php /var/www/html/email_worker.php

declare(strict_types=1);

set_time_limit(120);        // 1 minute max — should never need more for emails
ini_set('memory_limit', '64M');

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/services/MailService.php';

$db = Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] Email worker started.\n";

// Fetch up to 20 pending email jobs
// Email jobs are fast so we can process more per run
$db->prepare("
    UPDATE bookings
    SET payment_status = 'failed'
    WHERE payment_status = 'pending'
      AND deleted_at IS NULL
      AND created_at <= DATE_SUB(NOW(), INTERVAL 1 HOUR)
")->execute();

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
  echo "[" . date('Y-m-d H:i:s') . "] No pending email jobs. Exiting.\n";
  exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($jobs) . " email job(s) to process.\n";

foreach ($jobs as $job) {
  $jobId   = $job['id'];
  $type    = $job['type'];
  $payload = json_decode($job['payload'], true);

  echo "[" . date('Y-m-d H:i:s') . "] Processing job #{$jobId} — type: {$type}\n";

  // Mark as processing so another worker instance doesn't pick it up
  $db->prepare("
        UPDATE jobs
        SET status = 'processing', attempts = attempts + 1
        WHERE id = ?
    ")->execute([$jobId]);

  try {
    $success = false;
    $mailer  = new MailService();

    switch ($type) {

      // ── OTP (registration + email change + forgot password) ──
      case 'send_otp':
        $success = $mailer->sendOTP(
          $payload['email'],
          $payload['name'],
          $payload['otp'],
          $payload['type']
        );
        break;

      // ── Ticket purchase confirmation ──────────────────────────
      case 'send_ticket_confirmation':
        $bookingRef = $payload['booking_reference'] ?? '';
        $success = $mailer->sendTicketConfirmation(
          $payload['email'],
          $payload['name'],
          $payload['event_title'],
          $payload['event_date'],
          $payload['event_location'],
          $payload['ticket_type'],
          $payload['quantity'],
          $payload['total_amount'],
          $payload['page_url'],
          $bookingRef ? "booking-{$bookingRef}-confirm" : ''
        );
        break;

      // ── Password changed notification ─────────────────────────
      case 'send_password_changed':
        $success = $mailer->sendPasswordChanged(
          $payload['email'],
          $payload['name']
        );
        break;

      // ── Welcome email ─────────────────────────
      case 'send_welcome':
        $success = $mailer->sendWelcome(
          $payload['email'],
          $payload['name']
        );
        break;

      // ── Forgot password OTP ─────────────────────────
      case 'send_forgot_password_otp':
        $success = $mailer->sendForgotPasswordOTP(
          $payload['email'],
          $payload['name'],
          $payload['otp']
        );
        break;

        // Event Reminder
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

      // ── Unknown type landed in the email queue ────────────────
      default:
        throw new Exception("Unexpected job type '{$type}' in email queue.");
    }

    if (!$success) {
      throw new Exception("Mailer returned false for job type '{$type}'. Check mail service logs.");
    }

    // Mark done
    $db->prepare("
            UPDATE jobs
            SET status       = 'done',
                completed_at = NOW()
            WHERE id = ?
        ")->execute([$jobId]);

    echo "[" . date('Y-m-d H:i:s') . "] ✓ Job #{$jobId} ({$type}) completed.\n";
  } catch (Exception $e) {
    $error       = $e->getMessage();
    $attemptsNow = (int) $job['attempts'] + 1;
    $newStatus   = $attemptsNow >= (int) $job['max_attempts'] ? 'failed' : 'pending';

    // Exponential backoff: 1 min → 5 min → 15 min
    $backoffSeconds = match ($attemptsNow) {
      1       => 60,
      2       => 300,
      default => 900,
    };
    $retryAt = date('Y-m-d H:i:s', strtotime("+{$backoffSeconds} seconds"));

    echo "[" . date('Y-m-d H:i:s') . "] ✗ Job #{$jobId} failed (attempt {$attemptsNow}): {$error}\n";

    if ($newStatus === 'failed') {
      echo "[" . date('Y-m-d H:i:s') . "] ⚠ Job #{$jobId} permanently failed after {$attemptsNow} attempts.\n";
      // TODO: alert admin or surface this in a dashboard
    }

    $db->prepare("
            UPDATE jobs
            SET status       = ?,
                error        = ?,
                available_at = CASE WHEN ? = 'pending' THEN ? ELSE available_at END
            WHERE id = ?
        ")->execute([$newStatus, $error, $newStatus, $retryAt, $jobId]);
  }

  // Small delay between sends to avoid hitting mail API rate limits
  usleep(200000); // 200ms
}

echo "[" . date('Y-m-d H:i:s') . "] Email worker finished. Processed " . count($jobs) . " job(s).\n";
