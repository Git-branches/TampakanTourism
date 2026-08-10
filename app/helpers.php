<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Session;

/**
 * Global helpers. Deliberately few — anything with real logic belongs in a
 * service class, not here.
 */

if (!function_exists('e')) {
    /**
     * Escape for HTML output. Every dynamic value on every page passes
     * through this; the public landing page already uses it.
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('img')) {
    /**
     * Fallback artwork for a destination that has no photograph uploaded yet,
     * and for the homepage sections still awaiting their own phase.
     *
     * Shared here rather than defined in index.php: the listing and detail
     * pages need the same fallback, and a per-page copy is how one page ends
     * up fataling on an undefined function.
     *
     * Replaced naturally as real photographs are added through the admin area.
     */
    function img(string $photoId, int $w = 1200, int $h = 800): string
    {
        return "https://images.unsplash.com/photo-{$photoId}?auto=format&fit=crop&w={$w}&h={$h}&q=80";
    }
}

if (!function_exists('config')) {
    /** Reads config with dot notation: config('database.host') */
    function config(string $key, $default = null)
    {
        static $config = null;
        $config ??= $GLOBALS['__toursync_config'] ?? [];

        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}

if (!function_exists('public_nav')) {
    /**
     * The one definition of the public navigation.
     *
     * Defined here because the navbar and the footer both render it, and on
     * every page. Two copies drifted apart once already — the homepage offered
     * an "Explore" dropdown and a Gallery link that no other page had, and its
     * "Destinations" scrolled to a section while the same word on every other
     * page opened the full listing. One label must mean one thing.
     *
     * Two rules govern this list:
     *
     *   1. If a real page exists, the link points at the page. Sending a
     *      visitor to a homepage section when /map.php exists is how a site
     *      ends up with pages nobody can reach.
     *   2. Homepage-only sections carry the full path, so the same link works
     *      from a destination page as from the homepage. On the homepage they
     *      collapse to a bare anchor, so clicking one scrolls instead of
     *      reloading the page.
     *
     * @return array<int, array{label:string, href:string, match:string}>
     */
    function public_nav(): array
    {
        $onHome = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'index.php';

        /** Homepage sections: scroll when already home, navigate otherwise. */
        $section = static fn(string $id): string => $onHome ? '#' . $id : base_url('/#' . $id);

        return [
            ['label' => 'Home',          'href' => $onHome ? '#home' : base_url('/'), 'match' => 'index.php'],
            ['label' => 'Destinations',  'href' => $onHome ? '#destinations' : destinations_url(), 'match' => 'destinations'],
            ['label' => 'Tourist Map',   'href' => base_url('/map.php'),              'match' => 'map.php'],
            ['label' => 'Announcements', 'href' => $onHome ? '#news' : announcements_url(), 'match' => 'announcements'],
            ['label' => 'Travel Guide',  'href' => $section('travel-guide'),          'match' => ''],
            ['label' => 'About',         'href' => $section('about'),                 'match' => ''],
            ['label' => 'Contact',       'href' => $section('contact'),               'match' => ''],
        ];
    }
}

if (!function_exists('setting')) {
    /**
     * Reads an operational setting from the database.
     *
     * Distinct from config(): config() holds deployment facts that live in a
     * file (credentials, paths), while settings are values the Tourism Officer
     * can change from inside the system — retention period, rate limits, the
     * duplicate window.
     *
     * Loaded once per request and held in memory, so a page reading five
     * settings still makes one query.
     */
    function setting(string $key, $default = null)
    {
        static $settings = null;

        if ($settings === null) {
            $settings = [];
            try {
                foreach (App\Core\Database::all('SELECT setting_key, setting_value FROM settings') as $row) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
            } catch (Throwable $e) {
                // A missing settings table must not take down a public page;
                // callers fall back to their own defaults.
                error_log('TourSync settings unavailable: ' . $e->getMessage());
            }
        }

        return $settings[$key] ?? $default;
    }
}

if (!function_exists('base_url')) {
    /**
     * Absolute URL to a path within the application.
     *
     * Setting base_url to 'auto' in config derives it from the incoming
     * request instead, which is what lets the same installation answer on
     * http://tampakantourism.test (the Laragon vhost, document root = the
     * project) and on http://192.168.1.x/TampakanTourism (the LAN address,
     * document root = C:/laragon/www) without editing config between the two.
     * That matters for testing QR codes, because a phone on the WiFi cannot
     * resolve a .test hostname.
     *
     * Pin it to a fixed value in production. QR codes encode whatever this
     * returns at the moment they are printed, so a base URL that moves would
     * silently retire signage already installed on a mountain.
     */
    function base_url(string $path = ''): string
    {
        static $base = null;

        if ($base === null) {
            $configured = trim((string) config('base_url', ''));

            if ($configured === '' || strtolower($configured) === 'auto') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';

                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

                // How much of the URL path sits between the document root and
                // this application — empty when they are the same folder.
                $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
                $appRoot = str_replace('\\', '/', (string) realpath(dirname(APP_PATH)));

                $prefix = ($docRoot !== '' && $appRoot !== '' && str_starts_with($appRoot, $docRoot))
                    ? trim(substr($appRoot, strlen($docRoot)), '/')
                    : '';

                $base = $scheme . '://' . $host . ($prefix !== '' ? '/' . $prefix : '');
            } else {
                $base = rtrim($configured, '/');
            }
        }

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * URL to a file in assets/, stamped with its modification time.
     *
     * The stamp is what stops a browser serving yesterday's stylesheet after
     * an edit. Without it every CSS or JS change needs the visitor to know to
     * hard-refresh — which is fine for a developer and useless for an officer
     * who just sees the site looking wrong.
     *
     * The query string changes only when the file does, so caching still
     * works normally the rest of the time.
     */
    function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $absolute = dirname(APP_PATH) . '/assets/' . $relative;
        $version  = is_file($absolute) ? filemtime($absolute) : null;

        return base_url('assets/' . $relative) . ($version ? '?v=' . $version : '');
    }
}

