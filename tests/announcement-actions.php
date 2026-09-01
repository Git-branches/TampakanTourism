<?php
declare(strict_types=1);

/**
 * The announcement list's own actions, and the picture on the card.
 *
 * TWO GAPS THIS COVERS.
 *
 * `announcements.banner_path` has existed since the table was created and
 * nothing ever wrote to it. Every notice and every event on the homepage fell
 * back to one stock photograph, so a row of six event cards was six copies of
 * the same picture — and there was no field for it on any screen. The upload
 * has to reach the column, survive a round trip, and be removable.
 *
 * And the list row had no actions at all. Editing a notice, taking a live one
 * down, or filing it away meant opening it first. Those three post to view.php,
 * which has handled `action=status` all along — this is a second door to the
 * same handler, and the test is that it really is the same handler and not a
 * second implementation that will drift.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== announcement actions ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

$is = static function (string $what, bool $got): void { check($what, $got, true); };

[$sid, $csrf] = test_sign_in_officer();

$made  = [];
$files = [];

register_shutdown_function(static function () use (&$made, &$files): void {
    foreach ($made as $id) {
        Database::run('DELETE FROM announcements WHERE id = ?', [$id]);
    }

    foreach ($files as $relative) {
        $path = dirname(APP_PATH) . '/' . ltrim((string) $relative, '/');

        if (is_file($path)) {
            unlink($path);
        }
    }

    printf("\n  cleaned up %d announcement(s) and %d file(s)\n", count($made), count($files));
});

/* ---------------------------------------------------------------------------
   1. Creating one WITH a picture
   ------------------------------------------------------------------------ */

echo "--- the card picture ---\n";

$png = test_make_png(sys_get_temp_dir() . '/announce-banner.png', 'BANNER', 1200, 800);

$title = 'ZZ probe event ' . bin2hex(random_bytes(3));

$create = test_post('/admin/announcements/create.php', $sid, [
    '_token'         => $csrf,
    'title'          => $title,
    'body'           => 'A probe announcement written by the test suite.',
    'summary'        => 'Probe summary.',
    'type'           => 'event',
    'audience'       => 'public',
    'status'         => 'published',
    'event_date'     => date('Y-m-d', strtotime('+21 days')),
    'event_location' => 'Poblacion, Tampakan',
], $png, 'banner');

$is('the announcement was accepted', in_array($create['code'], [200, 302], true));

$row = Database::first('SELECT * FROM announcements WHERE title = ?', [$title]);

$is('and it reached the database', $row !== null);

if ($row === null) {
    echo "  cannot continue without the row\n";
    test_finish();
}

$id = (int) $row['id'];
$made[] = $id;

$banner = trim((string) ($row['banner_path'] ?? ''));

printf("    banner_path = %s\n", $banner === '' ? '(empty)' : $banner);

$is('the picture reached banner_path', $banner !== '');
$is('and the file is really on disk', $banner !== '' && file_on_disk($banner));

if ($banner !== '') {
    $files[] = $banner;
}

/* It is stored where the hero banners live, not loose in the webroot. */
$is('it was stored under uploads/', str_starts_with($banner, 'uploads/'));

/* ---------------------------------------------------------------------------
   2. It reaches the homepage
   ------------------------------------------------------------------------ */

echo "\n--- on the public homepage ---\n";

$home = test_get('/index.php');

$is('the event is listed', str_contains($home, $title));
$is('with its own picture, not the stock one',
    $banner === '' || str_contains($home, $banner));

/* ---------------------------------------------------------------------------
   3. Edit as a dialog fragment
   ------------------------------------------------------------------------ */

echo "\n--- Edit in the dialog ---\n";

$full = test_get_as($sid, '/admin/announcements/edit.php?id=' . $id);
$frag = test_get_as($sid, '/admin/announcements/edit.php?id=' . $id . '&modal=1');

printf("    full %s bytes, fragment %s\n", number_format(strlen($full)), number_format(strlen($frag)));

$is('the full page still renders whole',
    str_contains($full, '<html') && str_contains($full, 'admin-shell'));
$is('the fragment drops the shell',
    !str_contains($frag, '<html') && !str_contains($frag, 'admin-shell'));
$is('and still carries the composer', str_contains($frag, 'name="title"'));
$is('with the file input on it', str_contains($frag, 'name="banner"'));
$is('and an enctype, or the upload would send only a filename',
    str_contains($frag, 'multipart/form-data'));
