<?php

/**
 * PayoutController
 *
 * Handles admin payout management — viewing, triggering,
 * freezing, unfreezing, refunding, and organizer flag clearing.
 *
 * Routes (in routes/payouts.php):
 *   GET  /api/admin/payouts                        → index()
 *   GET  /api/admin/payouts/pending                → pending()
 *   POST /api/admin/payouts/:eventId/trigger       → trigger()
 *   POST /api/admin/payouts/:eventId/freeze        → freeze()
 *   POST /api/admin/payouts/:eventId/unfreeze      → unfreeze()
 *   POST /api/admin/payouts/:eventId/refund-all    → refundAll()
 *   POST /api/admin/organizers/:id/clear-flag      → clearFlag()
 */
class PayoutController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/admin/payouts
  // All payouts platform-wide.
  // Optional ?status= filter (pending/processing/paid/failed/frozen/cancelled)
  public function index(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(100, max(1, (int) $this->request->query('limit', '25')));
    $offset = ($page - 1) * $limit;
    $status = trim($this->request->query('status', ''));

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($status)) {
      $conditions[] = 'ep.payout_status = ?';
      $params[]     = $status;
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM event_payouts ep WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                ep.*,
                e.title      AS event_title,
                e.start_date AS event_start_date,
                e.end_date   AS event_end_date,
                u.name       AS organizer_name,
                u.email      AS organizer_email,
                fb.name      AS frozen_by_name
            FROM event_payouts ep
            JOIN events e  ON e.id  = ep.event_id
            JOIN users  u  ON u.id  = ep.organizer_id
            LEFT JOIN users fb ON fb.id = ep.frozen_by
            WHERE {$where}
            ORDER BY ep.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);

    Response::success([
      'payouts'    => $stmt->fetchAll(),
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/admin/payouts/pending
  // Events that have ended, hold period has passed,
  // but payout has not been sent yet.
  // This is the admin's action queue — press trigger on each one.
  public function pending(): void
  {
    $stmt = $this->db->prepare("
            SELECT
                ep.event_id,
                ep.organizer_amount,
                ep.gross_revenue,
                ep.platform_fee_amount,
                ep.platform_fee_percentage,
                ep.payout_status,
                ep.hold_until,
                ep.attempts,
                e.title      AS event_title,
                e.end_date   AS event_end_date,
                u.name       AS organizer_name,
                u.email      AS organizer_email,
                opd.is_flagged,
                opd.is_verified
            FROM event_payouts ep
            JOIN events e ON e.id = ep.event_id
            JOIN users  u ON u.id = ep.organizer_id
            LEFT JOIN organizer_payment_details opd ON opd.user_id = ep.organizer_id
            WHERE ep.payout_status IN ('pending', 'failed')
              AND ep.hold_until <= NOW()
              AND e.end_date    <= NOW()
            ORDER BY ep.hold_until ASC
        ");
    $stmt->execute();

    Response::success(['payouts' => $stmt->fetchAll()]);
  }

  // POST /api/admin/payouts/:eventId/trigger
  // Manually trigger a payout for a specific event.
  // Used when admin verifies the event happened successfully.
  // PayoutService handles all the Paystack Transfer logic.
  public function trigger(array $params): void
  {
    $eventId = (int) $params['eventId'];
    $adminId = (int) $this->request->user['id'];

    $result = PayoutService::triggerPayout($eventId, $adminId);

    if (!$result['success']) {
      Response::error($result['message'], 400);
    }

    Response::success([
      'event_id'      => $eventId,
      'transfer_code' => $result['transfer_code'] ?? null,
      'amount'        => $result['amount']        ?? null,
    ], $result['message']);
  }

  // POST /api/admin/payouts/:eventId/freeze
  // Freeze a payout — stops the auto worker from paying it out.
  // Use when an attendee reports a scam or dispute.
  // Body: { reason }  — required
  public function freeze(array $params): void
  {
    $eventId = (int) $params['eventId'];
    $adminId = (int) $this->request->user['id'];
    $reason  = trim($this->request->input('reason', ''));

    if (empty($reason)) {
      Response::validationError(['reason' => 'A reason is required to freeze a payout.']);
    }

    $result = PayoutService::freezePayout($eventId, $adminId, $reason);

    if (!$result['success']) {
      Response::error($result['message'], 400);
    }

    Response::success(null, $result['message']);
  }

  // POST /api/admin/payouts/:eventId/unfreeze
  // Unfreeze a payout after review.
  // The payout goes back to 'pending' and the
  // auto worker will pick it up on the next run.
  public function unfreeze(array $params): void
  {
    $eventId = (int) $params['eventId'];
    $result  = PayoutService::unfreezePayout($eventId);

    if (!$result['success']) {
      Response::error($result['message'], 400);
    }

    Response::success(null, $result['message']);
  }

  // POST /api/admin/payouts/:eventId/refund-all
  // Refund every paid attendee for a cancelled event.
  // - Calls Paystack refund API for each booking
  // - Marks all bookings as 'refunded'
  // - Writes audit log entry per booking
  // - Notifies each attendee
  // - Cancels the organizer payout (they get nothing)
  // Body: { reason }  — optional note shown in audit log
  public function refundAll(array $params): void
  {
    $eventId = (int) $params['eventId'];
    $adminId = (int) $this->request->user['id'];
    $note    = trim($this->request->input('reason', 'Event cancelled by platform.'));

    // Fetch all paid bookings for this event
    $stmt = $this->db->prepare("
            SELECT
                b.*,
                e.organizer_id,
                tt.name AS ticket_type_name,
                e.title AS event_title
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE b.event_id        = ?
              AND b.payment_status  = 'paid'
              AND b.deleted_at      IS NULL
        ");
    $stmt->execute([$eventId]);
    $bookings = $stmt->fetchAll();

    if (empty($bookings)) {
      Response::error('No paid bookings found for this event.', 400);
    }

    $paystack  = new PaystackService();
    $succeeded = 0;
    $failed    = 0;
    $errors    = [];

    foreach ($bookings as $booking) {
      try {
        // Trigger Paystack refund — capture the response
        $refundData = $paystack->refundTransaction($booking['paystack_reference']);

        // Paystack returns a refund ID — build a traceable reference
        $refundReference = 'REF-' . ($refundData['id'] ?? uniqid());

        // Mark booking as refunded
        $this->db->prepare("
            UPDATE bookings
            SET payment_status = 'refunded', refunded_at = NOW()
            WHERE id = ?
        ")->execute([$booking['id']]);

        // Pass refund reference into the note for full traceability
        TransactionService::refundProcessed(
          $booking,
          $adminId,
          $note . " | Paystack Refund ID: {$refundReference} | Original ref: {$booking['paystack_reference']}"
        );

        NotificationService::eventCancelled(
          (int) $booking['user_id'],
          $eventId,
          $booking['event_title']
        );

        $succeeded++;
      } catch (Exception $e) {
        $failed++;
        $errors[] = "Booking #{$booking['id']}: " . $e->getMessage();
        error_log("refundAll error booking #{$booking['id']}: " . $e->getMessage());
      }
    }

    // Cancel the organizer payout — they get nothing on cancellation
    PayoutService::cancelPayout($eventId);

    Response::success([
      'refunded' => $succeeded,
      'failed'   => $failed,
      'errors'   => $errors,
    ], "{$succeeded} refund(s) processed. {$failed} failed.");
  }

  // POST /api/admin/organizers/:id/clear-flag
  // Clear an organizer's flag and reset their strike count to 0.
  // Use after reviewing the organizer's cancellation history
  // and deciding to give them another chance.
  public function clearFlag(array $params): void
  {
    $organizerId = (int) $params['id'];
    $adminId     = (int) $this->request->user['id'];

    $stmt = $this->db->prepare(
      "SELECT id FROM organizer_payment_details WHERE user_id = ?"
    );
    $stmt->execute([$organizerId]);

    if (!$stmt->fetch()) {
      Response::notFound('Organizer payment details not found.');
    }

    PayoutService::clearFlag($organizerId, $adminId);

    Response::success(null, 'Organizer flag cleared and strike count reset to zero.');
  }
}
