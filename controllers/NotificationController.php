<?php

/**
 * NotificationController
 *
 * Handles in-app notifications for the logged-in user.
 *
 * Routes (in routes/notifications.php):
 *   GET    /api/notifications           → index()
 *   GET    /api/notifications/unread    → unreadCount()
 *   PUT    /api/notifications/read-all  → markAllRead()
 *   PUT    /api/notifications/:id/read  → markRead()
 *   DELETE /api/notifications/:id       → destroy()
 */
class NotificationController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/notifications
  // Paginated list of notifications for the logged-in user.
  // Optional ?unread=1 to filter unread only.
  // Also returns total unread count so the bell badge
  // stays accurate without a second request.
  public function index(): void
  {
    $userId     = $this->request->user['id'];
    $page       = max(1, (int) $this->request->query('page', '1'));
    $limit      = min(50, max(1, (int) $this->request->query('limit', '20')));
    $offset     = ($page - 1) * $limit;
    $unreadOnly = $this->request->query('unread', '') === '1';

    $conditions = ['user_id = ?'];
    $params     = [$userId];

    if ($unreadOnly) {
      $conditions[] = 'is_read = 0';
    }

    $where = implode(' AND ', $conditions);

    $countStmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $this->db->prepare("
            SELECT
                id,
                type,
                title,
                body,
                action_url,
                related_id,
                related_type,
                is_read,
                read_at,
                created_at
            FROM notifications
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    // Cast is_read to boolean so frontend gets true/false
    foreach ($notifications as &$n) {
      $n['is_read'] = (bool) $n['is_read'];
    }

    // Return unread count alongside the list
    $unreadStmt = $this->db->prepare(
      "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $unreadStmt->execute([$userId]);

    Response::success([
      'notifications' => $notifications,
      'unread_count'  => (int) $unreadStmt->fetchColumn(),
      'pagination'    => [
        'total'       => $total,
        'page'        => $page,
        'limit'       => $limit,
        'total_pages' => (int) ceil($total / $limit),
      ],
    ]);
  }

  // GET /api/notifications/unread
  // Lightweight endpoint — just the unread count for the bell badge.
  // Frontend can poll this every 30 seconds cheaply.
  public function unreadCount(): void
  {
    $stmt = $this->db->prepare(
      "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $stmt->execute([$this->request->user['id']]);

    Response::success(['unread_count' => (int) $stmt->fetchColumn()]);
  }

  // PUT /api/notifications/read-all
  // Mark ALL of the user's unread notifications as read at once.
  // Called when user opens the notification panel.
  public function markAllRead(): void
  {
    $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
    $stmt->execute([$this->request->user['id']]);

    Response::success(
      ['marked_read' => $stmt->rowCount()],
      'All notifications marked as read.'
    );
  }

  // PUT /api/notifications/:id/read
  // Mark a single notification as read.
  // Users can only mark their own notifications.
  public function markRead(array $params): void
  {
    $notifId = (int) $params['id'];
    $userId  = $this->request->user['id'];

    $stmt = $this->db->prepare("SELECT id, user_id FROM notifications WHERE id = ?");
    $stmt->execute([$notifId]);
    $notif = $stmt->fetch();

    if (!$notif) {
      Response::notFound('Notification not found.');
    }

    if ((int) $notif['user_id'] !== $userId) {
      Response::forbidden('This notification does not belong to you.');
    }

    $this->db->prepare(
      "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?"
    )->execute([$notifId]);

    Response::success(null, 'Notification marked as read.');
  }

  // DELETE /api/notifications/:id
  // Delete a single notification.
  // Users can only delete their own notifications.
  public function destroy(array $params): void
  {
    $notifId = (int) $params['id'];
    $userId  = $this->request->user['id'];

    $stmt = $this->db->prepare("SELECT id, user_id FROM notifications WHERE id = ?");
    $stmt->execute([$notifId]);
    $notif = $stmt->fetch();

    if (!$notif) {
      Response::notFound('Notification not found.');
    }

    if ((int) $notif['user_id'] !== $userId) {
      Response::forbidden('This notification does not belong to you.');
    }

    $this->db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$notifId]);

    Response::success(null, 'Notification deleted.');
  }
}
