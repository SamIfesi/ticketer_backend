<?php

/**
 * Caches users.token_version in Redis so AuthMiddleware doesn't hit MySQL
 * on every single authenticated request.
 *
 * This is a WRITE-THROUGH cache: whoever bumps token_version (password
 * change, password reset) calls set() with the new value immediately, so
 * other devices are invalidated the instant the DB write happens — not
 * whenever a TTL happens to expire. The TTL below is just a safety net in
 * case a value is ever written to the DB without going through set()
 * (e.g. a manual SQL update).
 */
class TokenVersionCache
{
  private const PREFIX = 'token_version:';
  private const TTL_SECONDS = 300; // 5 minutes

  public static function get(int $userId): ?int
  {
    $redis = RedisService::connect();
    if (!$redis) {
      return null; // Redis unavailable — caller should fall back to the DB
    }

    try {
      $value = $redis->get(self::PREFIX . $userId);
      return $value === false ? null : (int) $value;
    } catch (\Throwable $e) {
      error_log('TokenVersionCache::get failed — ' . $e->getMessage());
      return null;
    }
  }

  public static function set(int $userId, int $version): void
  {
    $redis = RedisService::connect();
    if (!$redis) {
      return; // No cache to write to — the next read will fall back to the DB
    }

    try {
      $redis->setex(self::PREFIX . $userId, self::TTL_SECONDS, $version);
    } catch (\Throwable $e) {
      error_log('TokenVersionCache::set failed — ' . $e->getMessage());
    }
  }

  public static function forget(int $userId): void
  {
    $redis = RedisService::connect();
    if (!$redis) {
      return;
    }

    try {
      $redis->del(self::PREFIX . $userId);
    } catch (\Throwable $e) {
      error_log('TokenVersionCache::forget failed — ' . $e->getMessage());
    }
  }
}
