<?php

class AdminController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/admin/users
  public function users(): void
  {
    $page   = max(1, (int) $this->request->query('page', '1'));
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset = ($page - 1) * $limit;
    $search = trim($this->request->query('search', ''));
    $role   = trim($this->request->query('role', ''));

    $conditions = ["role != 'dev'"];
    $params     = [];

    if (!empty($search)) {
      $conditions[] = '(name LIKE ? OR email LIKE ?)';
      $params[]     = "%{$search}%";
      $params[]     = "%{$search}%";
    }

    if (!empty($role) && in_array($role, Constants::PUBLIC_ROLES, true)) {
      $conditions[] = 'role = ?';
      $params[]     = $role;
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                id,
                name,
                email,
                role,
                is_active,
                avatar,
                created_at
            FROM users
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);

    Response::success([
      'users'      => $stmt->fetchAll(),
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/admin/users/:id
  //
  // FIX #2: organizer_summary no longer reads events.tickets_sold.
  // Uses v_event_sales view to get accurate live totals.
  public function showUser(array $params): void
  {
    $userId = (int) $params['id'];

    $stmt = $this->db->prepare("
            SELECT id, name, email, role, is_active, avatar, created_at
            FROM users
            WHERE id = ? AND role != 'dev'
        ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    // Booking summary
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                      AS total_bookings,
                SUM(total_amount)                             AS total_spent,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_bookings
            FROM bookings
            WHERE user_id = ? AND deleted_at IS NULL
        ");
    $stmt->execute([$userId]);
    $user['booking_summary'] = $stmt->fetch();

    // Organizer summary — join v_event_sales for accurate ticket counts
    if ($user['role'] === Constants::ROLE_ORGANIZER) {
      $stmt = $this->db->prepare("
                SELECT
                    COUNT(e.id)                                                        AS total_events,
                    COALESCE(SUM(s.tickets_sold), 0)                                   AS total_tickets_sold,
                    SUM(CASE WHEN e.status = 'published' THEN 1 ELSE 0 END)            AS live_events
                FROM events e
                LEFT JOIN v_event_sales s ON s.event_id = e.id
                WHERE e.organizer_id = ?
                  AND e.deleted_at IS NULL
            ");
      $stmt->execute([$userId]);
      $user['organizer_summary'] = $stmt->fetch();
    }

    Response::success(['user' => $user]);
  }

  // PUT /api/admin/users/:id/role
  public function updateRole(array $params): void
  {
    $targetId = (int) $params['id'];
    $newRole  = trim($this->request->input('role', ''));

    if (!in_array($newRole, Constants::PUBLIC_ROLES, true)) {
      Response::validationError([
        'role' => 'Invalid role. Must be one of: ' . implode(', ', Constants::PUBLIC_ROLES),
      ]);
    }

    $stmt = $this->db->prepare("SELECT id, role FROM users WHERE id = ? AND role != 'dev'");
    $stmt->execute([$targetId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    if ($targetId === (int) $this->request->user['id']) {
      Response::error('You cannot change your own role.', 400);
    }

    $this->db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);

    // ── NEW ──
    NotificationService::roleChanged($targetId, $newRole);
    // ── END NEW ──

    Response::success(null, "User role updated to '{$newRole}' successfully.");
  }

  // PUT /api/admin/users/:id/status
  public function updateStatus(array $params): void
  {
    $targetId = (int) $params['id'];
    $isActive = (int) $this->request->input('is_active', 1);

    $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? AND role != 'dev'");
    $stmt->execute([$targetId]);

    if (!$stmt->fetch()) {
      Response::notFound('User not found.');
    }

    if ($targetId === (int) $this->request->user['id']) {
      Response::error('You cannot deactivate your own account.', 400);
    }

    $this->db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$isActive ? 1 : 0, $targetId]);

    // ── NEW ──
    if (!$isActive) {
      NotificationService::accountDeactivated($targetId);
    }
    // ── END NEW ──

    Response::success(null, $isActive ? 'User account activated.' : 'User account deactivated.');
  }

  // GET /api/admin/events
  //
  // FIX #3: Replaced e.tickets_sold column (removed in v2 schema)
  // with a LEFT JOIN on v_event_sales to get live accurate counts.
  // Also filters out soft-deleted events by default.
  public function events(): void
  {
    $page          = max(1, (int) $this->request->query('page', '1'));
    $limit         = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset        = ($page - 1) * $limit;
    $status        = trim($this->request->query('status', ''));
    $showDeleted   = $this->request->query('show_deleted', '0') === '1';

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($status)) {
      $conditions[] = 'e.status = ?';
      $params[]     = $status;
    }

    // Hide soft-deleted events unless admin explicitly requests them
    if (!$showDeleted) {
      $conditions[] = 'e.deleted_at IS NULL';
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM events e WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.banner_image,
                e.slug,
                e.status,
                e.start_date,
                e.total_tickets,
                e.deleted_at,
                COALESCE(s.tickets_sold, 0)     AS tickets_sold,
                COALESCE(s.tickets_available, 0) AS tickets_available,
                COALESCE(s.total_revenue, 0)     AS total_revenue,
                e.created_at,
                u.name  AS organizer_name,
                u.email AS organizer_email,
                c.name  AS category_name
            FROM events e
            JOIN users u ON u.id = e.organizer_id
            LEFT JOIN categories c     ON c.id    = e.category_id
            LEFT JOIN v_event_sales s  ON s.event_id = e.id
            WHERE {$where}
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);

    Response::success([
      'events'     => $stmt->fetchAll(),
      'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // PUT /api/admin/events/:id/status
  //
  // FIX #5: Added EVENT_DELETED to valid statuses.
  // When admin sets status to 'deleted', also stamps deleted_at
  // so the soft-delete trigger fires and logs to activity_logs.
  public function updateEventStatus(array $params): void
  {
    $eventId   = (int) $params['id'];
    $newStatus = trim($this->request->input('status', ''));

    $validStatuses = [
      Constants::EVENT_DRAFT,
      Constants::EVENT_PUBLISHED,
      Constants::EVENT_CANCELLED,
      Constants::EVENT_COMPLETED,
      Constants::EVENT_DELETED,   // FIX #5
    ];

    if (!in_array($newStatus, $validStatuses, true)) {
      Response::validationError(['status' => 'Invalid status value.']);
    }

    $stmt = $this->db->prepare('SELECT id FROM events WHERE id = ?');
    $stmt->execute([$eventId]);

    if (!$stmt->fetch()) {
      Response::notFound('Event not found.');
    }

    if ($newStatus === Constants::EVENT_DELETED) {
      // Stamp deleted_at so the trigger logs it to activity_logs
      $this->db->prepare("
                UPDATE events SET status = 'deleted', deleted_at = NOW() WHERE id = ?
            ")->execute([$eventId]);
    } else {
      // For any other status change, clear deleted_at in case it was previously deleted
      $this->db->prepare("
                UPDATE events SET status = ?, deleted_at = NULL WHERE id = ?
            ")->execute([$newStatus, $eventId]);
    }

    // ── NEW ──
    if (in_array($newStatus, [Constants::EVENT_CANCELLED, Constants::EVENT_DELETED], true)) {
      $evtStmt = $this->db->prepare("SELECT title FROM events WHERE id = ?");
      $evtStmt->execute([$eventId]);
      $evtTitle = $evtStmt->fetchColumn();
 
      NotificationService::notifyEventAttendees(
        $this->db,
        $eventId,
        'event_cancelled',
        "Event Cancelled - {$evtTitle}",
        "{$evtTitle} has been cancelled. Please contact support for your refund",
        "/my-bookings"
      );
      PayoutService::cancelPayout($eventId);
    }
    // ── END NEW ──

    Response::success(null, "Event status updated to '{$newStatus}'.");
  }

  // GET /api/admin/stats
  //
  // FIX #3: Removed SUM(tickets_sold) from events query.
  // Ticket counts now come from bookings directly (already correct here).
  // Added deleted event count.
  // FIX #6: bookings stats filter deleted_at IS NULL.
  public function stats(): void
  {
    // Users
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                                AS total_users,
                SUM(CASE WHEN role = 'attendee'  THEN 1 ELSE 0 END)                   AS attendees,
                SUM(CASE WHEN role = 'organizer' THEN 1 ELSE 0 END)                   AS organizers,
                SUM(CASE WHEN role = 'admin'     THEN 1 ELSE 0 END)                   AS admins,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          AND role != 'dev' THEN 1 ELSE 0 END)                        AS new_this_month
            FROM users
            WHERE role != 'dev'
        ");
    $stmt->execute();
    $userStats = $stmt->fetch();

    // Events — includes deleted count for admin awareness
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                              AS total_events,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END)                AS published,
                SUM(CASE WHEN status = 'draft'     THEN 1 ELSE 0 END)                AS drafts,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END)                AS cancelled,
                SUM(CASE WHEN status = 'deleted'   THEN 1 ELSE 0 END)                AS deleted,
                SUM(CASE WHEN start_date >= NOW() AND status = 'published'
                          THEN 1 ELSE 0 END)                                          AS upcoming
            FROM events
        ");
    $stmt->execute();
    $eventStats = $stmt->fetch();

    // Bookings and revenue — exclude soft-deleted bookings
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                                   AS total_bookings,
                SUM(CASE WHEN payment_status = 'paid'     THEN 1 ELSE 0 END)              AS paid_bookings,
                SUM(CASE WHEN payment_status = 'pending'  THEN 1 ELSE 0 END)              AS pending_bookings,
                SUM(CASE WHEN payment_status = 'refunded' THEN 1 ELSE 0 END)              AS refunded_bookings,
                SUM(CASE WHEN payment_status = 'paid'     THEN total_amount ELSE 0 END)   AS total_revenue
            FROM bookings
            WHERE deleted_at IS NULL
        ");
    $stmt->execute();
    $bookingStats = $stmt->fetch();

    // Tickets
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                      AS total_tickets,
                SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END) AS checked_in
            FROM tickets
            WHERE deleted_at IS NULL
        ");
    $stmt->execute();
    $ticketStats = $stmt->fetch();

    // Recent 7-day activity
    $stmt = $this->db->prepare("
            SELECT
                DATE(created_at)                                                            AS date,
                COUNT(*)                                                                    AS bookings,
                SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END)        AS revenue
            FROM bookings
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND deleted_at IS NULL
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
    $stmt->execute();
    $recentActivity = $stmt->fetchAll();

    Response::success([
      'users'           => $userStats,
      'events'          => $eventStats,
      'bookings'        => $bookingStats,
      'tickets'         => $ticketStats,
      'recent_activity' => $recentActivity,
    ]);
  }
}
