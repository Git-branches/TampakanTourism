<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uploader;

/**
 * TourSync — the heritage items shown on a destination's QR page.
 *
 * WHY THIS IS NOT destination_photos WITH A FLAG
 *
 * Those two look alike and are not. A gallery photograph is decoration chosen
 * for how the place looks; a heritage item is a statement the office is making
 * about what something means, and it carries a title and a paragraph rather
 * than a caption.
 *
 * The deciding reason is mechanical, though. Three queries in
 * DestinationRepository pick a cover photograph with
 * `SELECT file_path FROM destination_photos … LIMIT 1`. Share the table and a
 * burial-jar photograph can become a waterfall's cover image on the public
 * list. Every one of those queries would have to learn a filter, and the one
 * that was missed would fail quietly and look like a choice somebody made.
 *
 * Order is a column, for the same reason it is on the hero: items get reordered
 * far more often than they get created, and `id` cannot say "this one moved to
 * the front" without rewriting primary keys.
 */
final class HeritageRepository
{
    public const MAX_TITLE = 160;
    public const MAX_BODY  = 1000;

    /**
     * Every item for a destination, in the order the office arranged them.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forDestination(int $destinationId): array
    {
        return Database::all(
            'SELECT * FROM destination_heritage
              WHERE destination_id = ?
              ORDER BY sort_order ASC, id ASC',
            [$destinationId]
        );
    }

    /**
     * The items a QR page should actually draw.
     *
     * An item with no words at all is skipped even if it has a photograph. That
     * state is reachable — upload the picture, save, and be called away before
     * writing the caption — and a picture with nothing said about it is not
     * heritage, it is a photograph in the wrong section.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publicFor(int $destinationId): array
    {
        return array_values(array_filter(
            self::forDestination($destinationId),
            static fn(array $r): bool => trim((string) $r['title']) !== ''
                                      || trim((string) $r['body']) !== ''
        ));
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM destination_heritage WHERE id = ?', [$id]);
    }

    public static function countFor(int $destinationId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM destination_heritage WHERE destination_id = ?',
            [$destinationId]
        );
    }

    /**
     * How many items each destination has, keyed by destination id.
     *
     * One query for the whole list screen rather than one per row — twenty
     * destinations should not mean twenty round trips to draw a badge.
     *
     * @return array<int, int>
     */
    public static function countsByDestination(): array
    {
        $out = [];

        foreach (Database::all(
            'SELECT destination_id, COUNT(*) AS n FROM destination_heritage GROUP BY destination_id'
        ) as $row) {
            $out[(int) $row['destination_id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Adds an item to the end of a destination's list.
     *
     * @param array<string, mixed> $data
     */
    public static function create(int $destinationId, array $data): int
    {
        return Database::insert(
            'INSERT INTO destination_heritage (destination_id, image_path, title, body, sort_order)
             VALUES (?, ?, ?, ?, ?)',
            [
                $destinationId,
                (string) ($data['image_path'] ?? ''),
                self::clip((string) ($data['title'] ?? ''), self::MAX_TITLE),
                self::clip((string) ($data['body']  ?? ''), self::MAX_BODY),
                self::nextSortOrder($destinationId),
            ]
        );
    }

    /**
     * Saves the words. The photograph is replaceImage()'s job, so a file that
     * failed to upload cannot take an edited paragraph down with it.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Database::run(
            'UPDATE destination_heritage SET title = ?, body = ? WHERE id = ?',
            [
                self::clip((string) ($data['title'] ?? ''), self::MAX_TITLE),
                self::clip((string) ($data['body']  ?? ''), self::MAX_BODY),
                $id,
            ]
        );
    }

    /**
     * Points an item at a newly stored file and removes the one it replaces.
     *
     * The row is updated FIRST and the old file deleted after — the other order
     * leaves a QR page pointing at a file that is gone if the write fails.
     */
    public static function replaceImage(int $id, string $stored): void
    {
        $item = self::find($id);

        if ($item === null) {
            return;
        }

        $previous = trim((string) $item['image_path']);

        Database::run('UPDATE destination_heritage SET image_path = ? WHERE id = ?', [$stored, $id]);

        if ($previous !== '' && $previous !== $stored) {
            Uploader::delete($previous);
        }
    }

    /** Removes the photograph but keeps the words. */
    public static function clearImage(int $id): void
    {
        $item = self::find($id);

        if ($item === null) {
            return;
        }

        $previous = trim((string) $item['image_path']);

        Database::run("UPDATE destination_heritage SET image_path = '' WHERE id = ?", [$id]);

        if ($previous !== '') {
            Uploader::delete($previous);
        }
    }

    /** Removes the item and the photograph nothing else is using. */
    public static function delete(int $id): void
    {
        $item = self::find($id);

        if ($item === null) {
            return;
        }

        $destinationId = (int) $item['destination_id'];
        $image         = trim((string) $item['image_path']);

        Database::run('DELETE FROM destination_heritage WHERE id = ?', [$id]);

        if ($image !== '' && !self::imageInUse($image)) {
            Uploader::delete($image);
        }

        self::renumber($destinationId);
    }

    /**
     * Applies an order given as a list of ids, within one destination.
     *
     * Ids that do not belong to this destination are ignored, and items the
     * list forgot keep their place at the end — a stale form submitted after
     * somebody deleted an item reorders what it can instead of throwing the
     * arrangement away.
     *
     * @param array<int, int|string> $ids
     */
    public static function reorder(int $destinationId, array $ids): void
    {
        $known  = array_map('intval', array_column(self::forDestination($destinationId), 'id'));
        $wanted = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && in_array($id, $known, true) && !in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        foreach ($known as $id) {
            if (!in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        Database::transaction(static function () use ($wanted): void {
            foreach ($wanted as $position => $id) {
                Database::run('UPDATE destination_heritage SET sort_order = ? WHERE id = ?',
                    [$position, $id]);
            }
        });
    }

    /** Closes gaps left by a deletion, so positions stay 0..n-1. */
    private static function renumber(int $destinationId): void
    {
        $position = 0;

        foreach (self::forDestination($destinationId) as $item) {
            Database::run('UPDATE destination_heritage SET sort_order = ? WHERE id = ?',
                [$position++, (int) $item['id']]);
        }
    }

    private static function nextSortOrder(int $destinationId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM destination_heritage WHERE destination_id = ?',
            [$destinationId]
        );
    }

    private static function imageInUse(string $path): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM destination_heritage WHERE image_path = ?', [$path]
        ) > 0;
    }

    /**
     * Trims to the column width in CHARACTERS.
     *
     * mb_substr, not substr: this copy carries ñ, ’ and the occasional B'laan
     * term, and cutting a multi-byte character in half stores a broken sequence
     * that MySQL rejects — a save that fails with nothing said.
     */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
