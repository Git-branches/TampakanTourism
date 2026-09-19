<?php
declare(strict_types=1);

/**
 * The manager's logbook page refuses a line with no gender or no address.
 *
 * Tested at the server, over real HTTP, because that is the claim: a record was
 * getting in without either, and client-side checking is not a control — anything
 * that posts the form directly walks past it. The validator is exercised
 * directly too, so a line's exact fault is pinned down rather than inferred from
 * a page that simply did not save.
 *
 * DOES NOT WRITE TO ANY EXISTING REPORT. It creates its own draft, uses it, and
 * deletes it and everything under it on the way out.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\LogbookEntryRepository as Entries;

echo "=== manager logbook: gender and address are required ===\n";

/* ---- the validator, line by line --------------------------------------- */

echo "\n-- what counts as a complete line --\n";

$line = static fn(array $over = []): array => $over + [
    'full_name'    => 'Juan Dela Cruz',
    'address_text' => 'Brgy. Poblacion, Tampakan',
    'sex'          => 'male',
];

check('a complete line passes', Entries::validate([$line()]), []);

check('no gender is refused',
    array_keys(Entries::validate([$line(['sex' => ''])])[1] ?? []), ['sex']);
check('an invented gender is refused',
    array_keys(Entries::validate([$line(['sex' => 'other'])])[1] ?? []), ['sex']);
check('a missing gender key is refused',
    array_keys(Entries::validate([['full_name' => 'A B', 'address_text' => 'Brgy. Poblacion']])[1] ?? []),
    ['sex']);

check('no address is refused',
    array_keys(Entries::validate([$line(['address_text' => ''])])[1] ?? []), ['address_text']);
check('a whitespace-only address is refused',
    array_keys(Entries::validate([$line(['address_text' => "   \t "])])[1] ?? []), ['address_text']);
check('a dash is refused',
    array_keys(Entries::validate([$line(['address_text' => '-'])])[1] ?? []), ['address_text']);
check('"N/A" is refused',
    array_keys(Entries::validate([$line(['address_text' => 'N/A'])])[1] ?? []), ['address_text']);
check('"wala" is refused',
    array_keys(Entries::validate([$line(['address_text' => 'Wala'])])[1] ?? []), ['address_text']);
check('digits alone are refused',
    array_keys(Entries::validate([$line(['address_text' => '12345'])])[1] ?? []), ['address_text']);

check('both faults are reported together',
    array_keys(Entries::validate([$line(['sex' => '', 'address_text' => ''])])[1] ?? []),
    ['sex', 'address_text']);

/* THE BLANK LINES BELOW THE LAST VISITOR MUST STILL BE FINE. A paper page is
   half full and the form shows ruled lines for the rest; refusing those would
   make an ordinary page unsaveable. */
echo "\n-- ruled blank lines are not visitors --\n";
check('an empty row is ignored', Entries::validate([['full_name' => '']]), []);
check('a row of only spaces is ignored', Entries::validate([['full_name' => '   ']]), []);
check('blank rows after a good one are ignored',
    Entries::validate([$line(), ['full_name' => ''], ['full_name' => '']]), []);

/* The line numbers must be the ones the manager sees, which skip blanks. */
echo "\n-- the line number points at the right row --\n";
$errs = Entries::validate([$line(), ['full_name' => ''], $line(['sex' => ''])]);
check('the second visitor is line 2', array_keys($errs), [2]);

/* ---- the repository refuses, even called directly ----------------------- */

echo "\n-- the repository is the last gate --\n";

$refused = false;

try {
    Entries::replaceForDate(0, '2026-01-01', [$line(['sex' => ''])]);
} catch (\InvalidArgumentException $e) {
    $refused = true;
}

check('replaceForDate() throws rather than writing', $refused, true);

/* ---- over HTTP, as the manager's browser does it ------------------------ */

echo "\n-- the manager's page, over HTTP --\n";

[$sid, $token] = test_sign_in_manager();

/* THE MANAGER'S OWN DESTINATION, not just any. The page checks that the report
   belongs to the signed-in manager's site, so a report created against another
   destination would be refused for the right reason and prove nothing about
   the validation this suite is here to test. */
$destinationId = (int) Database::scalar(
    'SELECT destination_id FROM destination_managers ORDER BY id LIMIT 1'
);

if ($destinationId === 0) {
    echo "  no destination manager on file — nothing to sign in as\n";
    test_finish();
}

/* Its own draft, so no existing report is touched. */
$reportId = Database::insert(
    "INSERT INTO arrival_reports (destination_id, period_start, period_end, status)
     VALUES (?, '2026-01-05', '2026-01-11', 'draft')",
    [$destinationId]
);