$is('it shows the picture already attached', str_contains($frag, $banner));

/* ---------------------------------------------------------------------------
   4. The three status moves, from the list
   ------------------------------------------------------------------------ */

echo "\n--- the row's own actions ---\n";

$list = test_get_as($sid, '/admin/announcements/index.php');

$is('the shared dialog is on the list', str_contains($list, 'id="pageModal"'));
$is('it is declared above the composer sheet',
    strpos($list, 'id="pageModal"') < strpos($list, 'id="addAnnouncement"'));
$is('Edit is a modal trigger', (bool) preg_match('/href="edit\.php\?id=\d+"[^>]*data-modal-page/', $list));

/* FIVE ITEMS, IN ONE ORDER, and Delete last.
   The menu is what the office reaches for; if an item quietly disappears the
   capability disappears with it, and nothing else on the screen offers it. */
$menuStart = strpos($list, 'card-menu__panel');
$menuEnd   = strpos($list, '</div>', (int) $menuStart);
$menu      = substr($list, (int) $menuStart, (int) $menuEnd - (int) $menuStart);

/* Read the item labels out of the markup rather than hunting for each one.
   Searching for "Unpublish\n" found nothing while "> Edit" found plenty: these
   files carry CRLF, so the byte after the label is \r, and the menu read as
   four items when it has five. Taking the labels in document order asks the
   question that was actually meant. */
preg_match_all('/<(?:a|button)\b[^>]*>(.*?)<\/(?:a|button)>/s', $menu, $m);

$order = array_values(array_filter(array_map(
    static fn (string $x): string => trim(preg_replace('/\s+/', ' ', strip_tags($x))),
    $m[1]
)));

printf("    menu: %s\n", implode(' → ', $order));

$is('Edit is on the menu',      in_array('Edit', $order, true));
$is('View is on the menu',      in_array('View', $order, true));
$is('Duplicate is on the menu', in_array('Duplicate', $order, true));
$is('Publish or Unpublish is on it',
    in_array('Publish', $order, true) || in_array('Unpublish', $order, true));
$is('and Delete is on it',      in_array('Delete', $order, true));

$is('the menu is exactly five items', count($order) === 5);

/* Delete is the only irreversible one. It belongs at the bottom, away from the
   four that are not — a hurried hand reaching for Unpublish must not find it. */
$is('Delete comes last of all', end($order) === 'Delete');

/* And it is separated by a rule, not merely by position. */
$is('with a rule above it', str_contains($menu, 'card-menu__rule'));

/* view.php must NOT repeat what the menu already owns. That duplication is
   what put an Edit button inside the page you opened in order to read. */
$viewPage = test_get_as($sid, '/admin/announcements/view.php?id=' . $id);

$is('the view page no longer repeats Edit',
    !preg_match('/href="edit\.php\?id=\d+"/', $viewPage));
$is('nor the publish buttons',
    !str_contains($viewPage, 'value="published"'));
$is('but it still offers Archive, which the menu does not',
    str_contains($viewPage, 'value="archived"'));

preg_match_all('/data-confirm="([^"]*)"/', $list, $asks);

$statusAsks = array_values(array_filter($asks[1],
    static fn (string $q): bool => str_contains($q, 'draft') || str_contains($q, 'Archive')));

$is('and every one of them asks first', count($statusAsks) >= 2);
$is('with no raw PHP left in the attribute',
    !array_filter($statusAsks, static fn (string $q): bool => str_contains($q, '<?')));

printf("    %d confirmation(s) on the list\n", count($asks[1]));

/* ---- and they actually work, through view.php's existing handler ---- */

foreach (['draft', 'archived', 'published'] as $status) {
    $r = test_post('/admin/announcements/view.php', $sid, [
        '_token' => $csrf,
        'id'     => $id,
        'action' => 'status',
        'status' => $status,
    ]);

    $now = (string) Database::scalar('SELECT status FROM announcements WHERE id = ?', [$id]);

    $is('moving it to ' . $status . ' works from the list\'s form', $now === $status);
}

/* A past event must leave UPCOMING EVENTS — but not the page.
   It is still a published notice, so Latest News keeps it, and asking whether
   the title has gone from the whole homepage was the wrong question: it failed
   on correct behaviour. Scoped to the events strip. */
