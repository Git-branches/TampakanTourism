<?php
declare(strict_types=1);

/**
 * FEATURE 4 — automated report generation and AI-assisted decision support.
 *
 * Two halves that fail differently.
 *
 * The REPORT half is arithmetic on the municipality's own figures, and it ends
 * up on a sheet filed with the Department of Tourism. Its failure mode is a
 * wrong number that looks right, so this checks the totals against figures it
 * put there itself.
 *
 * The AI half talks to Gemini over the network. Its failure modes are a missing
 * key, a refused request, and — the one that matters — an endpoint anybody can
 * post to. NO REQUEST IS SENT TO GEMINI HERE: the guard rails are what is
 * checked, because spending somebody's quota to prove a chat widget replies is
 * not worth it, and a network flake would fail a test about our own code.
 *
 * Everything it creates, it deletes.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\Insights;
use App\Core\VisitorRecord;
use App\Repositories\ArrivalReportRepository as Reports;
use App\Repositories\LogbookEntryRepository as LogbookEntries;

echo "\n=== feature 4: reports and AI decision support ===\n\n";

if (!test_server_up()) {
    echo "  SKIP — no web server answering at " . test_base_url() . "\n";
    exit(0);
}

[$sid, $csrf] = test_sign_in_officer();

$dest = Database::first("SELECT id, name FROM destinations WHERE status = 'active' ORDER BY id LIMIT 1");

if ($dest === null) {
    echo "  SKIP — no active destination\n";
    exit(0);
}

$did   = (int) $dest['id'];
$start = '2019-03-04';
$end   = '2019-03-06';

/* REGISTERED BEFORE ANYTHING IS WRITTEN, and it clears four tables — this suite
   transcribes names, so it puts rows into the municipality's own arrival record
   as well as the report and the summary. Invented visitors left behind would be
   counted in the DOT return. */
register_shutdown_function(static function () use ($did, $start, $end): void {
    foreach (Database::all(
        'SELECT id FROM arrival_reports WHERE destination_id = ? AND period_start = ?',
        [$did, $start]
    ) as $row) {
        Database::run('DELETE FROM arrival_report_entries WHERE report_id = ?', [(int) $row['id']]);
        Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [(int) $row['id']]);
        Database::run('DELETE FROM arrival_reports WHERE id = ?', [(int) $row['id']]);
    }

    Database::run(
        'DELETE FROM arrival_daily_summary WHERE destination_id = ? AND visit_date BETWEEN ? AND ?',
        [$did, $start, $end]
    );

    Database::run("DELETE FROM tourist_arrivals WHERE full_name LIKE 'ZZ %'");

    echo "  (probe figures, entries and arrivals removed)\n";
});

echo "--- figures the report will be built from ---\n";

$reportId = Reports::createDraft($did, $start, $end, 'ZZ report probe');

Reports::replaceDays($reportId, [
    ['visit_date' => '2019-03-04', 'local_count' => 12, 'domestic_count' => 5,
     'foreign_count' => 2, 'ofw_count' => 1, 'male_count' => 10, 'female_count' => 10,
     'children_count' => 3, 'adults_count' => 15, 'seniors_count' => 2],
    ['visit_date' => '2019-03-05', 'local_count' => 8, 'domestic_count' => 3,
     'foreign_count' => 0, 'ofw_count' => 0, 'male_count' => 5, 'female_count' => 6,
     'children_count' => 1, 'adults_count' => 9, 'seniors_count' => 1],
    ['visit_date' => '2019-03-06', 'local_count' => 4, 'domestic_count' => 1,
     'foreign_count' => 3, 'ofw_count' => 0, 'male_count' => 4, 'female_count' => 4,
     'children_count' => 0, 'adults_count' => 8, 'seniors_count' => 0],
]);

$manager = Database::first('SELECT id FROM destination_managers WHERE destination_id = ? LIMIT 1', [$did]);

Reports::submit($reportId, $manager !== null ? (int) $manager['id'] : 0);

$officer = (int) Database::scalar("SELECT id FROM admins WHERE role = 'officer' LIMIT 1");

Reports::approve($reportId, $officer);

check('the report is approved',
    (string) Database::scalar('SELECT status FROM arrival_reports WHERE id = ?', [$reportId]),
    'approved');

$local    = 12 + 8 + 4;    /* 24 */
$domestic = 5 + 3 + 1;     /* 9  */
$foreign  = 2 + 0 + 3;     /* 5  */
$ofw      = 1 + 0 + 0;     /* 1  */
$all      = $local + $domestic + $foreign + $ofw;   /* 39 */

