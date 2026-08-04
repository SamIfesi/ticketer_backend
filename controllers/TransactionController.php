<?php

/**
 * TransactionController
 *
 * Exposes the financial audit log via API.
 * All entries in transaction_logs are immutable — read only here.
 *
 * Routes (in routes/transactions.php):
 *   GET /api/transactions/mine                → mine()
 *   GET /api/organizer/transactions           → organizer()
 *   GET /api/admin/transactions/summary       → summary()
 *   GET /api/admin/transactions               → admin()
 *   GET /api/admin/transactions/:bookingId    → byBooking()
 */
class TransactionController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/transactions/mine
  // Attendee — their own payment history.
  // Shows every transaction log entry tied to their user_id.
  public function mine(): void
  {
    $userId = $this->request->user['id'];
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;

    $countStmt = $this->db->prepare(
      "SELECT COUNT(*) FROM transaction_logs WHERE user_id = ?"
    );
    $countStmt->execute([$userId]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $this->db->prepare("
            SELECT
                id,
                type,
                amount,
                currency,
                paystack_reference,
                quantity,
                unit_price,
                ticket_type_name,
                event_title,
                note,
                created_at,
                booking_id
            FROM transaction_logs
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute([$userId, $limit, $offset]);

    Response::success([
      'transactions' => $stmt->fetchAll(),
      'pagination'   => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/organizer/transactions
  // Organizer — revenue ledger for all their events.
  // Includes a summary (gross, organizer cut, platform fee,
  // refunds, payouts) for the filtered period.
  //
  // Query params:
  //   ?event_id=    filter by specific event
  //   ?type=        filter by transaction type
  //   ?from=        date range start (YYYY-MM-DD)
  //   ?to=          date range end   (YYYY-MM-DD)
  public function organizer(): void
  {
    $userId = $this->request->user['id'];
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(100, max(1, (int) $this->request->query('limit', '25')));
    $offset = ($page - 1) * $limit;

    $conditions = ['tl.organizer_id = ?'];
    $params     = [$userId];

    $eventId = (int) $this->request->query('event_id', '0');
    if ($eventId > 0) {
      $conditions[] = 'tl.event_id = ?';
      $params[]     = $eventId;
    }

    $type = trim($this->request->query('type', ''));
    if (!empty($type)) {
      $conditions[] = 'tl.type = ?';
      $params[]     = $type;
    }

    $from = trim($this->request->query('from', ''));
    if (!empty($from)) {
      $conditions[] = 'DATE(tl.created_at) >= ?';
      $params[]     = $from;
    }

    $to = trim($this->request->query('to', ''));
    if (!empty($to)) {
      $conditions[] = 'DATE(tl.created_at) <= ?';
      $params[]     = $to;
    }

    $where = implode(' AND ', $conditions);

    // Revenue summary for this filtered view
    $summaryStmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN type = 'payment_confirmed' THEN amount           ELSE 0 END) AS gross_revenue,
                SUM(CASE WHEN type = 'payment_confirmed' THEN organizer_amount ELSE 0 END) AS organizer_revenue,
                SUM(CASE WHEN type = 'payment_confirmed' THEN platform_fee     ELSE 0 END) AS platform_fees,
                SUM(CASE WHEN type = 'refund_processed'  THEN ABS(amount)      ELSE 0 END) AS total_refunded,
                SUM(CASE WHEN type = 'payout_sent'       THEN amount           ELSE 0 END) AS total_paid_out
            FROM transaction_logs tl
            WHERE {$where}
        ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();

    $countStmt = $this->db->prepare(
      "SELECT COUNT(*) FROM transaction_logs tl WHERE {$where}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                tl.id,
                tl.booking_id,
                tl.type,
                tl.amount,
                tl.currency,
                tl.paystack_reference,
                tl.quantity,
                tl.unit_price,
                tl.platform_fee,
                tl.organizer_amount,
                tl.ticket_type_name,
                tl.event_title,
                tl.note,
                tl.created_at,
                u.name  AS attendee_name,
                u.email AS attendee_email
            FROM transaction_logs tl
            JOIN users u ON u.id = tl.user_id
            WHERE {$where}
            ORDER BY tl.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);

    Response::success([
      'summary'      => $summary,
      'transactions' => $stmt->fetchAll(),
      'pagination'   => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/admin/transactions
  // Admin/Dev — platform-wide transaction log with full filters.
  //
  // Query params:
  //   ?user_id=       filter by attendee
  //   ?event_id=      filter by event
  //   ?organizer_id=  filter by organizer
  //   ?type=          filter by transaction type
  //   ?from=          date range start
  //   ?to=            date range end
  public function admin(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(100, max(1, (int) $this->request->query('limit', '25')));
    $offset = ($page - 1) * $limit;

    $conditions = ['1=1'];
    $params     = [];

    // Integer filters
    foreach (['user_id', 'event_id', 'organizer_id'] as $field) {
      $val = (int) $this->request->query($field, '0');
      if ($val > 0) {
        $conditions[] = "tl.{$field} = ?";
        $params[]     = $val;
      }
    }

    $type = trim($this->request->query('type', ''));
    if (!empty($type)) {
      $conditions[] = 'tl.type = ?';
      $params[]     = $type;
    }

    $from = trim($this->request->query('from', ''));
    if (!empty($from)) {
      $conditions[] = 'DATE(tl.created_at) >= ?';
      $params[]     = $from;
    }

    $to = trim($this->request->query('to', ''));
    if (!empty($to)) {
      $conditions[] = 'DATE(tl.created_at) <= ?';
      $params[]     = $to;
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare(
      "SELECT COUNT(*) FROM transaction_logs tl WHERE {$where}"
    );
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                tl.*,
                u.name        AS attendee_name,
                u.email       AS attendee_email,
                org.name      AS organizer_name,
                pb.name       AS performed_by_name
            FROM transaction_logs tl
            JOIN users u   ON u.id   = tl.user_id
            JOIN users org ON org.id = tl.organizer_id
            LEFT JOIN users pb ON pb.id = tl.performed_by
            WHERE {$where}
            ORDER BY tl.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);

    Response::success([
      'transactions' => $stmt->fetchAll(),
      'pagination'   => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/admin/transactions/summary
  // Admin/Dev — financial KPIs for a date range.
  // Defaults to current month if no dates provided.
  //
  // Query params:
  //   ?from=  start date (YYYY-MM-DD), default: first day of month
  //   ?to=    end date   (YYYY-MM-DD), default: today
  public function summary(): void
  {
    $from = trim($this->request->query('from', date('Y-m-01')));
    $to   = trim($this->request->query('to',   date('Y-m-d')));

    $params = [$from, $to];

    // Platform-wide totals
    $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN type = 'payment_confirmed' THEN amount           ELSE 0 END) AS gross_revenue,
                SUM(CASE WHEN type = 'payment_confirmed' THEN platform_fee     ELSE 0 END) AS platform_earnings,
                SUM(CASE WHEN type = 'payment_confirmed' THEN organizer_amount ELSE 0 END) AS organizer_revenue,
                SUM(CASE WHEN type = 'refund_processed'  THEN ABS(amount)      ELSE 0 END) AS total_refunded,
                SUM(CASE WHEN type = 'payout_sent'       THEN amount           ELSE 0 END) AS total_paid_out,
                COUNT(CASE WHEN type = 'payment_confirmed' THEN 1 END) AS successful_payments,
                COUNT(CASE WHEN type = 'payment_failed'    THEN 1 END) AS failed_payments,
                COUNT(CASE WHEN type = 'refund_processed'  THEN 1 END) AS refunds,
                COUNT(CASE WHEN type = 'payout_sent'       THEN 1 END) AS payouts_sent
            FROM transaction_logs
            WHERE DATE(created_at) BETWEEN ? AND ?
        ");
    $stmt->execute($params);
    $totals = $stmt->fetch();

    // Daily breakdown
    $stmt = $this->db->prepare("
            SELECT
                DATE(created_at)                                                              AS date,
                SUM(CASE WHEN type = 'payment_confirmed' THEN amount       ELSE 0 END)        AS revenue,
                SUM(CASE WHEN type = 'payment_confirmed' THEN platform_fee ELSE 0 END)        AS platform_fee,
                SUM(CASE WHEN type = 'refund_processed'  THEN ABS(amount)  ELSE 0 END)        AS refunds,
                COUNT(CASE WHEN type = 'payment_confirmed' THEN 1 END)                        AS transactions
            FROM transaction_logs
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
    $stmt->execute($params);
    $daily = $stmt->fetchAll();

    Response::success([
      'period' => ['from' => $from, 'to' => $to],
      'totals' => $totals,
      'daily'  => $daily,
    ]);
  }

  // GET /api/admin/transactions/:bookingId
  // Full audit trail for a single booking — every state change
  // from initiated → confirmed/failed → refunded.
  public function byBooking(array $params): void
  {
    $bookingId = (int) $params['bookingId'];

    $stmt = $this->db->prepare("SELECT id FROM bookings WHERE id = ?");
    $stmt->execute([$bookingId]);
    if (!$stmt->fetch()) {
      Response::notFound('Booking not found.');
    }

    $stmt = $this->db->prepare("
            SELECT
                tl.*,
                u.name  AS attendee_name,
                pb.name AS performed_by_name
            FROM transaction_logs tl
            JOIN users u ON u.id = tl.user_id
            LEFT JOIN users pb ON pb.id = tl.performed_by
            WHERE tl.booking_id = ?
            ORDER BY tl.created_at ASC
        ");
    $stmt->execute([$bookingId]);

    Response::success([
      'booking_id'  => $bookingId,
      'audit_trail' => $stmt->fetchAll(),
    ]);
  }
}
