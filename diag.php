<?php
// diag.php — READ-ONLY by default. Run inside the container:
//   docker cp diag.php ticketer-app:/var/www/html/diag.php
//   docker exec -it ticketer-app php diag.php            (just report)
//   docker exec -it ticketer-app php diag.php fix        (also fulfil what can be fulfilled)

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/config/RedisService.php';
require_once __DIR__ . '/helpers/TokenHelper.php';
require_once __DIR__ . '/services/PaystackService.php';
require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/TransactionService.php';
require_once __DIR__ . '/services/PayoutService.php';
require_once __DIR__ . '/services/EventCache.php';
require_once __DIR__ . '/services/BookingFulfillment.php';

$fix = ($argv[1] ?? '') === 'fix';
$db  = Database::connect();
$ps  = new PaystackService();

// All 5 successful transactions from the Paystack dashboard
$refs = [
  'EVT-1793952896-19EB2D',
  'EVT-1793924088-6EC9E7',
  'EVT-1793864130-286175',
  'EVT-1793704063-944C15',
  'EVT-1793689733-180D3A',
];

foreach ($refs as $ref) {
  echo "\n=== {$ref}\n";

  try {
    $tx = $ps->verifyTransaction($ref);
  } catch (\Throwable $e) {
    echo "  Paystack: ERROR " . $e->getMessage() . "\n";
    continue;
  }

  $metaBookingId = (int) ($tx['metadata']['booking_id'] ?? 0);
  $email         = $tx['customer']['email'] ?? '?';
  echo "  Paystack: {$tx['status']} | NGN " . ($tx['amount'] / 100)
     . " | {$email} | paid_at " . ($tx['paid_at'] ?? '?')
     . " | metadata booking_id: " . ($metaBookingId ?: 'none')
     . " | event: " . ($tx['metadata']['event_title'] ?? '?')
     . " | qty: " . ($tx['metadata']['quantity'] ?? '?') . "\n";

  // 1. booking by reference
  $s = $db->prepare("SELECT id, user_id, payment_status, paystack_reference, total_amount, created_at FROM bookings WHERE paystack_reference = ?");
  $s->execute([$ref]);
  $byRef = $s->fetch();

  // 2. booking by metadata id
  $byId = false;
  if ($metaBookingId) {
    $s = $db->prepare("SELECT id, user_id, payment_status, paystack_reference, total_amount, created_at, deleted_at FROM bookings WHERE id = ?");
    $s->execute([$metaBookingId]);
    $byId = $s->fetch();
  }

  $booking = $byRef ?: $byId;

  if ($booking) {
    $t = $db->prepare("SELECT COUNT(*) FROM tickets WHERE booking_id = ? AND deleted_at IS NULL");
    $t->execute([$booking['id']]);
    echo "  DB: booking #{$booking['id']} status={$booking['payment_status']} ref_in_db={$booking['paystack_reference']}"
       . " tickets=" . $t->fetchColumn()
       . (!empty($booking['deleted_at']) ? " DELETED_AT={$booking['deleted_at']}" : '')
       . ($byRef ? '' : "  <-- REFERENCE MISMATCH (resume() overwrote it)") . "\n";
  } else {
    echo "  DB: NO BOOKING FOUND by reference or by metadata id.\n";
    $u = $db->prepare("SELECT id FROM users WHERE email = ?");
    $u->execute([$email]);
    $uid = $u->fetchColumn();
    if ($uid) {
      $b = $db->prepare("SELECT id, payment_status, paystack_reference, total_amount, created_at, deleted_at FROM bookings WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
      $b->execute([$uid]);
      echo "  User #{$uid} recent bookings:\n";
      foreach ($b->fetchAll() as $r) {
        echo "    #{$r['id']} {$r['payment_status']} {$r['total_amount']} {$r['paystack_reference']} {$r['created_at']}"
           . ($r['deleted_at'] ? " DELETED" : '') . "\n";
      }
    } else {
      echo "  No user with email {$email}.\n";
    }
  }

  if ($fix && $booking && $booking['payment_status'] !== 'paid') {
    try {
      $r = BookingFulfillment::run($ref, (int) $booking['id']);
      echo "  FIX => {$r['status']}\n";
    } catch (\Throwable $e) {
      echo "  FIX ERROR: " . $e->getMessage() . "\n";
    }
  }
}
echo "\nDone.\n";
