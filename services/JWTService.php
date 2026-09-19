<?php

class JWTService
{
  /**
   * Create a JWT token for a user
   * Stores id, email, role, and expiry inside the token
   */
  public static function generate(array $user): string
  {
    $secret = Environment::get('JWT_SECRET');
    $expiry = (int) Environment::get('JWT_EXPIRY', '86400'); // default 24 hours

    // Header
    $header = self::base64UrlEncode(json_encode([
      'alg' => 'HS256',
      'typ' => 'JWT',
    ]));

    // Payload — what gets stored in the token
    //
    // 'tv' (token version) is stamped from users.token_version at issue
    // time. AuthMiddleware compares it against the current DB value on
    // every request — bumping the column (e.g. on password change)
    // invalidates every token issued before the bump, without needing
    // a token blacklist.
    $payload = self::base64UrlEncode(json_encode([
      'id'    => $user['id'],
      'email' => $user['email'],
      'role'  => $user['role'],
      'name'  => $user['name'],
      'tv'    => (int) ($user['token_version'] ?? 0),
      'iat'   => time(),               // issued at
      'exp'   => time() + $expiry,     // expiry
    ]));

    // Signature — proves the token hasn't been tampered with
    $signature = self::base64UrlEncode(
      hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
    );

    return "{$header}.{$payload}.{$signature}";
  }

  /**
   * Verify a token and return its payload
   * Returns null if invalid or expired
   */
  public static function verify(string $token): ?array
  {
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
      return null;
    }

    [$header, $payload, $signature] = $parts;

    // Recompute the signature and compare
    $secret   = Environment::get('JWT_SECRET');
    $expected = self::base64UrlEncode(
      hash_hmac('sha256', "{$header}.{$payload}", $secret, true)
    );

    // Signature mismatch — token was tampered with
    if (!hash_equals($expected, $signature)) {
      return null;
    }

    $data = json_decode(self::base64UrlDecode($payload), true);

    // Token has expired
    if (!$data || $data['exp'] < time()) {
      return null;
    }

    return $data;
  }

  /**
   * Set the auth cookie so the JWT is shared across every
   * ticketer.website subdomain (ticketer.website, app.ticketer.website, etc).
   *
   * HttpOnly  — JS can't read it, blocks XSS token theft (an upgrade
   *             over the old localStorage approach).
   * Secure    — HTTPS only.
   * SameSite  — Lax is enough since subdomains are "same-site"; this
   *             still blocks the cookie from being sent on cross-site
   *             requests initiated by other domains.
   * Domain    — leading dot makes it valid for the apex + all subdomains.
   *
   * Falls back to no Domain attribute on localhost, since
   * ".localhost" isn't a valid cookie domain and local dev doesn't
   * need cross-subdomain sharing anyway.
   */
  public static function setAuthCookie(string $token): void
  {
    $expiry     = (int) Environment::get('JWT_EXPIRY', '86400');
    $appEnv     = Environment::get('APP_ENV', 'production');
    $isLocal    = $appEnv === 'development';
    $cookieHost = Environment::get('COOKIE_DOMAIN', '.ticketer.website');

    $cookieOptions = [
      'expires'  => time() + $expiry,
      'path'     => '/',
      'secure'   => !$isLocal,
      'httponly' => true,
      'samesite' => 'Lax',
    ];

    // ".localhost" isn't a valid cookie Domain, and a mismatched Domain
    // makes the browser silently drop the Set-Cookie header entirely —
    // so on local dev, omit the attribute rather than passing it through.
    // With no Domain, the browser defaults it to the exact host that set
    // the cookie, which is exactly what local dev needs anyway.
    if (!$isLocal) {
      $cookieOptions['domain'] = $cookieHost;
    }

    setcookie('token', $token, $cookieOptions);
  }

  /**
   * Clear the auth cookie on logout. Attributes (path, domain,
   * secure, samesite) must match what was used to set it, or the
   * browser will treat it as a different cookie and not clear it.
   */
  public static function clearAuthCookie(): void
  {
    $appEnv     = Environment::get('APP_ENV', 'production');
    $isLocal    = $appEnv === 'development';
    $cookieHost = Environment::get('COOKIE_DOMAIN', '.ticketer.website');

    $cookieOptions = [
      'expires'  => time() - 3600,
      'path'     => '/',
      'secure'   => !$isLocal,
      'httponly' => true,
      'samesite' => 'Lax',
    ];

    if (!$isLocal) {
      $cookieOptions['domain'] = $cookieHost;
    }

    setcookie('token', '', $cookieOptions);
  }

  private static function base64UrlEncode(string $data): string
  {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private static function base64UrlDecode(string $data): string
  {
    return base64_decode(strtr($data, '-_', '+/'));
  }
}
