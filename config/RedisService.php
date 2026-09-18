<?php

/**
 * Thin wrapper around the phpredis extension (the `Redis` class).
 *
 * Accepts either:
 *   - REDIS_URL   — e.g. redis://default:password@host:6379
 *                   (this is what Railway's Redis plugin gives you)
 *   - or REDIS_HOST / REDIS_PORT / REDIS_PASSWORD separately, for local dev
 *
 * Connection failures are caught, not thrown — every call site that uses
 * this treats "Redis unavailable" as a cache miss and falls back to the
 * database, so a Redis outage degrades performance, not availability.
 */
class RedisService
{
  private static ?Redis $instance = null;
  private static bool $connectionFailed = false;

  private function __construct() {}

  public static function connect(): ?Redis
  {
    if (self::$connectionFailed) {
      return null;
    }

    if (self::$instance !== null) {
      return self::$instance;
    }

    if (!extension_loaded('redis')) {
      error_log('RedisService: the redis PHP extension is not installed — skipping cache.');
      self::$connectionFailed = true;
      return null;
    }

    [$host, $port, $password] = self::resolveConnectionParams();

    try {
      $redis = new Redis();
      $redis->connect($host, $port, 2.0); // 2s timeout — fail fast, don't hang a request

      if ($password !== null && $password !== '') {
        $redis->auth($password);
      }

      self::$instance = $redis;
      return $redis;
    } catch (\Throwable $e) {
      error_log('RedisService: connection failed — ' . $e->getMessage());
      self::$connectionFailed = true;
      return null;
    }
  }

  /** @return array{0: string, 1: int, 2: ?string} [host, port, password] */
  private static function resolveConnectionParams(): array
  {
    $url = Environment::get('REDIS_URL', '');

    if ($url !== '') {
      $parts = parse_url($url);
      return [
        $parts['host'] ?? '127.0.0.1',
        (int) ($parts['port'] ?? 6379),
        $parts['pass'] ?? null,
      ];
    }

    return [
      Environment::get('REDIS_HOST', '127.0.0.1'),
      (int) Environment::get('REDIS_PORT', '6379'),
      Environment::get('REDIS_PASSWORD', '') ?: null,
    ];
  }
}