register_shutdown_function(static function () use ($reportId): void {
    Database::run('DELETE FROM arrival_report_entries WHERE report_id = ?', [$reportId]);
    Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [$reportId]);
    Database::run('DELETE FROM tourist_arrivals WHERE report_id = ?', [$reportId]);
    Database::run('DELETE FROM arrival_reports WHERE id = ?', [$reportId]);
    echo "  (test report #{$reportId} removed)\n";
});

$date = '2026-01-06';
$url  = '/manager/logbook.php?id=' . $reportId . '&date=' . $date;

$stored = static fn(): int => (int) Database::scalar(
    'SELECT COUNT(*) FROM arrival_report_entries WHERE report_id = ? AND visit_date = ?',
    [$reportId, $date]
);

/* FLATTENED BEFORE IT REACHES cURL. CURLOPT_POSTFIELDS takes a flat map; handed
   a nested array it converts the inner one to the string "Array" and posts that,
   so `row[1][sex]` would arrive as garbage and every check below would pass for
   the wrong reason. The names are built the way the form writes them. */
$flatten = static function (array $rows) use ($token): array {
    $fields = ['_token' => $token];

    foreach ($rows as $lineNo => $row) {
        foreach ($row as $key => $value) {
            $fields["row[{$lineNo}][{$key}]"] = (string) $value;
        }
    }

    return $fields;
};

$post = static function (array $row) use ($sid, $url, $flatten): array {
    return test_post($url, $sid, $flatten([1 => $row]));
};

check('the page starts empty', $stored(), 0);

/* No gender. */
$res = $post(['full_name' => 'Maria Santos', 'address_text' => 'Brgy. Poblacion, Tampakan', 'sex' => '']);
check('a line with no gender is not stored', $stored(), 0);
check('and the page says why',
    stripos($res['body'], 'not saved') !== false
    || stripos($res['body'], "select the visitor's gender") !== false, true);
check('the typing is handed back',
    strpos($res['body'], 'Maria Santos') !== false, true);

/* No address. */
$res = $post(['full_name' => 'Pedro Reyes', 'address_text' => '', 'sex' => 'male']);
check('a line with no address is not stored', $stored(), 0);
check('and the page says why',
    stripos($res['body'], 'not saved') !== false
    || stripos($res['body'], 'address or place of residence') !== false, true);
check('the typing is handed back',
    strpos($res['body'], 'Pedro Reyes') !== false, true);

/* A placeholder address. */
$post(['full_name' => 'Ana Lim', 'address_text' => 'N/A', 'sex' => 'female']);
check('a placeholder address is not stored', $stored(), 0);

/* Complete. */
$post(['full_name' => 'Jose Cruz', 'address_text' => 'Brgy. Poblacion, Tampakan', 'sex' => 'male']);
check('a complete line IS stored', $stored(), 1);

$row = Database::first(
    'SELECT full_name, address_text, sex FROM arrival_report_entries WHERE report_id = ? AND visit_date = ?',
    [$reportId, $date]
);

check('  the name was kept', (string) $row['full_name'], 'Jose Cruz');
check('  the address was kept', (string) $row['address_text'], 'Brgy. Poblacion, Tampakan');
check('  the gender was kept', (string) $row['sex'], 'male');

/* AND ONE BAD LINE STOPS THE WHOLE PAGE. The page is written as a unit, so
   accepting the good lines would delete the bad one the manager is still on. */
echo "\n-- one bad line stops the page --\n";

test_post($url, $sid, $flatten([
    1 => ['full_name' => 'Good One', 'address_text' => 'Brgy. Lampitak, Tampakan', 'sex' => 'female'],
    2 => ['full_name' => 'Bad One',  'address_text' => 'Brgy. Lampitak, Tampakan', 'sex' => ''],
]));

check('the page is unchanged', $stored(), 1);
check('and still holds the earlier line',
    (string) Database::scalar(
        'SELECT full_name FROM arrival_report_entries WHERE report_id = ? AND visit_date = ?',
        [$reportId, $date]
    ),
    'Jose Cruz');

/* ---- existing records are left alone ------------------------------------ */

echo "\n-- historical records are not touched --\n";

/* Every entry on file predates this rule and has no gender. Nothing in this
   change may delete or rewrite them. */
$before = (int) Database::scalar(
    'SELECT COUNT(*) FROM arrival_report_entries WHERE report_id <> ?', [$reportId]
);
check('older entries are still there', $before > 0, true);
check('and still include ones with no gender',
    (int) Database::scalar(
        'SELECT COUNT(*) FROM arrival_report_entries WHERE report_id <> ? AND sex IS NULL',
        [$reportId]
    ) > 0,
    true);

test_finish();
