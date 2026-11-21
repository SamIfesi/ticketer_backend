<?php

class EventMetaController
{
  public static function show(array $params): void
  {
    $identifier = $params['slug'];
    $isNumeric = ctype_digit((string) $identifier);
    $column    = $isNumeric ? 'id' : 'slug';

    $db = Database::connect();

    $stmt = $db->prepare("
      SELECT id, title, description, banner_image, slug
      FROM events
      WHERE {$column} = ? AND deleted_at IS NULL
      LIMIT 1
    ");
    $stmt->execute([$identifier]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
      http_response_code(404);
      echo "Event not found";
      return;
    }

    $frontendUrl = DomainResolver::frontendUrl();
    $title = htmlspecialchars($event['title'], ENT_QUOTES);
    $description = htmlspecialchars(mb_substr($event['description'] ?? '', 0, 160), ENT_QUOTES);
    $image = htmlspecialchars($event['banner_image'] ?? '', ENT_QUOTES);
    $eventUrl = htmlspecialchars("{$frontendUrl}/events/{$event['slug']}", ENT_QUOTES);

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>{$title} | Ticketer</title>
  <meta name="description" content="{$description}" />

  <meta property="og:type" content="website" />
  <meta property="og:title" content="{$title}" />
  <meta property="og:description" content="{$description}" />
  <meta property="og:url" content="{$eventUrl}" />
  <meta property="og:image" content="{$image}" />

  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="{$title}" />
  <meta name="twitter:description" content="{$description}" />
  <meta name="twitter:image" content="{$image}" />

  <meta http-equiv="refresh" content="0;url={$eventUrl}" />
</head>
<body>
  <p>Redirecting to <a href="{$eventUrl}">{$title}</a>...</p>
</body>
</html>
HTML;
  }
}