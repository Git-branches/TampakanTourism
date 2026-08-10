<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CategoryRepository
{
    public static function all(): array
    {
        return Database::all('SELECT * FROM categories ORDER BY sort_order, name');
    }

    /** Categories that actually have at least one active destination. */
    public static function withDestinations(): array
    {
        return Database::all(
            "SELECT c.*, COUNT(d.id) AS destination_count
               FROM categories c
               JOIN destinations d ON d.category_id = c.id AND d.status = 'active'
              GROUP BY c.id
              ORDER BY c.sort_order, c.name"
        );
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return Database::first('SELECT * FROM categories WHERE slug = ?', [$slug]);
    }
}
