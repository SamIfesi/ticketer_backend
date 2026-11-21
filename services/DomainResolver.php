<?php

/**
 * Works out which of our domains a request belongs to, so cookies and
  * links follow the domain the user is actually on:
   *   *.ticketer.website  ->  .ticketer.website
    *   *.ticketer.com.ng   ->  .ticketer.com.ng
     * Anything not recognised falls back to the values in .env.
      */
class DomainResolver
{
/** Registrable domains we serve. Add new ones here. */
    public const BASE_DOMAINS = ['ticketer.com.ng', 'ticketer.website'];

    public static function baseFromHost(?string $host): ?string
    {
     $host = strtolower(trim((string) $host));
     $host = explode(':', $host)[0];

     foreach (self::BASE_DOMAINS as $base) {
                                if ($host === $base || str_ends_with($host, '.' . $base)) {
                                        return $base;
                                              }
                                                  }

                                                      return null;
                                                        }

                                                          /** Domain of the host this request was sent to (the API host). */
                                                            public static function requestBase(): ?string
                                                              {
                                                                  return self::baseFromHost($_SERVER['HTTP_HOST'] ?? '');
                                                                    }

                                                                      /** Domain of the site the browser is on (the Origin header). */
                                                                        public static function originBase(): ?string
                                                                          {
                                                                              $host = parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST);
                                                                                  return is_string($host) ? self::baseFromHost($host) : null;
                                                                                    }

                                                                                      /** Cookie Domain attribute. Must match the host serving the response. */
                                                                                        public static function cookieDomain(): string
                                                                                          {
                                                                                              $base = self::requestBase();

                                                                                                  if ($base !== null) {
                                                                                                        return '.' . $base;
                                                                                                            }

                                                                                                                return Environment::get('COOKIE_DOMAIN', '.' . self::BASE_DOMAINS[1]);
                                                                                                                  }

                                                                                                                    /** Frontend URL for links built during a request. */
                                                                                                                      public static function frontendUrl(): string
                                                                                                                        {
                                                                                                                            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

                                                                                                                                if (self::originBase() !== null && preg_match('#^https://[a-z0-9.-]+$#i', $origin)) {
                                                                                                                                      return rtrim($origin, '/');
                                                                                                                                          }

                                                                                                                                              $base = self::requestBase();
                                                                                                                                                  if ($base !== null) {
                                                                                                                                                        return 'https://app.' . $base;
                                                                                                                                                            }

                                                                                                                                                                return rtrim(Environment::get('FRONTEND_URL', 'https://app.ticketer.website'), '/');
                                                                                                                                                                  }
                                                                                                                                                                  }