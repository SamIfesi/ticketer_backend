<?php

class SitemapController
{
  // Static public routes worth letting Google index.
  // Keep in sync with the public (non-auth, non-role-gated) routes
  // in src/Routes.jsx. Auth pages, onboarding, and anything behind
  // ProtectedRoute/RoleRoute are intentionally excluded.
  private const STATIC_PAGES = [
    ['path' => '/',              'priority' => '1.0', 'changefreq' => 'daily'],
    ['path' => '/home',          'priority' => '0.9', 'changefreq' => 'daily'],
    ['path' => '/events',        'priority' => '0.9', 'changefreq' => 'daily'],
    ['path' => '/about',         'priority' => '0.7', 'changefreq' => 'monthly'],
    ['path' => '/how-it-works',  'priority' => '0.5', 'changefreq' => 'monthly'],
    ['path' => '/legal',         'priority' => '0.3', 'changefreq' => 'yearly'],
    ['path' => '/terms',         'priority' => '0.3', 'changefreq' => 'yearly'],
    ['path' => '/privacy',       'priority' => '0.3', 'changefreq' => 'yearly'],
    ['path' => '/refund-policy', 'priority' => '0.3', 'changefreq' => 'yearly'],
  ];

  public static function generate(): void
  {
    header('Content-Type: application/xml; charset=utf-8');

    $db = Database::connect();
    $appUrl = rtrim(Environment::get('FRONTEND_URL', 'https://app.ticketer.website'), '/');

    $stmt = $db->prepare("
      SELECT slug, updated_at
      FROM events
      WHERE status = 'published'
        AND deleted_at IS NULL
      ORDER BY updated_at DESC
    ");
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    // Static SPA pages
    foreach (self::STATIC_PAGES as $page) {
      $loc = htmlspecialchars("{$appUrl}{$page['path']}", ENT_QUOTES);
      echo "  <url>\n";
      echo "    <loc>{$loc}</loc>\n";
      echo "    <changefreq>{$page['changefreq']}</changefreq>\n";
      echo "    <priority>{$page['priority']}</priority>\n";
      echo "  </url>\n";
    }

    // Static pages worth indexing
    echo "  <url><loc>{$appUrl}/</loc><priority>1.0</priority></url>\n";
    echo "  <url><loc>{$appUrl}/events</loc><priority>0.9</priority></url>\n";

    foreach ($events as $event) {
      $loc = htmlspecialchars("{$appUrl}/events/{$event['slug']}", ENT_QUOTES);
      $lastmod = date('Y-m-d', strtotime($event['updated_at']));
      echo "  <url>\n";
      echo "    <loc>{$loc}</loc>\n";
      echo "    <lastmod>{$lastmod}</lastmod>\n";
      echo "    <changefreq>weekly</changefreq>\n";
      echo "    <priority>0.8</priority>\n";
      echo "  </url>\n";
    }

    echo '</urlset>';
  }
}
