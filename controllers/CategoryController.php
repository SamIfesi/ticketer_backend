<?php

class CategoryController
{
  private PDO $db;
  private Request $request;

  public function __construct(Request $request)
  {
    $this->request = $request;
    $this->db      = Database::connect();
  }

  // GET /api/categories
  // Public — anyone can see categories
  public function index(): void
  {
    $stmt = $this->db->prepare("
            SELECT
                c.id,
                c.name,
                c.slug,
                c.icon,
                COUNT(e.id) AS event_count
            FROM categories c
            LEFT JOIN events e
                ON e.category_id = c.id
                AND e.status = 'published'
                AND e.deleted_at IS NULL
            GROUP BY c.id, c.name, c.slug, c.icon
            ORDER BY c.name ASC
        ");
    $stmt->execute();

    Response::success(['categories' => $stmt->fetchAll()]);
  }

  // GET /api/categories/:id
  //
  // FIX #3: Replaced e.tickets_sold (removed column) with a
  // LEFT JOIN on v_event_sales for live accurate counts.
  // Also filters soft-deleted events.
  public function show(array $params): void
  {
    $categoryId = (int) $params['id'];

    $stmt = $this->db->prepare("
            SELECT id, name, slug, icon FROM categories WHERE id = ?
        ");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();

    if (!$category) {
      Response::notFound('Category not found.');
    }

    $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.title,
                e.slug,
                e.location,
                e.banner_image,
                e.start_date,
                e.total_tickets,
                COALESCE(s.tickets_sold, 0)      AS tickets_sold,
                COALESCE(s.tickets_available, 0)  AS tickets_available,
                u.name AS organizer_name
            FROM events e
            JOIN users u ON u.id = e.organizer_id
            LEFT JOIN v_event_sales s ON s.event_id = e.id
            WHERE e.category_id = ?
              AND e.status = 'published'
              AND e.deleted_at IS NULL
            ORDER BY e.start_date ASC
        ");
    $stmt->execute([$categoryId]);
    $category['events'] = $stmt->fetchAll();

    Response::success(['category' => $category]);
  }

  // POST /api/categories
  // Protected: admin or dev only
  public function store(): void
  {
    $name = trim($this->request->input('name', ''));
    $icon = trim($this->request->input('icon', 'ticket'));

    // Validate
    $errors = [];

    if (empty($name)) {
      $errors['name'] = 'Category name is required.';
    }

    if (!empty($errors)) {
      Response::validationError($errors);
    }

    // Generate slug from name
    $slug = $this->generateSlug($name);

    // Check slug is unique
    $stmt = $this->db->prepare('SELECT id FROM categories WHERE slug = ?');
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
      Response::error('A category with this name already exists.', 409);
    }

    $this->db->prepare("INSERT INTO categories (name, slug, icon) VALUES (?, ?, ?)")
      ->execute([$name, $slug, $icon]);

    $categoryId = $this->db->lastInsertId();

    $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);

    Response::success(['category' => $stmt->fetch()], 'Category created successfully.', 201);
  }

  // PUT /api/categories/:id
  // Protected: admin or dev only
  public function update(array $params): void
  {
    $categoryId = (int) $params['id'];

    $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();

    if (!$category) {
      Response::notFound('Category not found.');
    }

    $name = trim($this->request->input('name', ''));
    $icon = trim($this->request->input('icon', ''));

    $this->db->prepare("
            UPDATE categories SET
                name = COALESCE(NULLIF(?, ''), name),
                icon = COALESCE(NULLIF(?, ''), icon)
            WHERE id = ?
        ")->execute([$name, $icon, $categoryId]);

    $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);

    Response::success(['category' => $stmt->fetch()], 'Category updated successfully.');
  }

  // DELETE /api/categories/:id
  // Protected: admin or dev only
  // Only deletes if no events are linked to this category
  public function destroy(array $params): void
  {
    $categoryId = (int) $params['id'];

    $stmt = $this->db->prepare('SELECT id FROM categories WHERE id = ?');
    $stmt->execute([$categoryId]);

    if (!$stmt->fetch()) {
      Response::notFound('Category not found.');
    }

    // Check if any events use this category
    $stmt = $this->db->prepare("SELECT COUNT(*) FROM events WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    $eventCount = (int) $stmt->fetchColumn();

    if ($eventCount > 0) {
      Response::error(
        "Cannot delete this category — {$eventCount} event(s) are using it. Reassign them first.",
        409
      );
    }

    $this->db->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]);

    Response::success(null, 'Category deleted successfully.');
  }

  // PRIVATE HELPERS
  private function generateSlug(string $name): string
  {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    return trim($slug, '-');
  }
}
