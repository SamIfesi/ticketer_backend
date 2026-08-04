<?php

// EVENT REMINDER WORKER
// Runs once daily (cron). Finds events starting in ~2 days,
// queues a reminder email + in-app notification for every
// attendee with a paid booking, then marks the event as
// reminded so it's never sent twice.
//
// HOW TO RUN LOCALLY:
//   php event_reminder_worker.php
//
// HOW TO RUN ON RAILWAY / DOCKER (cron):
//   0 9 * * * php /var/www/html/event_reminder_worker.php
//   (9am daily — adjust to your timezone/preference)

declare(strict_types=1);

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/NotificationService.php';

$db = Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] Event reminder worker started.\n";

// ============================================================
// Find published events starting 47-49 hours from now
// (a 2-hour window so a once-daily cron run doesn't miss
// events that fall just outside a tighter window), that
// haven't been reminded yet.
// ============================================================
$stmt = $db->prepare("
    SELECT id, title, slug, location, start_date
    FROM events
    WHERE status = 'published'
      AND deleted_at IS NULL
      AND reminder_sent_at IS NULL
      AND start_date BETWEEN DATE_ADD(NOW(), INTERVAL 47 HOUR)
                          AND DATE_ADD(NOW(), INTERVAL 49 HOUR)
    ORDER BY start_date ASC
");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($events)) {
  echo "[" . date('Y-m-d H:i:s') . "] No events need reminders today. Exiting.\n";
  exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($events) . " event(s) to remind.\n";

$frontendUrl = rtrim(Environment::get('FRONTEND_URL', 'https://ticketer.website'), '/');

foreach ($events as $event) {
  $eventId = (int) $event['id'];

  echo "[" . date('Y-m-d H:i:s') . "] Processing event #{$eventId} — {$event['title']}\n";

  // Every distinct attendee with a paid, non-deleted booking for this event
  $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.name, u.email
        FROM bookings b
        JOIN users u ON u.id = b.user_id
        WHERE b.event_id        = ?
          AND b.payment_status  = 'paid'
          AND b.deleted_at      IS NULL
    ");
  $stmt->execute([$eventId]);
  $attendees = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (empty($attendees)) {
    echo "[" . date('Y-m-d H:i:s') . "]   No paid attendees — skipping, marking as reminded.\n";
  } else {
    $eventUrl = "{$frontendUrl}/events/{$event['slug']}";

    foreach ($attendees as $attendee) {
      // Queue the reminder email
      QueueService::sendEventReminder(
        $attendee['email'],
        $attendee['name'],
        $event['title'],
        $event['start_date'],
        $event['location'] ?? '',
        $eventUrl
      );

      // Push the in-app notification too
      NotificationService::eventReminder(
        (int) $attendee['id'],
        $eventId,
        $event['title'],
        $event['start_date']
      );
    }

    echo "[" . date('Y-m-d H:i:s') . "]   Queued reminders for " . count($attendees) . " attendee(s).\n";
  }

  $adminStmt = $db->prepare("SELECT id FROM users WHERE role IN ('admin', 'dev') AND is_active = 1");
  $adminStmt->execute();
  $admins = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

  foreach ($admins as $adminId) {
    NotificationService::adminEventReminder(
      (int) $adminId,
      $eventId,
      $event['title'],
      $event['start_date'],
      count($attendees)
    );
  }

  // Mark this event as reminded so it's never processed again,
  // even if the worker re-runs later today.
  $db->prepare("UPDATE events SET reminder_sent_at = NOW() WHERE id = ?")
    ->execute([$eventId]);
}

echo "[" . date('Y-m-d H:i:s') . "] Event reminder worker finished.\n";
