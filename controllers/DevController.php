<?php

class DevController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/dev/overview
  // Dev only — full platform overview including hidden data
  // Admins get 404 on all /api/dev/* routes
  // ============================================================
  public function overview(): void
  {
    // Everything admins see in stats — plus the hidden stuff

    // All users INCLUDING dev accounts
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                   AS total_users,
                SUM(CASE WHEN role = 'attendee'  THEN 1 ELSE 0 END)       AS attendees,
                SUM(CASE WHEN role = 'organizer' THEN 1 ELSE 0 END)       AS organizers,
                SUM(CASE WHEN role = 'admin'     THEN 1 ELSE 0 END)       AS admins,
                SUM(CASE WHEN role = 'dev'       THEN 1 ELSE 0 END)       AS dev_accounts
            FROM users
        ");
    $stmt->execute();
    $userStats = $stmt->fetch();

    // Full booking revenue
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                                AS total_bookings,
                SUM(CASE WHEN payment_status = 'paid'     THEN total_amount ELSE 0 END) AS total_revenue,
                SUM(CASE WHEN payment_status = 'pending'  THEN 1 ELSE 0 END)            AS pending,
                SUM(CASE WHEN payment_status = 'failed'   THEN 1 ELSE 0 END)            AS failed,
                SUM(CASE WHEN payment_status = 'refunded' THEN 1 ELSE 0 END)            AS refunded
            FROM bookings
            WHERE deleted_at IS NULL
        ");
    $stmt->execute();
    $bookingStats = $stmt->fetch();

    // Event breakdown
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                      AS total,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END)        AS published,
                SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END)        AS drafts,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)        AS cancelled,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END)        AS completed,
                SUM(CASE WHEN status = 'deleted'   THEN 1 ELSE 0 END)        AS deleted
            FROM events
        ");
    $stmt->execute();
    $eventStats = $stmt->fetch();

    // Database health — row counts per table
    $tables      = ['users', 'events', 'bookings', 'tickets', 'ticket_types', 'categories', 'dev_logs'];
    $tableCounts = [];

    foreach ($tables as $table) {
      $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table}");
      $stmt->execute();
      $tableCounts[$table] = (int) $stmt->fetchColumn();
    }

    Response::success([
      'users'        => $userStats,
      'bookings'     => $bookingStats,
      'events'       => $eventStats,
      'table_counts' => $tableCounts,
      'server_time'  => date('Y-m-d H:i:s'),
      'php_version'  => PHP_VERSION,
      'environment'  => Environment::get('APP_ENV', 'development'),
    ]);
  }

  // ============================================================
  // GET /api/dev/users
  // Dev only — ALL users including dev accounts
  // ============================================================
  public function users(): void
  {
    $stmt = $this->db->prepare("
            SELECT
                id,
                name,
                email,
                role,
                is_active,
                created_at
            FROM users
            ORDER BY role ASC, created_at DESC
        ");
    $stmt->execute();

    Response::success(['users' => $stmt->fetchAll()]);
  }

  // ============================================================
  // GET /api/dev/logs
  // Dev only — view API request logs
  // ============================================================
  public function logs(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(100, max(1, (int) $this->request->query('limit', '50')));
    $offset = ($page - 1) * $limit;

    // Optional filters
    $method   = strtoupper(trim($this->request->query('method', '')));
    $userId   = (int) $this->request->query('user_id', '0');
    $respCode = (int) $this->request->query('code', '0');

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($method)) {
      $conditions[] = 'method = ?';
      $params[]     = $method;
    }
    if ($userId > 0) {
      $conditions[] = 'user_id = ?';
      $params[]     = $userId;
    }
    if ($respCode > 0) {
      $conditions[] = 'response_code = ?';
      $params[]     = $respCode;
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM dev_logs WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                l.id,
                l.method,
                l.endpoint,
                l.ip_address,
                l.response_code,
                l.created_at,
                u.name  AS user_name,
                u.email AS user_email,
                u.role  AS user_role
            FROM dev_logs l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE {$where}
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);

    Response::success([
      'logs'       => $stmt->fetchAll(),
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // ============================================================
  // GET /api/dev/logs/:id
  // Dev only — view a single log entry including request payload
  // ============================================================
  public function showLog(array $params): void
  {
    $logId = (int) $params['id'];

    $stmt = $this->db->prepare("
            SELECT l.*, u.name AS user_name, u.email AS user_email
            FROM dev_logs l
            LEFT JOIN users u ON u.id = l.user_id
            WHERE l.id = ?
        ");
    $stmt->execute([$logId]);
    $log = $stmt->fetch();

    if (!$log) {
      Response::notFound('Log entry not found.');
    }

    // Decode payload JSON for readability
    if (!empty($log['payload'])) {
      $log['payload'] = json_decode($log['payload'], true);
    }

    Response::success(['log' => $log]);
  }

  // ============================================================
  // DELETE /api/dev/logs
  // Dev only — clear all logs
  // ============================================================
  public function clearLogs(): void
  {
    $this->db->prepare('DELETE FROM dev_logs')->execute();
    $this->db->prepare('ALTER TABLE dev_logs AUTO_INCREMENT = 1')->execute();

    Response::success(null, 'All logs cleared.');
  }

  // ============================================================
  // POST /api/dev/users/:id/role
  // Dev only — can assign ANY role including dev
  // This is the only place the dev role can be assigned
  // ============================================================
  public function forceRole(array $params): void
  {
    $targetId = (int) $params['id'];
    $newRole  = trim($this->request->input('role', ''));
    $allRoles = [...Constants::PUBLIC_ROLES, Constants::ROLE_DEV];

    if (!in_array($newRole, $allRoles, true)) {
      Response::validationError(['role' => 'Invalid role.']);
    }

    $stmt = $this->db->prepare('SELECT id, name FROM users WHERE id = ?');
    $stmt->execute([$targetId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    $this->db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);

    Response::success(null, "Role for '{$user['name']}' forced to '{$newRole}'.");
  }

  // ============================================================
  // GET /api/dev/bookings/failed
  // Dev only — view all failed and pending payments
  // Useful for debugging payment issues
  // ============================================================
  public function failedBookings(): void
  {
    $stmt = $this->db->prepare("
            SELECT
                b.id,
              b.booking_number,
                b.paystack_reference,
                b.total_amount,
                b.payment_status,
                b.created_at,
                u.name  AS user_name,
                u.email AS user_email,
                e.title AS event_title
            FROM bookings b
            JOIN users  u ON u.id = b.user_id
            JOIN events e ON e.id = b.event_id
            WHERE b.payment_status IN ('failed', 'pending')
              AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
        ");
    $stmt->execute();

    Response::success(['bookings' => $stmt->fetchAll()]);
  }

  // ============================================================
  // POST /api/dev/bookings/:id/force-pay
  //
  // FIX #1: Removed the two manual UPDATE statements that were
  // incrementing ticket_types.quantity_sold and events.tickets_sold.
  // The trg_booking_paid trigger handles quantity_sold automatically
  // when payment_status flips to 'paid'. events.tickets_sold no
  // longer exists — use v_event_sales view instead.
  // ============================================================
  public function forcePay(array $params): void
  {
    $bookingId = (int) $params['id'];

    $stmt = $this->db->prepare("
                 SELECT b.*, b.booking_number, e.title AS event_title,
                   u.name AS user_name, u.email AS user_email,
                   tt.name AS ticket_type_name,
                   e.location AS event_location, e.start_date AS event_start_date
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN users        u  ON u.id  = b.user_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE b.id = ?
        ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
      Response::notFound('Booking not found.');
    }

    if ($booking['payment_status'] === Constants::PAYMENT_PAID) {
      Response::error('This booking is already paid.', 400);
    }

    // Flip to paid — trg_booking_paid fires here and increments quantity_sold
    $this->db->prepare("
            UPDATE bookings SET payment_status = 'paid', paid_at = NOW() WHERE id = ?
        ")->execute([$bookingId]);

    // Issue tickets
    $tickets = [];
    $stmt    = $this->db->prepare("
            INSERT INTO tickets (booking_id, user_id, event_id, qr_token) VALUES (?, ?, ?, ?)
        ");

    for ($i = 0; $i < (int) $booking['quantity']; $i++) {
      $qrToken = TokenHelper::generateQRToken();
      $stmt->execute([
        $booking['id'],
        $booking['user_id'],
        $booking['event_id'],
        $qrToken,
      ]);
      $tickets[] = $qrToken;
    }

    // ── NEW ──
    $orgStmt = $this->db->prepare("SELECT organizer_id FROM events WHERE id = ?");
    $orgStmt->execute([$booking['event_id']]);
    $organizerId = (int) $orgStmt->fetchColumn();

    TransactionService::forcedPayment(array_merge($booking, [
      'organizer_id'     => $organizerId,
      'ticket_type_name' => $booking['ticket_type_name'],
    ]), (int) $this->request->user['id']);

    NotificationService::bookingConfirmed(
      (int)   $booking['user_id'],
      (int)   $booking['id'],
      $booking['event_title'],
      (int)   $booking['event_id'],
      (int)   $booking['quantity'],
      (float) $booking['total_amount']
    );
    // ── END NEW ──
    Response::success([
      'booking_id' => $bookingId,
      'tickets'    => $tickets,
    ], 'Booking force-paid and tickets issued.');
  }
}
