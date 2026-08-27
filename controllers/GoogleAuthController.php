<?php

/**
 * Handles Google OAuth sign-in / sign-up.
 *
 * Flow (ID-token version):
 *   1. React renders Google's own <GoogleLogin> button (@react-oauth/google)
 *   2. Google issues a signed ID token (a JWT) directly to the browser —
 *      no popup.closed polling involved, so this is unaffected by COOP
 *   3. React sends POST /api/auth/callback/google { id_token }
 *   4. PHP verifies the token against Google's tokeninfo endpoint —
 *      this confirms the signature AND expiry were valid, since Google
 *      itself checked them; we still verify `aud` and `iss` ourselves
 *   5. PHP finds-or-creates the user, issues our own JWT, sets the
 *      httpOnly auth cookie exactly as the password-login flow does
 *   6. Returns the same shape as /api/auth/login
 *
 * NOTE on verification approach:
 *   Calling https://oauth2.googleapis.com/tokeninfo is the simplest way to
 *   verify an ID token without a JWT/JWKS library in the project. Google's
 *   own docs note this endpoint is fine for normal use but recommend local
 *   JWKS-based verification for very high request volumes (it does have
 *   informal rate limits). If Google login volume grows significantly,
 *   swap verifyGoogleIdToken() for local RS256 verification against
 *   https://www.googleapis.com/oauth2/v3/certs — the rest of this
 *   controller does not need to change.
 *
 * Route:
 *   POST /api/auth/callback/google   (public — no AuthMiddleware)
 */