printf("    seeded: %d local, %d domestic, %d foreign, %d OFW — %d total\n",
    $local, $domestic, $foreign, $ofw, $all);

echo "\n--- the monthly Visitor Record adds them up the way the DOT form does ---\n";

/* build(year, month), not a date range. This is the DOT monthly sheet, and a
   calendar month is what it reports on — the class derives the start and end
   itself. My first call passed the two probe dates and PHP refused them
   outright, which is the kindest way to be wrong about a signature. */
$record = VisitorRecord::build(2019, 3);

check('the builder returns something', is_array($record), true);
check('it covers the whole calendar month', (string) $record['period_end'], '2019-03-31');
check('and labels itself', (string) $record['month_label'], 'MARCH 2019');

/* THE DOT SHEET IS BUILT FROM TRANSCRIBED NAMES, NOT FROM DAY TOTALS, and this
   is the single most useful thing this suite has to say.

   The form wants a province and a sex per visitor. Day totals do not carry
   either, so a month filed as totals alone produces a correctly EMPTY sheet:
   has_data is false and every cell is nought. That is not a fault — it is the
   form refusing to invent a breakdown nobody supplied.

   An office that only ever files day totals would hand the DOT a blank return
   and not know why. The transcription below is what fills it. */
check('day totals alone leave the sheet empty', (bool) $record['has_data'], false);

echo "\n--- transcribing the logbook names is what fills it ---\n";

/* address_text, not origin_province — the manager transcribes what is WRITTEN
   in the paper logbook's address column, and OriginClassifier files it into a
   province and a country. Passing the columns directly, as I first did, left
   them null and the sheet reported all three as unknown_province, which was the
   honest answer to input it had never been given. */
LogbookEntries::replaceForDate($reportId, '2019-03-04', [
    ['full_name' => 'ZZ Ana Cruz',   'sex' => 'female',
     'address_text' => 'Tampakan, South Cotabato'],
    ['full_name' => 'ZZ Ben Santos', 'sex' => 'male',
     'address_text' => 'Digos City, Davao del Sur'],
    ['full_name' => 'ZZ Cara Lopez', 'sex' => 'female',
     'address_text' => 'Osaka, Japan'],
]);

check('three lines were transcribed', LogbookEntries::countFor($reportId), 3);

/* Publishing happens on approval, and this report is already approved — so it
   is re-run directly, which is what a corrected resubmission does anyway. */
$published = LogbookEntries::publish($reportId, $did);

printf("    %d entries published into the municipality's records\n", $published);

check('they reached tourist_arrivals', $published, 3);

$filled = VisitorRecord::build(2019, 3);
$t      = $filled['totals'];

printf("    this province %d · other province %d · foreign %d · unplaced %d\n",
    (int) $t['this_province']['total'], (int) $t['other_province']['total'],
    (int) $t['foreign']['total'], (int) $filled['unknown_province']);

check('the sheet now has data', (bool) $filled['has_data'], true);

/* The classifier is what puts each visitor in a column, and it has been wrong
   before: reading longest-cue-first once filed eleven South Cotabato towns as
   another province, because the town name lost to a province name elsewhere in
   the string. "Tampakan, South Cotabato" is exactly that shape. */
check('Tampakan counts as this province', (int) $t['this_province']['total'], 1);
check('Digos counts as another province', (int) $t['other_province']['total'], 1);
check('Osaka counts as foreign',          (int) $t['foreign']['total'], 1);
check('nobody is left unplaced',          (int) $filled['unknown_province'], 0);
check('the grand total is the three of them', (int) $t['grand']['total'], 3);
check('and the sexes were carried across',
    [(int) $t['grand']['male'], (int) $t['grand']['female']], [1, 2]);

echo "\n--- the officer can open the record on screen ---\n";

$page = test_get_as($sid, 'admin/reports/visitor-record.php?year=2019&month=3');

check('it renders without diagnostics',
    (bool) preg_match('/Warning:|Fatal error:/', $page), false);
check('it names the destination', str_contains($page, (string) $dest['name']), true);
check('and carries the month it covers', str_contains($page, 'MARCH 2019'), true);
check('and the three transcribed visitors', str_contains($page, '>3<') || str_contains($page, ' 3 '), true);

echo "\n--- and it is refused to anyone not signed in ---\n";

$anon = test_get('admin/reports/visitor-record.php?year=2019&month=3');

check('an anonymous request does not get the figures',
    str_contains($anon, 'MARCH 2019'), false);

echo "\n--- the analysis that feeds the decision support ---\n";

/* There is no Insights::summary(); the class exposes the pieces the analytics
   screen assembles. Each is checked for the shape it promises rather than for
   a particular number — with three probe days behind them, a forecast that
   claimed confidence would be the suspicious answer. */
