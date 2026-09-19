<?php
declare(strict_types=1);

/**
 * The five About panels, each saving on its own.
 *                                     Client feedback, 17 September 2026
 *
 * WHAT THIS GUARDS.
 *
 * The About settings used to be one panel of thirty-one fields — a screen the
 * office could not navigate. It is now five, one per section of the public page,
 * and each posts ONLY its own fields.
 *
 * That is safe solely because the save handler writes what a request actually
 * contains rather than `$_POST[$key] ?? ''` across its whole list. If that ever
 * regresses, saving the Cultural Heritage panel would erase About Tampakan, the
 * Tourism Office, the Mayor, the Coordinator and the mission and vision — every
 * field not on the form that was submitted — silently, with a success message.
 *
 * So each panel is posted in turn and every OTHER field is checked to be
 * untouched. Everything is restored on the way out.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

echo "\n=== about: five panels, five saves ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $token] = test_sign_in_officer();

/* Every about_* input the panels render, and what it holds right now. */
$html = test_get_as($sid, 'admin/settings/index.php');

preg_match_all('/name="(about_[a-z0-9_]+)"/', $html, $found);
$keys = array_values(array_unique($found[1]));

check('the panel renders its About fields', count($keys) > 20, true);

$before = [];

foreach ($keys as $key) {
    $before[$key] = (string) (Database::scalar(
        'SELECT setting_value FROM settings WHERE setting_key = ?', [$key]) ?? '');
}

register_shutdown_function(static function () use ($before): void {
    foreach ($before as $key => $value) {
        Database::run(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
    }
    echo "  (about settings restored)\n";
});

/**
 * Which panel each field belongs to, read off the rendered page rather than
 * listed here — a field moved between panels must not need this file edited.
 *
 * @return array<string, array<int, string>>  panel id => field names
 */
$panelsOf = static function (string $html): array {
    $out = [];

    /* Split on the panel wrapper; each chunk then holds one form's inputs. */
    foreach (preg_split('/<section class="panel"/', $html) as $chunk) {
        if (!preg_match('/name="return_panel" value="([a-zA-Z]+)"/', $chunk, $p)) {
            continue;
        }

        preg_match_all('/name="(about_[a-z0-9_]+)"/', $chunk, $f);

        if ($f[1] !== []) {
            $out[$p[1]] = array_values(array_unique($f[1]));
        }
    }

    return $out;
};

$panels = $panelsOf($html);

check('five About panels were found', count($panels), 5);

/* Every field belongs to exactly one panel. A field rendered twice would be
   posted by two forms, and the two would fight over it. */
$seen = [];
$twice = [];

foreach ($panels as $fields) {
    foreach ($fields as $f) {
        if (isset($seen[$f])) { $twice[] = $f; }
        $seen[$f] = true;
    }
}

check('no field appears on two panels', array_values(array_unique($twice)), []);
check('every field is on some panel',
    array_values(array_diff($keys, array_keys($seen))), []);

/* ---------------------------------------------------------------------------
 | Post each panel in turn, and check it touched nothing else.
 * ------------------------------------------------------------------------ */
foreach ($panels as $panel => $fields) {
    echo "\n-- {$panel} --\n";

    /* A value this suite can recognise, per field. Short enough for the 30- and
       60-character columns; the handler clips anything longer and a clipped
       value would read as a failure that is really a column width. */
    $post = ['_token' => $token, 'action' => 'about_save', 'return_panel' => $panel];
    $want = [];

    foreach ($fields as $i => $f) {
        $want[$f] = 'ZZ' . $i . ' ' . $panel;
        $post[$f] = $want[$f];
    }

    $res = test_post('admin/settings/index.php', $sid, $post);

    check('  the save redirects', $res['code'], 302);

    /* It wrote its own. */
    $wrong = [];

    foreach ($want as $f => $value) {
        if (setting_fresh($f) !== $value) {
            $wrong[] = $f;
        }
    }

    check('  its own fields were stored', $wrong, []);

    /* AND NOTHING ELSE. This is the assertion the whole split rests on. */
    $collateral = [];

    foreach ($before as $f => $original) {
        if (in_array($f, $fields, true)) {
            continue;                       // this panel's own, checked above
        }

        if (setting_fresh($f) !== ($want[$f] ?? $original)) {
            /* A field already overwritten by an earlier panel in this loop is
               compared against what that panel left, not against the original. */
            $collateral[] = $f;
        }
    }

    check('  no other section was touched', $collateral, []);

    /* Remember what this panel left, so the next iteration compares fairly. */
    foreach ($want as $f => $value) {
        $before[$f] = $value;
    }
}

/* ---------------------------------------------------------------------------
 | Clearing a field still clears it.
 |
 | "Write only what was sent" must not become "never write an empty string" —
 | an officer who empties a textarea expects it gone. An emptied field is
 | PRESENT in the post and empty; only genuinely absent keys are skipped.
 * ------------------------------------------------------------------------ */
echo "\n-- clearing a field --\n";

$panel  = 'aboutHeritagePanel';
$fields = $panels[$panel] ?? [];

if ($fields === []) {
    check('the heritage panel was found', false, true);
} else {
    $post = ['_token' => $token, 'action' => 'about_save', 'return_panel' => $panel];

    foreach ($fields as $f) {
        $post[$f] = '';
    }

    test_post('admin/settings/index.php', $sid, $post);

    $stillSet = [];

    foreach ($fields as $f) {
        if (setting_fresh($f) !== '') { $stillSet[] = $f; }
    }

    check('emptied fields are actually cleared', $stillSet, []);

    /* AND THE PUBLIC PAGE DROPS THE SECTION — but only when it has nothing at
     * all, which now means no text AND no photographs.
     *
     * Cultural Heritage gained a gallery, so clearing the words is no longer
     * enough to empty it: an office that has uploaded pictures and not yet
     * written the paragraph should see their pictures, not an absent section.
     * This suite clears the text only, so which way it goes depends on whether
     * the office has a gallery. Both directions are asserted rather than one
     * being assumed. */
    $shots = App\Repositories\AboutPhotoRepository::published('heritage');
    $page  = test_get('index.php');

    if ($shots === []) {
        check('no text and no photographs ⇒ the section leaves the page',
            str_contains($page, 'id="about-heritage"'), false);
    } else {
        check('photographs alone keep the section on the page',
            str_contains($page, 'id="about-heritage"'), true);
    }
}

test_finish();