if (!function_exists('destinations_url')) {
    /**
     * URL of the destination catalogue.
     *
     * The catalogue used to be its own page. It now lives in the #destinations
     * section of the homepage, together with the search box and the category
     * filter that used to sit above it — one address for "show me the places"
     * instead of a featured teaser on one page and the real list on another.
     *
     * Fourteen call sites pointed at the old file: the navbar, the footer, the
     * QR landing page, the logbook, the 404 page, and several form handlers
     * that redirect here when they have nowhere better to send someone. They go
     * through this function now, so the next time the catalogue moves it moves
     * once.
     *
     * @param array<string, string|null> $params Query string, e.g. ['category' => 'waterfalls']
     */
    function destinations_url(array $params = []): string
    {
        $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '');
        $query  = $params !== [] ? '?' . http_build_query($params) : '';

        /* The fragment has to come last, after the query string — a URL with
           it the other way round makes the whole query part of the fragment
           and the filter silently stops working. */
        return base_url('/') . $query . '#destinations';
    }
}

if (!function_exists('announcements_url')) {
    /**
     * URL of the announcement feed.
     *
     * The same move the catalogue made, for the same reason. announcements.php
     * held the full list and its type filter while the homepage held a teaser
     * of three — two addresses for "what has the Tourism Office said", and the
     * one a visitor reached first was the one that could not answer. Both are
     * now the #news section of the homepage, filter included.
     *
     * Every link goes through here rather than naming a file, so the feed can
     * move again without hunting down the navbar, the breadcrumb on
     * announcement.php, and the chips.
     *
     * @param array<string, string|null> $params Query string, e.g. ['type' => 'advisory']
     */
    function announcements_url(array $params = []): string
    {
        $params = array_filter($params, static fn($value): bool => $value !== null && $value !== '');
        $query  = $params !== [] ? '?' . http_build_query($params) : '';

        /* Query first, fragment last — the other way round buries the whole
           query inside the fragment and the filter silently stops working. */
        return base_url('/') . $query . '#news';
    }
}

if (!function_exists('uploaded_url')) {
    /**
     * URL to an uploaded file — but only if the file is still there.
     *
     * A destination_photos row and the JPEG it names can part company: the
     * file is deleted, restored from a backup taken at a different moment, or
     * copied to another machine while the database is not. The row survives,
     * the file does not, and every page that trusted the row renders a broken
     * image with no clue why.
     *
     * Returning null instead lets each caller reach for its own fallback,
     * which they already have. Costs one stat() per photograph.
     */
    function uploaded_url(?string $path): ?string
    {
        $relative = ltrim((string) $path, '/');

        if ($relative === '' || !is_file(dirname(APP_PATH) . '/' . $relative)) {
            return null;
        }

        return base_url($relative);
    }
}

if (!function_exists('redirect')) {
    /** Sends a redirect and stops. Never returns. */
    function redirect(string $url, int $status = 302): void
    {
        header('Location: ' . $url, true, $status);
        exit;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('old_all')) {
    /**
     * The full set of rejected input from the previous request.
     *
     * Read from globals, not the session — bootstrap moves it there and clears
     * the session copy, so a flash_back() later in this same request writes a
     * clean new set instead of colliding with the one being displayed.
     */
    function old_all(): array
    {
        return $GLOBALS['__toursync_old'] ?? [];
    }
}

if (!function_exists('old')) {
    /**
     * Repopulates a form after a validation failure, so the visitor never
     * retypes what they already entered. Output is escaped.
     */
    function old(string $field, $default = '')
    {
        $old = old_all();
        return isset($old[$field]) ? e((string) $old[$field]) : e((string) $default);
    }
}

if (!function_exists('error_for')) {
    function error_for(string $field): ?string
    {
        return $GLOBALS['__toursync_errors'][$field] ?? null;
    }
}

if (!function_exists('all_errors')) {
    function all_errors(): array
    {
        return $GLOBALS['__toursync_errors'] ?? [];
    }
}

if (!function_exists('has_error')) {
    function has_error(string $field): bool
    {
        return error_for($field) !== null;
    }
}

if (!function_exists('flash_back')) {
    /**
     * Stores errors and input, then returns to the form. The one-request
     * lifetime is handled by clearing them in bootstrap on the next load.
     */
    function flash_back(array $errors, array $input, string $url): void
    {
        Session::put('_errors', $errors);
        Session::put('_old', array_diff_key($input, array_flip(['password', 'password_confirm', '_token'])));
        redirect($url);
    }
}

if (!function_exists('str_slug')) {
    function str_slug(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
        return strtolower(trim($text, '-')) ?: 'item';
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'F j, Y'): string
    {
        if ($date === null || $date === '' || str_starts_with($date, '0000')) {
            return '—';
        }
        $ts = strtotime($date);
        return $ts === false ? '—' : date($format, $ts);
    }
}

if (!function_exists('n')) {
    /** Thousands-separated integer, for dashboard counters. */
    function n($number): string
    {
        return number_format((float) $number);
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('json_response')) {
    /** Ends the request with a JSON body. Used by every endpoint in /api. */
    function json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
