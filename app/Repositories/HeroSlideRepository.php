<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Uploader;

/**
 * TourSync — the slides that rotate at the top of the public homepage.
 *
 * WHY THIS IS A TABLE AND NOT SETTINGS ROWS
 *
 * The hero lived in twelve settings keys — hero_1_title, hero_2_eyebrow, and so
 * on. That gave the office the words on three slides and nothing else. There was
 * no fourth slide, no way to hold one back while its photograph was still being
 * taken, and no way to put the rainy-season picture first in June. Three was not
 * a decision anybody made: it was how many happened to be hard-coded in
 * index.php the day the words were lifted out of it.
 *
 * A row per slide answers all three, and costs a table.
 *
 * ORDER IS A COLUMN, NOT AN ID SEQUENCE. Slides get reordered far more often
 * than they get created, and `id` cannot express "this one moved to the front"
 * without rewriting primary keys. sort_order is renumbered from zero on every
 * reorder, so a list that has been dragged about for a year has no gaps.
 */
final class HeroSlideRepository
{
    /** What a slide's status may be, and what the officer sees it called. */
    public const STATUSES = [
        'published' => 'Published',
        'draft'     => 'Draft',
    ];

    /** Ceilings that match the column widths — see database/migrate.php. */
    public const MAX_EYEBROW = 120;
    public const MAX_TITLE   = 160;
    public const MAX_BODY    = 400;

