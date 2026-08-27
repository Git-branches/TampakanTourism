<?php
declare(strict_types=1);

namespace App\Core;

/**
 * TourSync — one way to page through a list.
 *
 * Four modules had grown their own pager by the time this was written, each a
 * copy of the last with a different window size and a different way of building
 * the query string. Three others that grow just as fast had none at all, so a
 * screen that will eventually hold a thousand rows was rendering all of them.
 *
 * SIX PER PAGE is the house default. It is small, and deliberately so: these
 * lists are read on an office desktop and on a manager's phone, and the pages
 * here are cards rather than table rows — six cards is a screenful, sixty is a
 * scroll nobody finishes.
 *
 * WHAT THIS DOES NOT DO
 *
 * It does not query. Each repository already knows how to count and slice its
 * own rows with its own filters; a generic query builder would have to be told
 * about every join those repositories do. This owns the arithmetic and the
 * links, which is the part that was being copied.
 */
final class Paginator
{
    public const PER_PAGE = 6;

    /**
     * Works out the window from a total and a requested page.
     *
     * @return array{page:int, pages:int, perPage:int, total:int, offset:int, from:int, to:int}
     */
    public static function of(int $total, mixed $requestedPage = null, int $perPage = self::PER_PAGE): array
    {
        $total   = max(0, $total);
        $perPage = max(1, min(100, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));

        /* Clamped rather than trusted. ?page=0 divides into a negative offset and
           ?page=9999 returns an empty list that reads as "your filter found
           nothing" — both are the same mistake, a page number taken at face
           value from a URL somebody can type. */
        $page = (int) $requestedPage;
        $page = $page < 1 ? 1 : min($page, $pages);

        $offset = ($page - 1) * $perPage;

        return [
            'page'    => $page,
            'pages'   => $pages,
            'perPage' => $perPage,
            'total'   => $total,
            'offset'  => $offset,
            'from'    => $total === 0 ? 0 : $offset + 1,
            'to'      => min($offset + $perPage, $total),
        ];
    }

    /**
     * Slices an array that is already in memory.
     *
     * For the lists whose repository returns everything — the smaller modules,
     * where a second COUNT query would cost more than it saves. A module that
     * outgrows this should move its paging into SQL, and the page markup does
     * not change when it does.
     *
     * @param  array<int, mixed> $rows
     * @return array{rows: array<int, mixed>, page:int, pages:int, perPage:int, total:int, offset:int, from:int, to:int}
     */
    public static function slice(array $rows, mixed $requestedPage = null, int $perPage = self::PER_PAGE): array
    {
        $window = self::of(count($rows), $requestedPage, $perPage);

        return ['rows' => array_slice($rows, $window['offset'], $window['perPage'])] + $window;
    }

    /**
     * Adapts a repository that already pages in SQL.
     *
     * Three modules — announcements, destinations and feedback — were paging
     * before this class existed, each through its own `paginate()` and each at
     * its own window size (15, 12 and 20). Their SQL is right and doing it
     * again in PHP would mean fetching every row to throw most away, so the
     * queries stay; only the shape they return is completed here, with the
     * `offset`, `from` and `to` the shared pager needs to say "1–6 of 14".
     *
     * @param  array{rows?: array<int, mixed>, total?: int, page?: int, pages?: int, perPage?: int} $result
     * @return array{rows: array<int, mixed>, page:int, pages:int, perPage:int, total:int, offset:int, from:int, to:int}
     */
    public static function adopt(array $result): array
    {
        $window = self::of(
            (int) ($result['total'] ?? 0),
            $result['page'] ?? 1,
            (int) ($result['perPage'] ?? self::PER_PAGE)
        );

        return ['rows' => (array) ($result['rows'] ?? [])] + $window;
    }

    /**
     * The current query string with page removed, ready for the pager to append
     * its own.
     *
     * Built from the actual request rather than from a list each page maintains
     * by hand — that list is what silently drops a filter when somebody adds a
     * new one and forgets to add it here too, so page 2 of a filtered list
     * quietly shows the unfiltered set.
     */
    public static function query(array $except = ['page']): string
    {
        $params = $_GET;

        foreach ($except as $key) {
            unset($params[$key]);
        }

        return http_build_query($params);
    }
}
