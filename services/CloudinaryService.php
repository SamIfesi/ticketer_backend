<?php

/**
 * CloudinaryService
 *
 * Handles all Cloudinary interactions without the SDK —
 * pure PHP using cURL + HMAC signing so there is no
 * extra composer dependency.
 *
 * Upload flow (signed):
 *   1. React calls POST /api/cloudinary/sign
 *   2. PHP returns { signature, api_key, timestamp, cloud_name, upload_preset }
 *   3. React POSTs the file DIRECTLY to Cloudinary — PHP never touches the binary
 *   4. Cloudinary returns { secure_url, public_id }
 *   5. React sends those two values to the real save endpoint (profile / event)
 */
class CloudinaryService
{
  // ── Upload presets (create these in Cloudinary dashboard) ──
  // Settings → Upload → Add upload preset
  // Set "Signing mode" to Signed for both.
  const PRESET_BANNERS = 'ticketer_banners';   // max 1920px, auto quality/format
  const PRESET_AVATARS = 'ticketer_avatars';   // 400×400 face-crop, auto quality/format

  // ── Folders inside your Cloudinary account ─────────────────
  const FOLDER_BANNERS = 'ticketer/banners';
  const FOLDER_AVATARS = 'ticketer/avatars';

  // Generate signed upload parameters
  //
  // React sends these as extra POST fields alongside the file.
  // Cloudinary verifies the HMAC before accepting the upload.
  //
  // $preset — one of the PRESET_* constants above
  // $folder — one of the FOLDER_* constants above
  //
  // Returns array ready to JSON-encode back to React.
  public static function signUpload(string $preset, string $folder): array
  {
    $apiSecret  = Environment::get('CLOUDINARY_API_SECRET');
    $apiKey     = Environment::get('CLOUDINARY_API_KEY');
    $cloudName  = Environment::get('CLOUDINARY_CLOUD_NAME');
    $timestamp  = time();

    // Parameters that MUST match what React sends to Cloudinary
    // They are signed in alphabetical order
    $params = [
      'folder'         => $folder,
      'timestamp'      => $timestamp,
      'upload_preset'  => $preset,
    ];

    // Build the string to sign: key=value&key=value sorted alphabetically
    ksort($params);
    $stringToSign = '';
    foreach ($params as $key => $value) {
      $stringToSign .= "{$key}={$value}&";
    }
    $stringToSign = rtrim($stringToSign, '&');

    $signature = hash('sha256', $stringToSign . $apiSecret);

    return [
      'signature'    => $signature,
      'api_key'      => $apiKey,
      'timestamp'    => $timestamp,
      'cloud_name'   => $cloudName,
      'upload_preset' => $preset,
      'folder'       => $folder,
    ];
  }

  // Delete an image from Cloudinary by its public_id
  //
  // Call this whenever a user replaces their avatar or an
  // organizer replaces a banner — keeps your storage clean.
  //
  // Returns true on success, false on failure (non-fatal).
  public static function delete(string $publicId): bool
  {
    if (empty($publicId)) return false;

    $apiKey    = Environment::get('CLOUDINARY_API_KEY');
    $apiSecret = Environment::get('CLOUDINARY_API_SECRET');
    $cloudName = Environment::get('CLOUDINARY_CLOUD_NAME');
    $timestamp = time();

    $stringToSign = "public_id={$publicId}&timestamp={$timestamp}{$apiSecret}";
    $signature    = hash('sha256', $stringToSign);

    $url  = "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy";
    $data = [
      'public_id' => $publicId,
      'timestamp' => $timestamp,
      'api_key'   => $apiKey,
      'signature' => $signature,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => http_build_query($data),
      CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return false;

    $decoded = json_decode($response, true);
    return isset($decoded['result']) && $decoded['result'] === 'ok';
  }

  // Build an optimized Cloudinary delivery URL
  //
  // Use these helpers everywhere you render an <img> so you
  // always get WebP, right size, auto quality — no work on React side.
  //
  // $publicId — the public_id returned by Cloudinary after upload

  // Avatar: square, face-centred crop, WebP, auto quality
  public static function avatarUrl(string $publicId, int $size = 200): string
  {
    return self::buildUrl($publicId, "w_{$size},h_{$size},c_fill,g_face,f_auto,q_auto");
  }

  // Banner / flyer: fixed width, auto height, WebP, auto quality
  public static function bannerUrl(string $publicId, int $width = 1200): string
  {
    return self::buildUrl($publicId, "w_{$width},c_limit,f_auto,q_auto");
  }

  // Thumbnail: banner shrunk for card grids
  public static function thumbnailUrl(string $publicId, int $width = 600): string
  {
    return self::buildUrl($publicId, "w_{$width},h_400,c_fill,f_auto,q_auto");
  }

  // Raw builder — pass any Cloudinary transformation string
  public static function buildUrl(string $publicId, string $transforms = ''): string
  {
    $cloudName = Environment::get('CLOUDINARY_CLOUD_NAME');
    $base      = "https://res.cloudinary.com/{$cloudName}/image/upload";

    if ($transforms) {
      return "{$base}/{$transforms}/{$publicId}";
    }

    return "{$base}/{$publicId}";
  }

  // Extract public_id from a full Cloudinary secure_url
  //
  // Useful when you only stored the URL and need to delete it.
  public static function publicIdFromUrl(string $url): string
  {
    // e.g. https://res.cloudinary.com/mycloud/image/upload/v1234/ticketer/avatars/abc123.jpg
    // → ticketer/avatars/abc123
    $pattern = '/\/image\/upload\/(?:v\d+\/)?(.+?)(?:\.[a-z]{2,4})?$/i';
    if (preg_match($pattern, $url, $matches)) {
      return $matches[1];
    }
    return '';
  }
}
