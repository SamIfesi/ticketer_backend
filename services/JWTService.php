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
        $payload = self::base64UrlEncode(json_encode([
            'id'    => $user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'name'  => $user['name'],
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
  
      setcookie('token', $token, [
        'expires'  => time() + $expiry,
        'path'     => '/',
        'domain'   => $cookieHost,
        'secure'   => !$isLocal,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
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
  
      setcookie('token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => $cookieHost,
        'secure'   => !$isLocal,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
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