Database::run('UPDATE announcements SET event_date = ? WHERE id = ?',
    [date('Y-m-d', strtotime('-30 days')), $id]);

$eventsOnly = static function (): string {
    $html  = test_get('/index.php');
    $start = strpos($html, 'id="eventGrid"');

    if ($start === false) {
        return '';
    }

    $end = strpos($html, 'id="eventRailDots"', $start);

    return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
};

$is('a past event drops out of Upcoming Events', !str_contains($eventsOnly(), $title));

Database::run('UPDATE announcements SET event_date = ? WHERE id = ?',
    [date('Y-m-d', strtotime('+21 days')), $id]);

$is('and a future one is back in it', str_contains($eventsOnly(), $title));

/* ---------------------------------------------------------------------------
   5. Removing the picture
   ------------------------------------------------------------------------ */

echo "\n--- removing the picture ---\n";

$removed = test_post('/admin/announcements/edit.php?id=' . $id, $sid, [
    '_token'        => $csrf,
    'title'         => $title,
    'body'          => 'A probe announcement written by the test suite.',
    'summary'       => 'Probe summary.',
    'type'          => 'event',
    'audience'      => 'public',
    'status'        => 'published',
    'event_date'    => date('Y-m-d', strtotime('+21 days')),
    'remove_banner' => '1',
]);

$is('the edit was accepted', in_array($removed['code'], [200, 302], true));

$after = (string) (Database::scalar(
    'SELECT banner_path FROM announcements WHERE id = ?', [$id]) ?? '');

$is('banner_path is empty again', $after === '');
$is('and the file went with it', $banner === '' || !file_on_disk($banner));

/* Nothing else was disturbed by the removal. */
$is('the announcement itself survived',
    (string) Database::scalar('SELECT title FROM announcements WHERE id = ?', [$id]) === $title);

/* ---------------------------------------------------------------------------
   Duplicate, and then delete
   ------------------------------------------------------------------------ */

echo "\n--- duplicate ---\n";

Database::run("UPDATE announcements SET status = 'published', publish_at = NOW() WHERE id = ?", [$id]);

$before = (int) Database::scalar('SELECT COUNT(*) FROM announcements');

test_post('/admin/announcements/view.php', $sid, [
    '_token' => $csrf,
    'id'     => $id,
    'action' => 'duplicate',
]);

$copy = Database::first(
    'SELECT * FROM announcements WHERE title = ? ORDER BY id DESC LIMIT 1', [$title . ' (copy)']);

$is('a copy was made', $copy !== null);

if ($copy !== null) {
    $made[] = (int) $copy['id'];

    printf("    copy #%d, status %s, slug %s\n", $copy['id'], $copy['status'], $copy['slug']);

    /* A copy that published itself would put last year's date on the website
       the moment somebody pressed Duplicate. */
    $is('the copy is a draft, never published', $copy['status'] === 'draft');
    $is('and carries no publish time to fire on', $copy['publish_at'] === null);
    $is('it has its own address', $copy['slug'] !== '' && $copy['slug'] !== $title);

    /* The words and the date come across — that is the point of copying. */
    $is('the wording came with it',   $copy['body'] === 'A probe announcement written by the test suite.');
    $is('and the type',               $copy['type'] === 'event');
    $is('and the event date',         (string) $copy['event_date'] !== '');

    $is('the original is untouched',
        (string) Database::scalar('SELECT status FROM announcements WHERE id = ?', [$id]) === 'published');
    $is('and exactly one row was added',
        (int) Database::scalar('SELECT COUNT(*) FROM announcements') === $before + 1);
}

echo "\n--- delete ---\n";

/* Deleting takes the delivery board with it — notifications cascade — so the
   test proves the row goes AND that nothing else did. */
$survivor = (int) Database::scalar('SELECT COUNT(*) FROM announcements WHERE id <> ?', [$id]);

$gone = test_post('/admin/announcements/view.php', $sid, [
    '_token' => $csrf,
    'id'     => $id,
    'action' => 'delete',
]);

$is('the delete was accepted', in_array($gone['code'], [200, 302], true));
$is('the announcement is gone',
    Database::scalar('SELECT id FROM announcements WHERE id = ?', [$id]) === null);
$is('and nothing else went with it',
    (int) Database::scalar('SELECT COUNT(*) FROM announcements') === $survivor);

