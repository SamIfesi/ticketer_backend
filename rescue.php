<?php
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

$refs = ['EVT-1793864130-286175', 'EVT-1793924088-6EC9E7', 'EVT-1793864378-9EA685'];

foreach ($refs as $ref) {
  try {
    $r = BookingFulfillment::run($ref);
    echo $ref . ' => ' . $r['status']
      . ' (paystack: ' . ($r['paystack_status'] ?? 'n/a') . ')'
      . ($r['booking'] ? ' booking #' . $r['booking']['id'] : ' NO BOOKING FOUND')
      . "\n";
  } catch (\Throwable $e) {
    echo $ref . ' => ERROR: ' . $e->getMessage() . "\n";
  }
}