$history = Insights::monthlyHistory(12);
$trend   = Insights::trend(6);
$cast    = Insights::forecast();
$advice  = Insights::recommendations();

check('monthly history is an array', is_array($history), true);
check('the trend is an array',       is_array($trend), true);
check('a forecast is produced',      is_array($cast), true);
check('recommendations come back',   is_array($advice), true);

printf("    %d months of history, %d month(s) of data, %d recommendation(s)\n",
    count($history), Insights::monthsOfData(), count($advice));

/* HONESTY ABOUT THIN DATA IS THE POINT OF THIS ONE.
   A few probe days are not a series, and the forecast says so rather than
   producing a number: it returns `limitation`, `reason`, `months_of_data` and
   `months_needed`. My first version looked for a key called `confidence` and
   read its absence as the forecast being silent about certainty — it is not
   silent, it names the method and refuses. */
check('the forecast names its method', array_key_exists('method', $cast), true);
check('and states its limitation', array_key_exists('limitation', $cast), true);
/* THE FORECAST HAS TWO SHAPES AND THIS ONCE ONLY KNEW ONE.
   With no arrivals in the database it always took the refusal branch, so the
   assertions below were written against that branch alone and passed for
   months without the productive path ever running. The moment sample data was
   loaded, `months_needed` stopped being returned — correctly, because there is
   now enough history — and this suite failed on working code.

   Both branches are checked now, and which one is being exercised is printed,
   so a run against an empty database and a run against a seeded one are
   distinguishable in the output rather than looking identical. */
if (empty($cast['available'])) {
    echo "    (refusing to forecast — not enough history)\n";

    check('it says how much history it has',
        array_key_exists('months_of_data', $cast) && array_key_exists('months_needed', $cast), true);
    check('and does not pretend', array_key_exists('reason', $cast), true);
    check('and offers no number', array_key_exists('estimate', $cast), false);
} else {
    printf("    (forecasting %s: %s visitors, range %s-%s, confidence %s)\n",
        (string) ($cast['target_month'] ?? '?'),
        number_format((float) ($cast['estimate'] ?? 0)),
        number_format((float) ($cast['range_low'] ?? 0)),
        number_format((float) ($cast['range_high'] ?? 0)),
        (string) ($cast['confidence'] ?? '?'));

    check('it produces a number',        is_numeric($cast['estimate'] ?? null), true);
    check('with a range around it',
        isset($cast['range_low'], $cast['range_high'])
        && $cast['range_low'] <= $cast['estimate']
        && $cast['estimate']  <= $cast['range_high'], true);
    check('it names the month forecast', !empty($cast['target_month']), true);
    check('and states its confidence',
        in_array($cast['confidence'] ?? '', ['low', 'moderate', 'high'], true), true);

    /* One year of history cannot support a seasonal claim, and the system is
       supposed to say so rather than imply two years of pattern. */
    check('confidence is not overstated on one year of data',
        Insights::monthsOfData() >= 24 || ($cast['confidence'] ?? '') !== 'high', true);
}

echo "\n--- the AI endpoint: guarded, and NOT called ---\n";

/* No question is sent to Gemini. What matters here is that the endpoint cannot
   be posted to by anybody who finds the URL — the API key behind it is the
   office's, and the quota is theirs to spend. */
$ch = curl_init(test_base_url() . '/api/chat/ask.php');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => ['question' => 'ZZ probe'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
]);
curl_exec($ch);
$noToken = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

check('a request with no CSRF token is refused 403', $noToken, 403);

$key = (string) config('ai.api_key', config('gemini.api_key', ''));

printf("    model: %s   key: %s\n",
    (string) config('ai.model', config('gemini.model', '(unset)')),
    $key === '' ? 'NOT CONFIGURED' : 'configured (' . strlen($key) . ' chars)');

check('an AI key is configured', $key !== '', true);

echo "\n--- clean up ---\n";

Database::run('DELETE FROM arrival_report_days WHERE report_id = ?', [$reportId]);
Database::run('DELETE FROM arrival_reports WHERE id = ?', [$reportId]);
Database::run(
    'DELETE FROM arrival_daily_summary WHERE destination_id = ? AND visit_date BETWEEN ? AND ?',
    [$did, $start, $end]
);

check('no probe report survives',
    (int) Database::scalar('SELECT COUNT(*) FROM arrival_reports WHERE period_start = ?', [$start]), 0);
check('and no probe figures',
    (int) Database::scalar(
        'SELECT COUNT(*) FROM arrival_daily_summary WHERE visit_date BETWEEN ? AND ?',
        [$start, $end]), 0);

test_finish();