/* Deleting is officer-only: it destroys the only evidence the office has that
   a closure notice was actually sent. */
[$msid] = test_sign_in_manager();

if ($msid !== '' && $copy !== null) {
    $asManager = test_post('/admin/announcements/view.php', $msid, [
        '_token' => $csrf,
        'id'     => (int) $copy['id'],
        'action' => 'delete',
    ]);

    $is('a destination manager cannot delete one',
        Database::scalar('SELECT id FROM announcements WHERE id = ?', [(int) $copy['id']]) !== null);
}

/* ---------------------------------------------------------------------------
   6. Two doors, one screen
   ---------------------------------------------------------------------------
   Events and Announcements are the same table behind different filters, which
   is a deliberate choice: 15 of the 17 columns are shared, as are the status
   workflow, the SMS dispatch and the public page. The risk of the arrangement
   is that the two doors stop being distinguishable — both sidebar entries light
   at once, or the composer opens on the wrong type and an officer writes an
   event that never reaches Upcoming Events because nobody set the type.
   ------------------------------------------------------------------------ */

echo "\n--- the Events door ---\n";

/** Which sidebar links are marked active, by their visible label. */
$activeNav = static function (string $html): array {
    preg_match_all('/<a class="sidebar__link is-active[^"]*"[^>]*>(.*?)<\/a>/s', $html, $m);

    return array_map(
        static fn (string $x): string => trim(preg_replace('/\s+/', ' ', strip_tags($x))),
        $m[1]
    );
};

$doors = [
    ''              => ['New Announcements', 'New Announcement'],
    '?section=events' => ['New Events',  'New Event'],
    /* A filter nothing claims belongs to the default door — narrowing to
       Closures must not leave the sidebar with nothing lit. */
    '?type=closure'  => ['New Announcements', 'New Announcement'],
];

foreach ($doors as $query => [$expected, $button]) {
    $page = test_get_as($sid, '/admin/announcements/index.php' . $query);

    preg_match('/<h1>(.*?)<\/h1>/s', $page, $t);
    $heading = isset($t[1]) ? trim(preg_replace('/\s+/', ' ', strip_tags($t[1]))) : '';

    $active = $activeNav($page);
    $label  = $query === '' ? 'the plain list' : $query;

    printf("    %-15s heading %-14s active: %s\n",
        $query === '' ? '(no filter)' : $query, $heading,
        $active === [] ? 'NONE' : implode(' + ', $active));

    $onScreen = str_replace('New ', '', $expected);

    $is($label . ': the heading says ' . $onScreen, $heading === $onScreen);
    $is($label . ': exactly one sidebar item is lit', count($active) === 1);
    $is($label . ': and it is ' . $expected, ($active[0] ?? '') === $expected);
    $is($label . ': the button says ' . $button, str_contains($page, $button));
}

/* The composer must open on the type the door is for. An officer who writes an
   event from the Events screen and leaves the type at its default would produce
   a notice that never appears in Upcoming Events, and nothing would say why. */
$is('the composer opens on Tourism Event', (bool) preg_match(
    '/<option value="event"[^>]*selected/',
    test_get_as($sid, '/admin/announcements/index.php?section=events')));

$is('and on General Announcement from the other door', (bool) preg_match(
    '/<option value="announcement"[^>]*selected/',
    test_get_as($sid, '/admin/announcements/index.php')));

/* Both doors are the same records. */
$eventTotal = (int) Database::scalar("SELECT COUNT(*) FROM announcements WHERE type = 'event'");
$allTotal   = (int) Database::scalar('SELECT COUNT(*) FROM announcements');

printf("    %d event(s) of %d announcement(s), one table\n", $eventTotal, $allTotal);

$is('the events door really is filtered', $eventTotal < $allTotal);

/* THE SPLIT ITSELF. A type in both lists would put the same record in both
   sections of the public homepage, which is the fault this exists to fix. */
$overlap = array_intersect_key(
    App\Repositories\AnnouncementRepository::NEWS_TYPES,
    App\Repositories\AnnouncementRepository::EVENT_TYPES
);

$is('no type belongs to both vocabularies', $overlap === []);
$is('and TYPES is still the union of the two',
    count(App\Repositories\AnnouncementRepository::TYPES)
    === count(App\Repositories\AnnouncementRepository::NEWS_TYPES)
     + count(App\Repositories\AnnouncementRepository::EVENT_TYPES));

