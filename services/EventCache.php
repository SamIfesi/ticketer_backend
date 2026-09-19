<?php

/**
 * Caches the two hottest read paths on the platform: the events listing
 * (GET /api/events) and a single event's detail page (GET /api/events/:id).
 *
 * Two different invalidation strategies are used, on purpose:
 *
 *   - DETAIL pages are cached per event and actively invalidated whenever
 *     that event is written to (created/updated/cancelled, or a booking
 *     changes its ticket counts). Short TTL as a safety net only.
 *
 *   - LISTING pages are cached per filter combination (search/category/
 *     page/etc). There's no practical way to enumerate and delete every
 *     combination a write might affect, so instead we use a version
 *     number: every listing cache key embeds "v{N}", and writing an event
 *     bumps N. Bumping N doesn't delete anything — it just means every
 *     previously-cached key stops being referenced and quietly expires
 *     via its own TTL. New reads after a write get a new key (cache
 *     miss), old keys are simply never looked up again.
 *
 * Tradeoff worth knowing: ticket availability changes on every booking,
 * but bumping the list version on every single sale would defeat the
 * cache exactly when high-traffic events need it most. So listing pages
 * are NOT bumped on booking — only on event create/update/cancel. Ticket
 * counts shown while *browsing* can lag by up to LIST_TTL_SECONDS. This
 * is safe because BookingController checks real availability with a
 * row lock (FOR UPDATE) at actual purchase time — the cache only affects
 * what's displayed, never what's sold. Detail pages ARE invalidated on
 * booking, since that's the page someone's about to buy from.
 */
class EventCache
{
  private const DETAIL_PREFIX     = 'event:detail:';
  private const DETAIL_TTL        = 300; // 5 min safety net — actively invalidated on writes
  private const LIST_PREFIX       = 'events:list:';
  private const LIST_VERSION_KEY  = 'events:list:version';
  private const LIST_TTL          = 45;  // how stale a browsing page can be

  // ── Detail page (by id or slug — both are valid lookup identifiers) ──

  public static function rememberDetail(string $identifier, callable $resolver): ?array
  {
    $redis = RedisService::connect();
    $key   = self::DETAIL_PREFIX . $identifier;

    if ($redis) {
      try {
        $cached = $redis->get($key);
        if ($cached !== false) {
          return json_decode($cached, true);
        }
      } catch (\Throwable $e) {
        error_log('EventCache::rememberDetail read failed — ' . $e->getMessage());
      }
    }

    $event = $resolver();

    if ($redis && $event !== null) {
      try {
        $redis->setex($key, self::DETAIL_TTL, json_encode($event));
      } catch (\Throwable $e) {
        error_log('EventCache::rememberDetail write failed — ' . $e->getMessage());
      }
    }

    return $event;
  }

  /**
   * Call after any write that changes what an event's detail page shows:
   * event create/update/cancel, or a booking that moves quantity_sold.
   * Pass both the id and slug when you have them — an event is cached
   * under whichever identifier it was looked up by, so either could have
   * a stale entry.
   */
  public static function forgetDetail(int|string ...$identifiers): void
  {
    $redis = RedisService::connect();
    if (!$redis) {
      return;
    }

    try {
      foreach (array_filter($identifiers) as $identifier) {
        $redis->del(self::DETAIL_PREFIX . $identifier);
      }
    } catch (\Throwable $e) {
      error_log('EventCache::forgetDetail failed — ' . $e->getMessage());
    }
  }

  // ── Listing page (versioned — see class docblock) ──

  public static function rememberList(array $filters, callable $resolver): array
  {
    $redis = RedisService::connect();
    $key   = self::listKey($filters, $redis);

    if ($redis && $key) {
      try {
        $cached = $redis->get($key);
        if ($cached !== false) {
          return json_decode($cached, true);
        }
      } catch (\Throwable $e) {
        error_log('EventCache::rememberList read failed — ' . $e->getMessage());
      }
    }

    $result = $resolver();

    if ($redis && $key) {
      try {
        $redis->setex($key, self::LIST_TTL, json_encode($result));
      } catch (\Throwable $e) {
        error_log('EventCache::rememberList write failed — ' . $e->getMessage());
      }
    }

    return $result;
  }

  /** Call after any event create/update/cancel. */
  public static function bumpListVersion(): void
  {
    $redis = RedisService::connect();
    if (!$redis) {
      return;
    }

    try {
      $redis->incr(self::LIST_VERSION_KEY);
    } catch (\Throwable $e) {
      error_log('EventCache::bumpListVersion failed — ' . $e->getMessage());
    }
  }

  private static function listKey(array $filters, ?Redis $redis): ?string
  {
    if (!$redis) {
      return null;
    }

    try {
      $version = $redis->get(self::LIST_VERSION_KEY);
      if ($version === false) {
        $version = 1;
        $redis->setex(self::LIST_VERSION_KEY, self::LIST_TTL * 100, $version);
      }
    } catch (\Throwable $e) {
      error_log('EventCache::listKey version read failed — ' . $e->getMessage());
      return null;
    }

    // Stable regardless of key order — ksort before hashing.
    ksort($filters);
    return self::LIST_PREFIX . 'v' . $version . ':' . md5(serialize($filters));
  }
}
