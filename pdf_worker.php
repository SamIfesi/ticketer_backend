<?php

// PDF WORKER
// Handles only PDF ticket generation jobs.
//
// Runs every 5 minutes via cron — Chromium is heavy, slow,
// and memory-intensive so we give it its own isolated lane.
// Processing fewer jobs per run prevents memory exhaustion.
//
// HOW TO RUN LOCALLY:
//   php pdf_worker.php
//
// HOW TO RUN ON RAILWAY / DOCKER (cron):
//   */5 * * * * php /var/www/html/pdf_worker.php

declare(strict_types=1);

set_time_limit(300);         // 5 minutes max — Chromium can be slow
ini_set('memory_limit', '256M');

require_once __DIR__ . '/config/Environment.php';
Environment::load(__DIR__ . '/.env');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/Database.php';
require_once __DIR__ . '/config/Constants.php';
require_once __DIR__ . '/services/PDFService.php';

$db = Database::connect();

echo "[" . date('Y-m-d H:i:s') . "] PDF worker started.\n";

// Fetch up to 5 pending PDF jobs
// Each job can spin up Chromium — keep this number low to
// avoid OOM kills on the container
$stmt = $db->prepare("
    SELECT * FROM jobs
    WHERE status       = 'pending'
      AND queue        = 'pdf'
      AND available_at <= NOW()
      AND attempts      < max_attempts
    ORDER BY created_at ASC
    LIMIT 5
");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
  echo "[" . date('Y-m-d H:i:s') . "] No pending PDF jobs. Exiting.\n";
  exit;
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($jobs) . " PDF job(s) to process.\n";

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

    switch ($type) {

      // ── Single booking ticket generation ──────────────────────
      case 'generate_ticket':
        $bookingId = (int) ($payload['booking_id'] ?? 0);

        if ($bookingId === 0) {
          throw new Exception('generate_ticket job missing booking_id in payload.');
        }

        echo "[" . date('Y-m-d H:i:s') . "] Generating PDF(s) for booking #{$bookingId}\n";

        // Idempotent — skip if all PDFs already exist on disk
        if (PDFService::ticketExists($bookingId)) {
          echo "[" . date('Y-m-d H:i:s') . "] Ticket(s) for booking #{$bookingId} already exist. Skipping.\n";
          $success = true;
          break;
        }

        $filePaths = PDFService::generateTickets($bookingId);
        $count     = count($filePaths);
        $totalSize = array_sum(array_map('filesize', $filePaths));

        echo "[" . date('Y-m-d H:i:s') . "] Generated {$count} PDF(s) for booking #{$bookingId}"
          . " — " . round($totalSize / 1024, 1) . " KB total\n";

        $success = true;
        break;

      // ── Bulk generation (future use) ───────────────────────────
      // If you ever need to regenerate all tickets for an event,
      // push a job with type 'generate_tickets_bulk' and
      // payload: { booking_ids: [1, 2, 3, ...] }
      case 'generate_tickets_bulk':
        $bookingIds = $payload['booking_ids'] ?? [];

        if (empty($bookingIds)) {
          throw new Exception('generate_tickets_bulk job has empty booking_ids.');
        }

        echo "[" . date('Y-m-d H:i:s') . "] Bulk generating PDFs for "
          . count($bookingIds) . " booking(s)\n";

        $generated = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($bookingIds as $bookingId) {
          $bookingId = (int) $bookingId;
          try {
            if (PDFService::ticketExists($bookingId)) {
              $skipped++;
              continue;
            }
            PDFService::generateTickets($bookingId);
            $generated++;
          } catch (Exception $inner) {
            $failed++;
            error_log("Bulk PDF failed for booking #{$bookingId}: " . $inner->getMessage());
          }

          // Brief pause between Chromium launches to avoid memory spikes
          sleep(2);
        }

        echo "[" . date('Y-m-d H:i:s') . "] Bulk done — "
          . "generated: {$generated}, skipped: {$skipped}, failed: {$failed}\n";

        // Only mark as failed if everything failed
        if ($failed > 0 && $generated === 0 && $skipped === 0) {
          throw new Exception("All {$failed} bulk PDF generation(s) failed.");
        }

        $success = true;
        break;

      // ── Unknown type landed in the PDF queue ──────────────────
      default:
        throw new Exception("Unexpected job type '{$type}' in PDF queue.");
    }

    if (!$success) {
      throw new Exception("PDF generation returned false for job #{$jobId}.");
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

    // Longer backoff for PDF — Chromium failures often need more time to recover
    // 2 min → 10 min → 30 min
    $backoffSeconds = match ($attemptsNow) {
      1       => 120,
      2       => 600,
      default => 1800,
    };
    $retryAt = date('Y-m-d H:i:s', strtotime("+{$backoffSeconds} seconds"));

    echo "[" . date('Y-m-d H:i:s') . "] ✗ Job #{$jobId} failed (attempt {$attemptsNow}): {$error}\n";

    if ($newStatus === 'failed') {
      // Permanently failed — user will need to trigger on-demand via
      // GET /api/bookings/:id/ticket which generates synchronously as fallback
      echo "[" . date('Y-m-d H:i:s') . "] ⚠ Job #{$jobId} permanently failed after {$attemptsNow} attempts."
        . " User can still download via the ticket endpoint (on-demand generation).\n";
    }

    $db->prepare("
            UPDATE jobs
            SET status       = ?,
                error        = ?,
                available_at = CASE WHEN ? = 'pending' THEN ? ELSE available_at END
            WHERE id = ?
        ")->execute([$newStatus, $error, $newStatus, $retryAt, $jobId]);
  }

  // Pause between Chromium launches — lets memory fully release
  sleep(3);
}

echo "[" . date('Y-m-d H:i:s') . "] PDF worker finished. Processed " . count($jobs) . " job(s).\n";
