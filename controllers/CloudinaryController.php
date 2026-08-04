<?php

/**
 * CloudinaryController
 *
 * Routes (add to routes/cloudinary.php):
 *   POST /api/cloudinary/sign          → sign()       — returns upload signature to React
 *   POST /api/cloudinary/avatar        → saveAvatar() — saves public_id + URL to users table
 *   POST /api/cloudinary/banner/:id    → saveBanner() — saves public_id + URL to events table
 *
 * Flow:
 *   1. React calls /sign → gets signature params
 *   2. React uploads directly to Cloudinary (PHP never sees the file)
 *   3. Cloudinary returns { secure_url, public_id } to React
 *   4. React calls /avatar or /banner/:id with those two values
 *   5. PHP validates, deletes old image if any, saves new values to DB
 */
class CloudinaryController
{
    private PDO $db;
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->db      = Database::connect();
    }

    // POST /api/cloudinary/sign
    // Protected: logged in
    //
    // Body: { type: 'avatar' | 'banner' }
    //
    // Returns signed params React needs to upload directly to Cloudinary.
    // The signature expires after ~1 minute (Cloudinary enforces this).
    public function sign(): void
    {
        $type = trim($this->request->input('type', ''));

        if (!in_array($type, ['avatar', 'banner'], true)) {
            Response::validationError(['type' => 'Type must be avatar or banner.']);
        }

        // Organizer role check for banners
        if ($type === 'banner') {
            $role = $this->request->user['role'];
            if (!in_array($role, ['organizer', 'admin', 'dev'], true)) {
                Response::forbidden('Only organisers can upload event banners.');
            }
        }

        $preset = $type === 'avatar'
            ? CloudinaryService::PRESET_AVATARS
            : CloudinaryService::PRESET_BANNERS;

        $folder = $type === 'avatar'
            ? CloudinaryService::FOLDER_AVATARS
            : CloudinaryService::FOLDER_BANNERS;

        $params = CloudinaryService::signUpload($preset, $folder);

        Response::success($params, 'Upload signature generated.');
    }

    // POST /api/cloudinary/avatar
    // Protected: logged in
    //
    // Body: { public_id, secure_url }
    //
    // Saves the new avatar and deletes the old one from Cloudinary.
    public function saveAvatar(): void
    {
        $userId    = $this->request->user['id'];
        $publicId  = trim($this->request->input('public_id', ''));
        $secureUrl = trim($this->request->input('secure_url', ''));

        if (empty($publicId) || empty($secureUrl)) {
            Response::validationError([
                'public_id'  => empty($publicId)  ? 'public_id is required.'  : '',
                'secure_url' => empty($secureUrl) ? 'secure_url is required.' : '',
            ]);
        }

        // Validate it's actually a Cloudinary URL
        if (!str_contains($secureUrl, 'cloudinary.com')) {
            Response::validationError(['secure_url' => 'Invalid image URL.']);
        }

        // Fetch and delete old avatar from Cloudinary if it exists
        $stmt = $this->db->prepare('SELECT avatar, avatar_public_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!empty($user['avatar_public_id'])) {
            CloudinaryService::delete($user['avatar_public_id']);
        }

        // Save new avatar
        $this->db->prepare('
            UPDATE users
            SET avatar           = ?,
                avatar_public_id = ?,
                updated_at       = NOW()
            WHERE id = ?
        ')->execute([$secureUrl, $publicId, $userId]);

        // Return updated user so React can update the auth store
        $stmt = $this->db->prepare('
            SELECT id, name, email, role, avatar, email_verified, created_at
            FROM users WHERE id = ?
        ');
        $stmt->execute([$userId]);

        Response::success(['user' => $stmt->fetch()], 'Avatar updated successfully.');
    }

    // POST /api/cloudinary/banner/:id
    // Protected: organizer (own events) | admin | dev
    //
    // Body: { public_id, secure_url }
    //
    // Saves the new banner and deletes the old one from Cloudinary.
    public function saveBanner(array $params): void
    {
        $eventId   = (int) $params['id'];
        $userId    = $this->request->user['id'];
        $role      = $this->request->user['role'];
        $publicId  = trim($this->request->input('public_id', ''));
        $secureUrl = trim($this->request->input('secure_url', ''));

        if (empty($publicId) || empty($secureUrl)) {
            Response::validationError([
                'public_id'  => empty($publicId)  ? 'public_id is required.'  : '',
                'secure_url' => empty($secureUrl) ? 'secure_url is required.' : '',
            ]);
        }

        if (!str_contains($secureUrl, 'cloudinary.com')) {
            Response::validationError(['secure_url' => 'Invalid image URL.']);
        }

        // Fetch event and check ownership
        $stmt = $this->db->prepare('
            SELECT organizer_id, banner_image, banner_public_id
            FROM events WHERE id = ? AND deleted_at IS NULL
        ');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();

        if (!$event) {
            Response::notFound('Event not found.');
        }

        if ($role === 'organizer' && (int) $event['organizer_id'] !== $userId) {
            Response::forbidden('You can only update banners for your own events.');
        }

        // Delete old banner from Cloudinary if it was a Cloudinary image
        if (!empty($event['banner_public_id'])) {
            CloudinaryService::delete($event['banner_public_id']);
        }

        // Save new banner
        $this->db->prepare('
            UPDATE events
            SET banner_image      = ?,
                banner_public_id  = ?,
                updated_at        = NOW()
            WHERE id = ?
        ')->execute([$secureUrl, $publicId, $eventId]);

        Response::success([
            'banner_image'     => $secureUrl,
            'banner_public_id' => $publicId,
        ], 'Banner updated successfully.');
    }
}