class GoogleAuthController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // POST /api/auth/callback/google
  //
  // Body: { id_token: string }
  //
  // Returns same shape as /api/auth/login:
  //   { user, token, email_verified: true }
  public function googleLogin(): void
  {
    $idToken = trim($this->request->input('id_token', ''));

    if (empty($idToken)) {
      Response::validationError(['id_token' => 'Google ID token is required.']);
    }

    // ── Step 1: Verify the ID token ────────────────────────
    $googleUser = $this->verifyGoogleIdToken($idToken);

    if (!$googleUser) {
      Response::error('Could not verify Google account. Please try again.', 401);
    }

    $googleId = $googleUser['sub'];
    $email    = $googleUser['email']   ?? '';
    $name     = $googleUser['name']    ?? '';
    $avatar   = $googleUser['picture'] ?? null;

    if (empty($email)) {
      Response::error('Your Google account must have an email address.', 400);
    }

    // tokeninfo returns email_verified as the string "true"/"false"
    $emailVerified = filter_var($googleUser['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$emailVerified) {
      Response::error('Your Google email address is not verified.', 400);
    }

    // ── Step 2: Find or create user ───────────────────────
    $user = $this->findUserByGoogleId($googleId);

    if (!$user) {
      // Try to link an existing email account
      $user = $this->findUserByEmail($email);
    }

    if ($user) {
      $this->syncGoogleData($user['id'], $googleId, $avatar, $user);
      $user = $this->fetchUserById($user['id']);
    } else {
      $user = $this->createGoogleUser($googleId, $email, $name, $avatar);
    }

    // ── Step 3: Guard against deactivated accounts ────────
    if (isset($user['is_active']) && !$user['is_active']) {
      Response::error('Your account has been deactivated. Please contact support.', 403);
    }

    // ── Step 4: Log + issue JWT ───────────────────────────
    $this->logActivity($user['id'], 'google_login', 'Signed in with Google');

    $token = JWTService::generate($user);
    JWTService::setAuthCookie($token);

    Response::success([
      'user'           => $user,
      'token'          => $token,
      'email_verified' => true,
    ], 'Signed in with Google successfully.');
  }

  // Verify a Google ID token via Google's tokeninfo endpoint.
  // Returns the decoded claims (sub, email, email_verified, name, picture,
  // aud, iss, exp, ...) on success, or null if invalid/unverifiable.
  //
  // Google's tokeninfo endpoint validates the signature and expiry for us —
  // if it returns HTTP 200, those checks already passed. We still must
  // verify `aud` (audience) ourselves: tokeninfo will happily validate a
  // token that was legitimately issued for a DIFFERENT app's client ID,
  // so skipping the aud check would let anyone with any Google Sign-In
  // integration mint a token that logs into YOUR app.
  private function verifyGoogleIdToken(string $idToken): ?array
  {
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 10,
      CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
      error_log('GoogleAuthController: cURL error — ' . $curlError);
      return null;
    }

    // tokeninfo returns non-200 for an invalid, tampered, or expired token
    if ($httpCode !== 200) {
      error_log('GoogleAuthController: tokeninfo returned HTTP ' . $httpCode . ' — ' . $response);
      return null;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($data['sub'])) {
      error_log('GoogleAuthController: invalid tokeninfo response');
      return null;
    }

    // Verify audience — must match OUR Google OAuth client ID
    $expectedAud = Environment::get('GOOGLE_CLIENT_ID');
    if (empty($expectedAud)) {
      error_log('GoogleAuthController: GOOGLE_CLIENT_ID is not set in the environment.');
      return null;
    }
    if (($data['aud'] ?? '') !== $expectedAud) {
      error_log('GoogleAuthController: aud mismatch. Expected ' . $expectedAud . ', got ' . ($data['aud'] ?? 'none'));
      return null;
    }

    // Verify issuer is actually Google
    $issuer = $data['iss'] ?? '';
    if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
      error_log('GoogleAuthController: unexpected issuer — ' . $issuer);
      return null;
    }

    return $data;
  }

  // Find user by Google ID (returning Google users)
  private function findUserByGoogleId(string $googleId): ?array
  {
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, avatar_public_id,
                   google_id, auth_provider, email_verified, is_active, created_at
            FROM users WHERE google_id = ?
        ');
    $stmt->execute([$googleId]);
    return $stmt->fetch() ?: null;
  }

  // Find user by email — to link existing email/password accounts
  private function findUserByEmail(string $email): ?array
  {
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, avatar_public_id,
                   google_id, auth_provider, email_verified, is_active, created_at
            FROM users WHERE email = ?
        ');
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
  }

  // Fetch a clean user row after sync (matches JWT payload shape)
  private function fetchUserById(int $userId): array
  {
    $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, email_verified, created_at
            FROM users WHERE id = ?
        ');
    $stmt->execute([$userId]);
    return $stmt->fetch();
  }

  // Sync Google data onto an existing user account:
  //   - Link google_id if this is their first Google sign-in
  //   - Set avatar from Google if they have no avatar yet
  //   - Mark email as verified (Google already verified it)
  private function syncGoogleData(
    int     $userId,
    string  $googleId,
    ?string $avatar,
    array   $existingUser
  ): void {
    $updates = [];
    $params  = [];

    // Link Google ID + flip auth_provider
    if (empty($existingUser['google_id'])) {
      $updates[] = 'google_id = ?';
      $params[]  = $googleId;
      $updates[] = "auth_provider = 'google'";
    }

    // Use Google avatar if user has no avatar yet
    if (empty($existingUser['avatar']) && !empty($avatar)) {
      $updates[] = 'avatar = ?';
      $params[]  = $avatar;
    }

    // Mark email verified — Google confirmed it
    if (empty($existingUser['email_verified'])) {
      $updates[] = 'email_verified = 1';
      $updates[] = 'email_verified_at = NOW()';
    }

    if (empty($updates)) return;

    $params[] = $userId;
    $this->db->prepare(
      'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?'
    )->execute($params);
  }

  // Create a brand new user from their Google profile.
  //
  // Notes:
  //   - password_hash is a random unusable string
  //     (they sign in via Google; if they ever want email/password
  //     they can use "Forgot password" to set one — that flow exists)
  //   - email_verified = 1 (Google already verified it)
  //   - role = attendee (same as regular registration)
  private function createGoogleUser(
    string  $googleId,
    string  $email,
    string  $name,
    ?string $avatar
  ): array {
    $unusablePasswordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

    // Use email prefix as fallback name if Google didn't provide one
    $displayName = !empty($name) ? $name : explode('@', $email)[0];

    $this->db->prepare('
            INSERT INTO users
                (name, email, password_hash, role, google_id, auth_provider,
                 avatar, email_verified, email_verified_at, is_active)
            VALUES (?, ?, ?, ?, ?, \'google\', ?, 1, NOW(), 1)
        ')->execute([
      $displayName,
      $email,
      $unusablePasswordHash,
      Constants::ROLE_ATTENDEE,
      $googleId,
      $avatar,
    ]);

    $userId = $this->db->lastInsertId();

    $this->logActivity($userId, 'register', 'Account created via Google OAuth');

    // Send a welcome email after successfull register
    QueueService::sendWelcome($email, $displayName);

    return $this->fetchUserById($userId);
  }

  private function logActivity(int $userId, string $action, string $description = ''): void
  {
    try {
      $this->db->prepare('
                INSERT INTO activity_logs (user_id, action, description, ip_address)
                VALUES (?, ?, ?, ?)
            ')->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {
      error_log('GoogleAuthController activity log error: ' . $e->getMessage());
    }
  }
}