/* And the News door must not list an event. */
$newsList = test_get_as($sid, '/admin/announcements/index.php');

$eventTitles = array_column(Database::all(
    "SELECT title FROM announcements WHERE type IN ('event','festival','community','municipal','activity')"
), 'title');

$leaked = array_filter($eventTitles, static fn (string $t): bool => str_contains($newsList, $t));

$is('no event appears on the News list', $leaked === []);

/* ---------------------------------------------------------------------------
   The public navigation
   ---------------------------------------------------------------------------
   The order is specified, not incidental: Events comes before Announcements
   because the invitation is what most visitors arrive for and the advisories
   are what they read once they have decided to come. Written down here so a
   later tidy-up of the nav array cannot quietly reverse it.
   ------------------------------------------------------------------------ */

echo "\n--- the public navigation ---\n";

$homePage = test_get('/index.php');

preg_match('/<ul class="navbar-nav.*?<\/ul>/s', $homePage, $navHtml);
preg_match_all('/<a[^>]*class="nav-link[^"]*"[^>]*>(.*?)<\/a>/s', $navHtml[0] ?? '', $navItems);

$nav = array_map(
    static fn (string $x): string => trim(preg_replace('/\s+/', ' ', strip_tags($x))),
    $navItems[1]
);

printf("    %s\n", implode(' | ', $nav));

$is('Events is in the navigation',        in_array('Events', $nav, true));
$is('Announcements is still there too',   in_array('Announcements', $nav, true));

$eventsAt = array_search('Events', $nav, true);
$newsAt   = array_search('Announcements', $nav, true);

$is('and Events comes first of the two',
    $eventsAt !== false && $newsAt !== false && $eventsAt < $newsAt);

/* Each points at its own section of the homepage, not at the same one. */
$is('Events points at the events section',        str_contains($homePage, 'href="#events"'));
$is('Announcements points at the news section',   str_contains($homePage, 'href="#news"'));

/* And the two sections really are two. */
$is('both sections exist on the page',
    str_contains($homePage, 'id="events"') && str_contains($homePage, 'id="news"'));

/* ---- the navbar knows where you are ----------------------------------------
   Events was added to the bar with an empty `match`, so it was the one item
   that could never light: a visitor reading about the fiesta saw nothing marked
   at all, while the same visitor reading a closure notice saw Announcements
   highlighted. The bar has to say which section the page belongs to, or it is
   decoration.
   ------------------------------------------------------------------------ */

/** The nav item marked as the current page, by its visible label. */
$litNav = static function (string $html): array {
    preg_match_all('/<a[^>]*class="nav-link[^"]*is-active[^"]*"[^>]*>(.*?)<\/a>/s', $html, $m);

    if ($m[1] === []) {
        preg_match_all('/<a[^>]*aria-current="page"[^>]*>(.*?)<\/a>/s', $html, $m);
    }

    return array_map(
        static fn (string $x): string => trim(preg_replace('/\s+/', ' ', strip_tags($x))),
        $m[1]
    );
};

$anEvent = (string) (Database::scalar(
    "SELECT slug FROM announcements
      WHERE status = 'published'
        AND type IN ('event','festival','community','municipal','activity')
      LIMIT 1") ?? '');

$aNotice = (string) (Database::scalar(
    "SELECT slug FROM announcements
      WHERE status = 'published'
        AND type NOT IN ('event','festival','community','municipal','activity')
      LIMIT 1") ?? '');

foreach ([
    'events.php?slug='       . $anEvent => 'Events',
    'announcement.php?slug=' . $aNotice => 'Announcements',
    'map.php'                           => 'Tourist Map',
] as $path => $expected) {
    if (str_ends_with($path, 'slug=')) {
        continue;   /* nothing of that kind published to test with */
    }

    $lit = $litNav(test_get('/' . $path));

    printf("    %-46s %s\n", explode('?', $path)[0], $lit === [] ? 'NONE' : implode(' + ', $lit));

    $is(explode('?', $path)[0] . ': exactly one nav item is lit', count($lit) === 1);
    $is(explode('?', $path)[0] . ': and it is ' . $expected, ($lit[0] ?? '') === $expected);
}
printf("    %d event title(s) checked against the News list
", count($eventTitles));

test_finish();
