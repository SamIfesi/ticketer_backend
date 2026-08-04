<?php

// PAYOUT WORKER
// Runs as a cron job nightly (or every hour on Railway).
// Finds events where:
//   - end_date has passed
//   - hold_until has passed (48hr fraud window)
//   - payout is still pending or failed (retry)
//   - event was NOT cancelled or deleted
//
// HOW TO RUN LOCALLY (separate terminal):
//   php payout_worker.php
//
// HOW TO RUN ON RAILWAY:
//   Add a cron job: php payout_worker.php
//   Recommended schedule: 0 2 * * *  (2am daily)
//   Or every hour:        0 * * * *

declare(strict_types=1);

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/services/PaystackService.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/TransactionService.php';
require_once __DIR__ . '/services/PayoutService.php';

$db = Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] Payout worker started.\n";

// Find all events ready for payout
$stmt = $db->prepare("
    SELECT
        ep.event_id,
        ep.organizer_id,
        ep.organizer_amount,
        ep.payout_status,
        ep.attempts,
        e.title     AS event_title,
        e.end_date  AS event_end_date,
        e.status    AS event_status
    FROM event_payouts ep
    JOIN events e ON e.id = ep.event_id
    WHERE ep.payout_status IN ('pending', 'failed')
      AND ep.hold_until  <= NOW()
      AND e.end_date     <= NOW()
      AND e.status NOT IN ('cancelled', 'deleted')
      AND ep.attempts     < 3
    ORDER BY ep.hold_until ASC
    LIMIT 50
");
$stmt->execute();
$payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($payouts)) {
  echo "[" . date('Y-m-d H:i:s') . "] No payouts ready. Exiting.\n";
  exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($payouts) . " payout(s) to process.\n";

foreach ($payouts as $payout) {
  $eventId = (int) $payout['event_id'];

  echo "[" . date('Y-m-d H:i:s') . "] Processing payout for event #{$eventId} — {$payout['event_title']}\n";
  echo "                                Amount: ₦" . number_format((float)$payout['organizer_amount'], 2) . "\n";

  // triggerPayout() handles everything:
  // - checks organizer details
  // - calls Paystack Transfer API
  // - updates event_payouts status
  // - writes audit log
  // - sends notification to organizer
  // - notifies admins on failure
  $result = PayoutService::triggerPayout($eventId, null); // null = auto worker

  if ($result['success']) {
    echo "[" . date('Y-m-d H:i:s') . "] ✓ Payout #{$eventId} succeeded. Transfer: {$result['transfer_code']}\n";
  } else {
    echo "[" . date('Y-m-d H:i:s') . "] ✗ Payout #{$eventId} failed: {$result['message']}\n";
  }

  // Small delay between transfers to avoid rate limiting
  sleep(2);
}

echo "[" . date('Y-m-d H:i:s') . "] Payout worker finished.\n";
