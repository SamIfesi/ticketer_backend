<?php

class AuthMiddleware
{
  /**
   * Called before every protected route
   * Reads the JWT from Authorization header, verifies it,
   * and attaches the user payload to $request->user
   */
  public function handle(Request $request): void
  {
    $token = $request->bearerToken();

    if (!$token) {
      Response::unauthorized('No token provided. Please log in.');
    }

    $payload = JWTService::verify($token);

    if (!$payload) {
      Response::unauthorized('Invalid or expired token. Please log in again.');
    }

    // Reject tokens issued before the user's last "log out other devices"
    // event (password change, etc). The JWT signature alone can't express
    // this — it's stateless — so we check the token's stamped version
    // against the current value, read from Redis when available and
    // falling back to the DB on a cache miss or if Redis is down.
    $currentVersion = $this->currentTokenVersion((int) $payload['id']);

    if ($currentVersion === null || (int) ($payload['tv'] ?? -1) !== $currentVersion) {
      Response::unauthorized('Your session has expired. Please log in again.');
    }

    // Attach decoded user data to the request so controllers can use it
    // e.g. $request->user['id'], $request->user['role']
    $request->user = $payload;
  }

  private function currentTokenVersion(int $userId): ?int
  {
    $cached = TokenVersionCache::get($userId);
    if ($cached !== null) {
      return $cached;
    }

    $stmt = Database::connect()->prepare('SELECT token_version FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
      return null;
    }

    $version = (int) $row['token_version'];
    TokenVersionCache::set($userId, $version);

    return $version;
  }
}
