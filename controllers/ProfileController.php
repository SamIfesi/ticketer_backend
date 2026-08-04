<?php

class ProfileController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // ============================================================
  // GET /api/profile
  //
  // FIX #3: organizer_stats no longer reads events.tickets_sold.
  // Uses v_event_sales view for accurate live totals.
  // FIX #6: booking_stats filters deleted_at IS NULL.
  // ============================================================
  public function show(): void
  {
    $userId = $this->request->user['id'];
    $role   = $this->request->user['role'];

    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, email_verified, email_verified_at, created_at
            FROM users WHERE id = ?
        ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
      Response::notFound('User not found.');
    }

    // Booking stats — exclude soft-deleted bookings
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                               AS total_bookings,
                SUM(CASE WHEN payment_status = 'paid'    THEN 1    ELSE 0 END)         AS paid_bookings,
                SUM(CASE WHEN payment_status = 'pending' THEN 1    ELSE 0 END)         AS pending_bookings,
                SUM(CASE WHEN payment_status = 'paid'    THEN total_amount ELSE 0 END) AS total_spent
            FROM bookings
            WHERE user_id = ?
              AND deleted_at IS NULL
        ");
    $stmt->execute([$userId]);
    $user['booking_stats'] = $stmt->fetch();

    // Ticket stats — exclude soft-deleted tickets
    $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                       AS total_tickets,
                SUM(CASE WHEN is_used = 1 THEN 1 ELSE 0 END)  AS used_tickets
            FROM tickets
            WHERE user_id = ?
              AND deleted_at IS NULL
        ");
    $stmt->execute([$userId]);
    $user['ticket_stats'] = $stmt->fetch();

    // Upcoming events
    $stmt = $this->db->prepare("
            SELECT DISTINCT
                e.id,
                e.title,
                e.location,
                e.start_date,
                e.banner_image,
                tt.name AS ticket_type
            FROM tickets t
            JOIN events       e  ON e.id  = t.event_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE t.user_id = ?
              AND e.start_date > NOW()
              AND e.deleted_at IS NULL
              AND b.payment_status = 'paid'
              AND b.deleted_at IS NULL
              AND t.deleted_at IS NULL
            ORDER BY e.start_date ASC
            LIMIT 5
        ");
    $stmt->execute([$userId]);
    $user['upcoming_events'] = $stmt->fetchAll();

    // Organizer-only stats
    // FIX #3: Replaced SUM(tickets_sold) with v_event_sales join
    if (in_array($role, [Constants::ROLE_ORGANIZER, Constants::ROLE_DEV])) {
      $stmt = $this->db->prepare("
                SELECT
                    COUNT(e.id)                                                         AS total_events,
                    COALESCE(SUM(s.tickets_sold), 0)                                   AS total_tickets_sold,
                    COALESCE(SUM(s.total_revenue), 0)                                  AS total_revenue,
                    SUM(CASE WHEN e.status = 'published' THEN 1 ELSE 0 END)            AS live_events,
                    SUM(CASE WHEN e.start_date > NOW()
                             AND e.status = 'published'
                             THEN 1 ELSE 0 END)                                        AS upcoming_events
                FROM events e
                LEFT JOIN v_event_sales s ON s.event_id = e.id
                WHERE e.organizer_id = ?
                  AND e.deleted_at IS NULL
            ");
      $stmt->execute([$userId]);
      $user['organizer_stats'] = $stmt->fetch();
    }

    Response::success(['profile' => $user]);
  }

  // ============================================================
  // PUT /api/profile
  // Protected: logged in
  // Update name and/or avatar
  // ============================================================
  public function update(): void
  {
    $userId = $this->request->user['id'];
    $name   = trim($this->request->input('name', ''));
    $avatar = trim($this->request->input('avatar', ''));
    $errors = [];

    if (!empty($name) && strlen($name) < 2) {
      $errors['name'] = 'Name must be at least 2 characters.';
    }

    if (!empty($avatar) && !filter_var($avatar, FILTER_VALIDATE_URL)) {
      $errors['avatar'] = 'Avatar must be a valid URL.';
    }

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    $this->db->prepare("
            UPDATE users SET
                name   = COALESCE(NULLIF(?, ''), name),
                avatar = COALESCE(NULLIF(?, ''), avatar)
            WHERE id = ?
        ")->execute([$name, $avatar, $userId]);

    $this->logActivity($userId, 'profile_update', 'Profile information updated');

    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, email_verified, created_at FROM users WHERE id = ?
        ');
    $stmt->execute([$userId]);

    Response::success(['user' => $stmt->fetch()], 'Profile updated successfully.');
  }

  // ============================================================
  // POST /api/profile/change-password
  // Protected: logged in
  // Requires current password before setting a new one
  // ============================================================
  public function changePassword(): void
  {
    $userId          = $this->request->user['id'];
    $currentPassword = $this->request->input('current_password', '');
    $newPassword     = $this->request->input('new_password', '');
    $confirmPassword = $this->request->input('confirm_password', '');

    $errors = ValidationHelper::check(
      [
        'current_password' => $currentPassword,
        'new_password'     => $newPassword,
        'confirm_password' => $confirmPassword,
      ],
      [
        'current_password' => 'required',
        'new_password'     => 'required|min:8',
        'confirm_password' => 'required|confirm:new_password',
      ]
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Fetch current password hash
    $stmt = $this->db->prepare('SELECT password_hash, name, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Verify current password is correct
    if (!password_verify($currentPassword, $user['password_hash'])) {
      Response::error('Current password is incorrect.', 400);
    }

    // Make sure new password is different
    if (password_verify($newPassword, $user['password_hash'])) {
      Response::error('New password must be different from your current password.', 400);
    }

    $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
      ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);

    // Queue the notification email instead of sending directly
    QueueService::sendPasswordChanged($user['email'], $user['name']);

    $this->logActivity($userId, 'password_change', 'Password changed successfully');

    Response::success(null, 'Password changed successfully.');
  }

  // ============================================================
  // POST /api/profile/change-email
  // Step 1 — sends OTP to new email
  // ============================================================
  public function requestEmailChange(): void
  {
    $userId   = $this->request->user['id'];
    $newEmail = trim($this->request->input('email', ''));

    $errors = ValidationHelper::check(
      ['email' => $newEmail],
      ['email' => 'required|email']
    );

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Make sure the new email isn't already taken by another account
    $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $stmt->execute([$newEmail, $userId]);
    if ($stmt->fetch()) {
      Response::error('This email address is already associated with another account.', 409);
    }

    // Fetch current user name
    $stmt = $this->db->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    // Invalidate any previous email change OTPs
    $this->db->prepare("
            UPDATE email_verifications SET is_used = 1
            WHERE user_id = ? AND type = 'email_change' AND is_used = 0
        ")->execute([$userId]);

    // Generate OTP and store against NEW email
    $otp       = $this->generateOTP();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));

    $this->db->prepare("
            INSERT INTO email_verifications (user_id, email, otp, type, expires_at)
            VALUES (?, ?, ?, 'email_change', ?)
        ")->execute([$userId, $newEmail, $otp, $expiresAt]);

    // Queue OTP email to the NEW email address
    QueueService::sendOTP($newEmail, $user['name'], $otp, 'email_change');

    Response::success(null, "A verification code has been sent to {$newEmail}. Enter it to confirm the change.");
  }

  // ============================================================
  // POST /api/profile/confirm-email-change
  // Step 2 — verifies OTP and updates email
  // ============================================================
  public function confirmEmailChange(): void
  {
    $userId = $this->request->user['id'];
    $otp    = trim($this->request->input('otp', ''));

    if (empty($otp)) {
      Response::validationError(['otp' => 'OTP is required.']);
    }

    // Find valid OTP
    $stmt = $this->db->prepare("
            SELECT id, email, expires_at FROM email_verifications
            WHERE user_id = ?
              AND otp      = ?
              AND type     = 'email_change'
              AND is_used  = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
    $stmt->execute([$userId, $otp]);
    $record = $stmt->fetch();

    if (!$record) {
      Response::error('Invalid OTP. Please check the code and try again.', 400);
    }

    if (strtotime($record['expires_at']) < time()) {
      Response::error('This OTP has expired. Please request a new email change.', 400);
    }

    // Mark OTP as used
    $this->db->prepare("UPDATE email_verifications SET is_used = 1 WHERE id = ?")
      ->execute([$record['id']]);

    $this->db->prepare("
            UPDATE users SET
                email             = ?,
                email_verified    = 1,
                email_verified_at = NOW()
            WHERE id = ?
        ")->execute([$record['email'], $userId]);

    $this->logActivity($userId, 'email_change', "Email changed to {$record['email']}");

    Response::success(null, 'Email address updated successfully.');
  }

  // ============================================================
  // GET /api/profile/bookings
  //
  // FIX #6: Added deleted_at IS NULL filter on bookings.
  // ============================================================
  public function bookings(): void
  {
    $userId = $this->request->user['id'];
    $status = $this->request->query('status', '');

    $conditions = ['b.user_id = ?', 'b.deleted_at IS NULL'];
    $params     = [$userId];

    if (!empty($status)) {
      $conditions[] = 'b.payment_status = ?';
      $params[]     = $status;
    }

    $where = implode(' AND ', $conditions);

    $stmt = $this->db->prepare("
            SELECT
                b.id,
              b.booking_number,
                b.quantity,
                b.total_amount,
                b.payment_status,
                b.paid_at,
                b.refunded_at,
                b.created_at,
                e.title       AS event_title,
                e.location    AS event_location,
                e.start_date  AS event_start_date,
                e.banner_image,
                tt.name       AS ticket_type,
                COUNT(t.id)   AS tickets_issued
            FROM bookings b
            JOIN events       e  ON e.id  = b.event_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            LEFT JOIN tickets  t  ON t.booking_id = b.id AND t.deleted_at IS NULL
            WHERE {$where}
            GROUP BY b.id, e.title, e.location, e.start_date, e.banner_image, tt.name,
                     b.quantity, b.total_amount, b.payment_status, b.paid_at, b.refunded_at, b.created_at
            ORDER BY b.created_at DESC
        ");
    $stmt->execute($params);

    Response::success(['bookings' => $stmt->fetchAll()]);
  }

  // ============================================================
  // GET /api/profile/tickets
  //
  // FIX #6: Added deleted_at IS NULL on tickets and bookings.
  // Also excludes tickets for soft-deleted events.
  // ============================================================
  public function tickets(): void
  {
    $userId = $this->request->user['id'];
    $filter = $this->request->query('filter', '');

    $conditions = [
      't.user_id = ?',
      "b.payment_status = 'paid'",
      'b.deleted_at IS NULL',
      't.deleted_at IS NULL',
      'e.deleted_at IS NULL',
    ];
    $params = [$userId];

    if ($filter === 'upcoming') {
      $conditions[] = 'e.start_date > NOW()';
    } elseif ($filter === 'past') {
      $conditions[] = 'e.start_date <= NOW()';
    }

    $where = implode(' AND ', $conditions);

    $stmt = $this->db->prepare("
            SELECT
                t.id,
              t.ticket_number,
                t.qr_token,
                t.booking_id,
                t.is_used,
                t.used_at,
                t.created_at,
                b.total_amount,
                b.unit_price,
                e.id          AS event_id,
                e.title       AS event_title,
                e.location    AS event_location,
                e.start_date  AS event_start_date,
                e.banner_image,
                tt.name       AS ticket_type
            FROM tickets t
            JOIN events       e  ON e.id  = t.event_id
            JOIN bookings     b  ON b.id  = t.booking_id
            JOIN ticket_types tt ON tt.id = b.ticket_type_id
            WHERE {$where}
            ORDER BY t.created_at DESC
        ");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

    foreach ($tickets as &$ticket) {
      QRCodeService::generate($ticket['qr_token']);
      $ticket['qr_code_url'] = QRCodeService::getUrl($ticket['qr_token']);
      $ticket['status'] = $ticket['is_used'] ? 'used' : 'valid';
      unset($ticket['qr_token']);
    }

    Response::success(['tickets' => $tickets]);
  }

  // ============================================================
  // GET /api/profile/activity
  // Protected: logged in
  // User's activity log — logins, password changes, etc.
  // ============================================================
  public function activity(): void
  {
    $userId = $this->request->user['id'];
    $limit  = min(50, max(1, (int) $this->request->query('limit', '20')));

    $stmt = $this->db->prepare("
            SELECT id, action, description, ip_address, created_at
            FROM activity_logs
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
    $stmt->execute([$userId, $limit]);

    Response::success(['activity' => $stmt->fetchAll()]);
  }

  // ============================================================
  // PRIVATE HELPERS
  // ============================================================

  private function generateOTP(): string
  {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  }

  private function logActivity(int $userId, string $action, string $description = ''): void
  {
    try {
      $this->db->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, ?, ?, ?)
            ")->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
      error_log('Activity log error: ' . $e->getMessage());
    }
  }
}