    /**
     * Every slide, in the order the office arranged them.
     *
     * Drafts included: this is what the settings screen lists, and a draft the
     * officer cannot see is a draft they cannot publish.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::all(
            'SELECT * FROM hero_slides ORDER BY sort_order ASC, id ASC'
        );
    }

    /**
     * The slides the public homepage actually shows.
     *
     * A slide with no words at all is skipped even when it is published. That
     * state is reachable — add a slide, upload the photograph, save, and go to
     * lunch before typing the caption — and a blank pane sliding across the
     * front page reads as a broken site rather than an unfinished one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function published(): array
    {
        $rows = Database::all(
            "SELECT * FROM hero_slides
              WHERE status = 'published'
              ORDER BY sort_order ASC, id ASC"
        );

        return array_values(array_filter($rows, static fn(array $r): bool =>
            trim((string) $r['title']) !== ''
            || trim((string) $r['eyebrow']) !== ''
            || trim((string) $r['body']) !== ''
        ));
    }

    public static function find(int $id): ?array
    {
        return Database::first('SELECT * FROM hero_slides WHERE id = ?', [$id]);
    }

    public static function countAll(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM hero_slides');
    }

    public static function countPublished(): int
    {
        return count(self::published());
    }

    /**
     * Adds a slide to the end of the list.
     *
     * @param array<string, mixed> $data
     */
    public static function create(array $data): int
    {
        return Database::insert(
            'INSERT INTO hero_slides (image_path, eyebrow, title, body, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (string) ($data['image_path'] ?? ''),
                self::clip((string) ($data['eyebrow'] ?? ''), self::MAX_EYEBROW),
                self::clip((string) ($data['title']   ?? ''), self::MAX_TITLE),
                self::clip((string) ($data['body']    ?? ''), self::MAX_BODY),
                self::status($data['status'] ?? 'published'),
                self::nextSortOrder(),
            ]
        );
    }

    /**
     * Saves the words and the status. The photograph is handled separately by
     * replaceImage(), because a file that failed to upload must not take the
     * caption down with it.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        Database::run(
            'UPDATE hero_slides
                SET eyebrow = ?, title = ?, body = ?, status = ?
              WHERE id = ?',
            [
                self::clip((string) ($data['eyebrow'] ?? ''), self::MAX_EYEBROW),
                self::clip((string) ($data['title']   ?? ''), self::MAX_TITLE),
                self::clip((string) ($data['body']    ?? ''), self::MAX_BODY),
                self::status($data['status'] ?? 'published'),
                $id,
            ]
        );
    }

    /**
     * Points a slide at a newly stored file and removes the one it replaces.
     *
     * The order matters and is the whole reason this is a method rather than two
     * lines at the call site: the row is updated FIRST, and only then is the old
     * file deleted. Deleting first would leave the homepage pointing at a file
     * that is gone if the write failed.
     */
    public static function replaceImage(int $id, string $stored): void
    {
        $slide = self::find($id);

        if ($slide === null) {
            return;
        }

        $previous = trim((string) $slide['image_path']);

        Database::run('UPDATE hero_slides SET image_path = ? WHERE id = ?', [$stored, $id]);

        if ($previous !== '' && $previous !== $stored) {
            Uploader::delete($previous);
        }
    }

    /** Clears the photograph, falling the slide back to the stock image. */
    public static function clearImage(int $id): void
    {
        $slide = self::find($id);

        if ($slide === null) {
            return;
        }

        $previous = trim((string) $slide['image_path']);

        Database::run("UPDATE hero_slides SET image_path = '' WHERE id = ?", [$id]);

        if ($previous !== '') {
            Uploader::delete($previous);
        }
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::run('UPDATE hero_slides SET status = ? WHERE id = ?', [self::status($status), $id]);
    }

    /**
     * Copies a slide, including its photograph.
     *
     * The FILE is copied, not the path. Two rows sharing one path means deleting
     * either slide deletes the other's picture — the kind of fault that shows up
     * weeks later as a homepage with a hole in it.
     */
    public static function duplicate(int $id): ?int
    {
        $slide = self::find($id);

        if ($slide === null) {
            return null;
        }

        $image  = trim((string) $slide['image_path']);
        $copied = $image !== '' ? Uploader::copy($image) : '';

        return Database::insert(
            'INSERT INTO hero_slides (image_path, eyebrow, title, body, status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $copied,
                (string) $slide['eyebrow'],
                self::clip(trim((string) $slide['title']) . ' (copy)', self::MAX_TITLE),
                (string) $slide['body'],
                /* A copy starts as a draft whatever the original was. The point
                   of duplicating is to change something, and the front page
                   should not carry two near-identical slides in the meantime. */
                'draft',
                self::nextSortOrder(),
            ]
        );
    }

    /** Removes the slide and the photograph nothing else is using. */
    public static function delete(int $id): void
    {
        $slide = self::find($id);

        if ($slide === null) {
            return;
        }

        Database::run('DELETE FROM hero_slides WHERE id = ?', [$id]);

        $image = trim((string) $slide['image_path']);

        if ($image !== '' && !self::imageInUse($image)) {
            Uploader::delete($image);
        }

        self::renumber();
    }

    /**
     * Applies an order given as a list of slide ids.
     *
     * Ids that are not on the roster are ignored and slides the list forgot keep
     * their place at the end, so a stale form — two tabs open, one submitted
     * after a slide was deleted in the other — reorders what it can instead of
     * throwing the arrangement away.
     *
     * @param array<int, int|string> $ids
     */
    public static function reorder(array $ids): void
    {
        $known = array_column(self::all(), 'id');
        $known = array_map('intval', $known);

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
                Database::run('UPDATE hero_slides SET sort_order = ? WHERE id = ?', [$position, $id]);
            }
        });
    }

    /** Closes any gaps left by a deletion, so positions stay 0..n-1. */
    private static function renumber(): void
    {
        $position = 0;

        foreach (self::all() as $slide) {
            Database::run('UPDATE hero_slides SET sort_order = ? WHERE id = ?',
                [$position++, (int) $slide['id']]);
        }
    }

    private static function nextSortOrder(): int
    {
        return (int) Database::scalar('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM hero_slides');
    }

    /** Whether any slide still points at this file. */
    private static function imageInUse(string $path): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM hero_slides WHERE image_path = ?', [$path]
        ) > 0;
    }

    private static function status(mixed $value): string
    {
        $value = (string) $value;

        return isset(self::STATUSES[$value]) ? $value : 'published';
    }

    /**
     * Trims to the column width in CHARACTERS.
     *
     * mb_substr, not substr: the copy on this page is Filipino and English and
     * carries ñ, é and the occasional em dash. Cutting a multi-byte character in
     * half stores a broken sequence, and MySQL rejects the row rather than
     * saving something odd — a save that fails with no message.
     */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
