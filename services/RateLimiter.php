<?php

/**
 * Fixed-window rate limiter, backed by Redis INCR.
 *
 * How it works: the first request for a given key in a window creates a
 * counter at 1 with a TTL equal to the window length; every request after
 * that just increments it. Once the counter passes the limit, requests
 * are rejected until the TTL expires and the key disappears — at which
 * point the next request starts a fresh window.
 *
 * This is intentionally the simple version (not a sliding-window log),
 * which means someone can burst right at a window boundary — request the
 * limit at 0:59, then the limit again at 1:00. For login/register/reset
 * abuse protection that's a fine tradeoff for the simplicity.
 *
 * FAIL OPEN: if Redis is unreachable, allow() returns true (don't block).
 * A rate limiter that fails closed would mean a Redis outage locks
 * everyone out of login — much worse than a few minutes of unlimited
 * attempts.
 */
class RateLimiter
{
  private const PREFIX = 'ratelimit:';

  /**
   * @param string $key        Unique bucket, e.g. "login:" . $request->ip()
   * @param int    $maxAttempts Attempts allowed per window
   * @param int    $windowSeconds Window length in seconds
   * @return array{allowed: bool, remaining: int, retry_after: int}
   */
  public static function attempt(string $key, int $maxAttempts, int $windowSeconds): array
  {
    $redis = RedisService::connect();

    if (!$redis) {
      // Fail open — see class docblock.
      return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0];
    }

    $redisKey = self::PREFIX . $key;

    try {
      $count = $redis->incr($redisKey);

      if ($count === 1) {
        // First hit in this window — start the TTL clock.
        $redis->expire($redisKey, $windowSeconds);
      }

      $ttl = $redis->ttl($redisKey);
      $retryAfter = $ttl > 0 ? $ttl : $windowSeconds;

      return [
        'allowed'     => $count <= $maxAttempts,
        'remaining'   => max(0, $maxAttempts - $count),
        'retry_after' => $retryAfter,
      ];
    } catch (\Throwable $e) {
      error_log('RateLimiter::attempt failed — ' . $e->getMessage());
      return ['allowed' => true, 'remaining' => $maxAttempts, 'retry_after' => 0];
    }
  }

  /**
   * Call on a SUCCESSFUL login/verification so a legitimate user isn't
   * left half-consumed against the limit by their own earlier typos.
   * Not called on register/reset — those stay limited regardless of
   * outcome since a "success" there doesn't prove the requester is who
   * they say they are the way a correct password does.
   */
  public static function clear(string $key): void
  {
    $redis = RedisService::connect();
    if (!$redis) {
      return;
    }

    try {
      $redis->del(self::PREFIX . $key);
    } catch (\Throwable $e) {
      error_log('RateLimiter::clear failed — ' . $e->getMessage());
    }
  }
}
