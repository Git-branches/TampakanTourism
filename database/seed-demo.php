<?php
declare(strict_types=1);

/**
 * TourSync — sample data for demonstrating the system before it is launched.
 *
 * The office is shown this system on a laptop months before anything goes live,
 * and until now that demonstration was of a system with nothing in it: three of
 * the five headline features read zero, because `tourist_arrivals` was empty.
 * Every screen opened, every button worked, and there was nothing to look at.
 *
 * WHAT THIS IS NOT. It is not a fixture for the test suite — those build and
 * remove their own rows. It is not migration data. It exists to be seen by
 * people in a room, and then removed.
 *
 * EVERY ROW IS REVERSIBLE. Not by matching on a marker in a column — a marker
 * can be edited away by the very people demonstrating the system, and then the
 * cleanup silently spares rows or, far worse, takes real ones. Every insert is
 * recorded by table and id in a manifest, and --undo deletes exactly those ids
 * and nothing else. Run it twice and the second run does nothing, because the
 * manifest already accounts for what exists.
 *
 *     php database/seed-demo.php            what it would write, writes nothing
 *     php database/seed-demo.php --write    writes it
 *     php database/seed-demo.php --status   what is currently seeded
 *     php database/seed-demo.php --undo     removes exactly what was seeded
 *
 * The default is a dry run on purpose. This writes to the same database the
 * office's real destinations and photographs live in.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database;
use App\Repositories\ArrivalRepository;

/* ---------------------------------------------------------------------------
   The manifest
   ------------------------------------------------------------------------ */

const MANIFEST = __DIR__ . '/../storage/demo-seed.json';

/** @return array<string, list<int>> */
function manifest_read(): array
{
    if (!is_file(MANIFEST)) {
        return [];
    }

    $data = json_decode((string) file_get_contents(MANIFEST), true);

    return is_array($data) ? $data : [];
}

/** @param array<string, list<int>> $data */
function manifest_write(array $data): void
{
    file_put_contents(MANIFEST, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* ---------------------------------------------------------------------------
   Tampakan, and the places people come from
   ---------------------------------------------------------------------------
   Real municipalities, because the arrival classifier sorts on them and sample
   data made of invented towns would exercise none of that. A visitor from
   Polomolok must come out as domestic-within-province; one from Danlag must
   come out as local.
   ------------------------------------------------------------------------ */

const TAMPAKAN_BARANGAYS = [
    'Albagan', 'Buto', 'Danlag', 'Kipalbig', 'Lambayong', 'Liberty', 'Maltana',
    'Palo', 'Poblacion', 'Pula Bato', 'San Isidro', 'Santa Cruz', 'Tablu',
];

/** Same province — still domestic, but the nearby kind. */
const SOUTH_COTABATO = [
    'Koronadal City', 'Banga', 'Lake Sebu', 'Norala', 'Polomolok',
    'Santo Niño', 'Surallah', "T'boli", 'Tantangan', 'Tupi',
];

/** Elsewhere in the country, roughly in the order Tampakan actually sees them. */
const DOMESTIC_ELSEWHERE = [
    ['General Santos City', 'South Cotabato'],
    ['Davao City',          'Davao del Sur'],
    ['Digos City',          'Davao del Sur'],
    ['Kidapawan City',      'Cotabato'],
    ['Cotabato City',       'Maguindanao del Norte'],
    ['Tacurong City',       'Sultan Kudarat'],
    ['Isulan',              'Sultan Kudarat'],
    ['Cagayan de Oro City', 'Misamis Oriental'],
    ['Zamboanga City',      'Zamboanga del Sur'],
    ['Iloilo City',         'Iloilo'],
    ['Cebu City',           'Cebu'],
    ['Quezon City',         'Metro Manila'],
    ['Makati City',         'Metro Manila'],
];

const FOREIGN = [
    ['South Korea', 'Korean'],   ['Japan', 'Japanese'],
    ['United States', 'American'], ['Germany', 'German'],
    ['Australia', 'Australian'], ['Canada', 'Canadian'],
    ['China', 'Chinese'],        ['Singapore', 'Singaporean'],
];

const GIVEN = [
    'Maria', 'Jose', 'Ana', 'Juan', 'Rosalinda', 'Ricardo', 'Cristina', 'Danilo',
    'Grace', 'Arnel', 'Jennifer', 'Rolando', 'Marilou', 'Edgar', '蔡', 'Divina',
    'Noel', 'Liezl', 'Ramon', 'Jocelyn', 'Ferdinand', 'Aileen', 'Bernardo',
    'Charmaine', 'Dexter', 'Elena', 'Fidel', 'Gina', 'Hector', 'Imelda',
    'Jomar', 'Kristine', 'Lorenzo', 'Michelle', 'Nestor', 'Olivia', 'Patricio',
    'Queenie', 'Rodel', 'Sheila', 'Teodoro', 'Ursula', 'Victor', 'Wilma',
];

const SURNAME = [
    'Alcantara', 'Bautista', 'Cabrera', 'Dela Cruz', 'Escobar', 'Fernandez',
    'Gallardo', 'Hernandez', 'Ignacio', 'Jimenez', 'Katigbak', 'Lagunda',
    'Magbanua', 'Nazareno', 'Ocampo', 'Pascual', 'Quirante', 'Ramos',
    'Salazar', 'Tolentino', 'Urbano', 'Villanueva', 'Yumang', 'Zabala',
    'Adarna', 'Bacaltos', 'Cadungog', 'Dagohoy', 'Espinosa', 'Fuentes',
];

const FOREIGN_NAMES = [
    'Kim Min-jun', 'Park Ji-woo', 'Sato Haruto', 'Tanaka Yui', 'Michael Brooks',
    'Sarah Whitfield', 'Lukas Weber', 'Anna Schmidt', 'James Callaghan',
    'Emily Turner', 'Chen Wei', 'Li Na', 'Daniel Ong', 'Rachel Lim',
];

/* ---------------------------------------------------------------------------
   Extra destinations
   ---------------------------------------------------------------------------
   Two active destinations exercise almost nothing: no pagination, no ranking
   worth the name, a map with two pins, and a QR sheet that fits on one line.
   Five is the smallest number where those screens behave like themselves.
   *
   * THESE NAMES ARE A STARTING POINT, NOT A CLAIM. Susong Dalaga and Knife Edge
   * come from photographs already sitting in assets/img, so they are what the
   * office appears to have in mind; the third is a placeholder. The
   * descriptions below deliberately say only what any visitor could see for
   * themselves — no history, no cultural claim, no fee, nothing this file is in
   * a position to know. Replace all of it with the office's own wording before
   * anyone reads it as fact.
   *
   * Coordinates are inside the municipality so the map and the distance check
   * have something real to work with, but they are approximate.
   ------------------------------------------------------------------------ */

const EXTRA_DESTINATIONS = [
    [
        'name'      => 'Susong Dalaga',
        'category'  => 'Nature',
        'barangay'  => 'Maltana',
        'lat'       => 6.4512,
        'lng'       => 124.9603,
        'short'     => 'Twin forested peaks rising above the pineapple fields, best seen in the morning light.',
        'body'      => 'A pair of steep, rounded hills visible from the road across open farmland. '
                     . 'Most visitors come for the view from the fields rather than to climb.',
        'hours'     => 'Daylight hours',
        'fee'       => 'None',
        'safety'    => 'The viewing area is private farmland. Stay on the road shoulder and do not enter '
                     . 'planted rows without asking.',
    ],
    [
        'name'      => 'Knife Edge',
        'category'  => 'Adventure',
        'barangay'  => 'Danlag',
        'lat'       => 6.5031,
        'lng'       => 124.9812,
        'short'     => 'A narrow rock spine along the upper ridge, with a long drop on both sides.',
        'body'      => 'A short but exposed section of the ridge trail. It is the part of the walk people '
                     . 'photograph, and the part that needs the most care.',
        'hours'     => '5:00 AM - 3:00 PM, last ascent 1:00 PM',
        'fee'       => 'Registration at the barangay tourism desk',
        'safety'    => 'Exposed on both sides with no railing. Not advisable in rain or strong wind. '
                     . 'Register at the barangay desk and go with a guide.',
    ],
    [
        'name'      => 'Tablu Pineapple Fields',
        'category'  => 'Agri-Tourism',
        'barangay'  => 'Tablu',
        'lat'       => 6.4287,
        'lng'       => 124.9341,
        'short'     => 'Rows of pineapple running to the treeline, with Mt. Matutum behind them on clear days.',
        'body'      => 'Working farmland rather than an attraction built for visitors. The interest is the '
                     . 'view across the rows and the mountain beyond.',
        'hours'     => 'Daylight hours',
        'fee'       => 'None',
        'safety'    => 'Active farmland with vehicles and spraying schedules. Ask before entering and keep '
                     . 'to the access tracks.',
    ],

    /* The five below exist so the LIST behaves like a list: two pages at six a
       page, every category represented, and one featured. Named after real
       barangays of Tampakan with plain descriptors — placeholders, all of
       them. */
    [
        'name'      => 'Kipalbig Spring',
        'category'  => 'Nature',
        'barangay'  => 'Kipalbig',
        'lat'       => 6.4694,
        'lng'       => 124.9022,
        'short'     => 'A cold spring pool shaded by old trees, a short walk from the barangay road.',
        'body'      => 'Water rises clear and cold here year round. The pool is shallow and the ground '
                     . 'around it is often wet and slippery.',
        'hours'     => '6:00 AM - 5:00 PM',
        'fee'       => 'None',
        'safety'    => 'Slippery rock around the pool. Children should not be left unattended.',
    ],
    [
        'name'      => 'Danlag Weaving Center',
        'category'  => 'Culture',
        'barangay'  => 'Danlag',
        'lat'       => 6.4998,
        'lng'       => 124.9744,
        'featured'  => true,
        'short'     => 'A working space where weavers from the barangay meet, and where finished cloth is sold.',
        'body'      => 'Visitors can watch work in progress when weavers are present. Whether anyone is '
                     . 'working on a given day depends on the season and the household.',
        'hours'     => '8:00 AM - 4:00 PM, Monday to Saturday',
        'fee'       => 'None; cloth is sold by the weavers',
        'safety'    => 'Ask before photographing anyone at work.',
    ],
    [
        'name'      => 'Buto Falls',
        'category'  => 'Waterfalls',
        'barangay'  => 'Buto',
        'lat'       => 6.4405,
        'lng'       => 124.9889,
        'short'     => 'A single drop into a rock basin, reached by a short but steep footpath.',
        'body'      => 'The path down is unpaved and follows the stream. Water volume changes sharply '
                     . 'between the dry months and the rains.',
        'hours'     => '6:00 AM - 4:00 PM',
        'fee'       => 'None',
        'safety'    => 'The footpath is steep and unpaved. Do not attempt the descent during or after '
                     . 'heavy rain — the stream rises quickly.',
    ],
    [
        'name'      => 'Liberty Forest Trail',
        'category'  => 'Eco-Tourism',
        'barangay'  => 'Liberty',
        'lat'       => 6.4571,
        'lng'       => 124.9455,
        'short'     => 'A marked walking trail through second-growth forest on the lower slopes.',
        'body'      => 'A gentle walk of about two hours, marked at intervals. Birds are most active in '
                     . 'the first hours after sunrise.',
        'hours'     => '5:30 AM - 4:00 PM',
        'fee'       => 'None',
        'safety'    => 'Stay on the marked trail. Tell the barangay desk before walking alone.',
    ],
    [
        'name'      => 'Palo Viewdeck',
        'category'  => 'Nature',
        'barangay'  => 'Palo',
        'lat'       => 6.4166,
        'lng'       => 124.9218,
        'short'     => 'A roadside deck looking across the valley toward Mt. Matutum.',
        'body'      => 'The nearest thing to an easy viewpoint in the municipality: reachable by vehicle, '
                     . 'with no walking required.',
        'hours'     => 'Open at all hours; unlit after dark',
        'fee'       => 'None',
        'safety'    => 'No lighting after dark and the road shoulder is narrow. Park well off the '
                     . 'carriageway.',
    ],
];

/* ---------------------------------------------------------------------------
   Announcements
   ---------------------------------------------------------------------------
   The homepage shows six at a time with Previous and Next behind them. With
   the two notices on file it showed one card and hid its own buttons — the
   layout was correct and demonstrated nothing.

   Written the way a municipal tourism office writes: what changed, when, and
   what the visitor should do about it. Every type is represented so the filter
   chips above the strip each have something to find.
   ------------------------------------------------------------------------ */

const ANNOUNCEMENTS = [
    ['advisory', -2,
        'Trail conditions after the weekend rain',
        'The upper sections are soft and slippery. Boots are advised and the ridge is not recommended for children under twelve until the ground dries.'],
    ['closure', -6,
        'Buto Falls closed for footpath repair',
        'The descent to the basin is being re-cut and stepped. The falls reopen once the work is signed off; the viewing platform above stays open throughout.'],
    ['event', 9,
        'Tampakan Founding Anniversary',
        'A week of programmes at the Poblacion grounds with a tourism booth, a weavers\' market, and guided walks leaving each morning.'],
    ['reminder', -1,
        'Please sign the logbook at every destination',
        'Scanning the QR code and filling in the short form is what tells the Office how many people visited. It takes under a minute and no name is published anywhere.'],
    ['schedule', -4,
        'Barangay report submission: first week of the month',
        'Destination managers submit the previous month\'s logbook within the first seven days. Late submissions are not counted in the figures filed with the Department.'],
    ['announcement', -9,
        'Accredited tour guides now listed online',
        'Every guide accredited by the Office carries an ID with a QR code. Scanning it opens their record on this site, so a visitor can check an accreditation on the spot.'],
    ['advisory', -13,
        'Carry water on the ridge trails',
        'There is no potable water between the trail head and the summit. Two litres per person is the guidance from the barangay tourism desks.'],
    ['event', 24,
        'Highland Harvest Weekend',
        'Farm visits, a pineapple market, and an evening programme at the municipal plaza. Transport leaves from the Poblacion terminal each morning.'],
    ['reminder', -17,
        'Respect the markers at heritage sites',
        'Several sites hold stones and markers placed by the families of the barangay. Please do not climb on them, move them, or take anything from around them.'],
    ['announcement', -21,
        'Offline maps available on every QR page',
        'Each destination\'s page now carries a downloadable route sketch, so a visitor without signal on the trail still has directions on their phone.'],
];

/* ---------------------------------------------------------------------------
   Small helpers
   ------------------------------------------------------------------------ */

function pick(array $a)
{
    return $a[array_rand($a)];
}

/** A name that is plausible without being anybody in particular. */
function filipino_name(): string
{
    return pick(GIVEN) . ' ' . pick(SURNAME);
}

/**
 * How busy a given day is.
 *
 * Tampakan sits at altitude and its dry season is the season people climb in.
 * March to May is the peak, December and January carry the holidays, and the
 * habagat months from June to September are quiet because the trails are mud.
 * Weekends are the visit; a Tuesday is a handful of people.
 */
function day_weight(DateTimeImmutable $d): float
{
    $month = (int) $d->format('n');
    $dow   = (int) $d->format('N');

    $season = match (true) {
        in_array($month, [3, 4, 5], true)  => 1.75,   // summer, the climbing season
        in_array($month, [12, 1], true)    => 1.55,   // holidays and cool weather
        $month === 2                       => 1.40,   // still dry season, still busy
        in_array($month, [10, 11], true)   => 1.05,   // the rains easing off
        in_array($month, [6, 7, 8, 9], true) => 0.45, // habagat, the trails are mud
        default                            => 0.85,
    };

    $week = match ($dow) {
        6       => 3.0,   // Saturday
        7       => 2.6,   // Sunday
        5       => 1.4,   // Friday
        default => 0.7,
    };

    return $season * $week;
}

/* ---------------------------------------------------------------------------
   What gets written
   ------------------------------------------------------------------------ */

$mode = $argv[1] ?? '';

$officer = (int) (Database::scalar(
    "SELECT id FROM admins WHERE role = 'officer' AND is_active = 1 ORDER BY id LIMIT 1") ?? 0);

$destinations = Database::all(
    "SELECT id, name, slug, qr_version FROM destinations WHERE status = 'active' ORDER BY id");

if ($destinations === []) {
    fwrite(STDERR, "No active destinations. Nothing to attach sample data to.\n");
    exit(1);
}

/* ---- status ------------------------------------------------------------- */

if ($mode === '--status') {
    $m = manifest_read();

    if ($m === []) {
        echo "Nothing seeded. (no " . basename(MANIFEST) . ")\n";
        exit(0);
    }

    echo "Seeded rows still recorded in the manifest:\n\n";

    $total = 0;

    foreach ($m as $table => $ids) {
        if ($table === '_files') {
            printf("  %-28s %s file(s)\n", $table, number_format(count($ids)));
            continue;
        }

        $live = $ids === [] ? 0 : (int) Database::scalar(
            "SELECT COUNT(*) FROM `$table` WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")");

        printf("  %-28s %6s recorded, %6s still present\n",
            $table, number_format(count($ids)), number_format($live));

        $total += count($ids);
    }

    printf("\n  %s rows total\n", number_format($total));
    exit(0);
}

/* ---- undo --------------------------------------------------------------- */

if ($mode === '--undo') {
    $m = manifest_read();

    if ($m === []) {
        echo "Nothing to undo.\n";
        exit(0);
    }

    /* Children before parents. */
    /* Children before parents, and the destinations themselves last of all —
       everything above points at them. */
    $order = [
        'arrival_report_entries', 'arrival_report_days', 'tourist_arrivals',
        'arrival_reports', 'destination_heritage', 'destination_photos', 'announcements',
        'destination_alerts', 'destination_change_requests', 'contact_messages',
        'destinations',
    ];

    echo "Removing sample data.\n\n";

    Database::transaction(static function () use ($m, $order): void {
        foreach ($order as $table) {
            $ids = $m[$table] ?? [];

            if ($ids === []) {
                continue;
            }

            $in = implode(',', array_map('intval', $ids));
            Database::run("DELETE FROM `$table` WHERE id IN ($in)");

            printf("  %-28s %s removed\n", $table, number_format(count($ids)));
        }
    });

    foreach ($m['_files'] ?? [] as $relative) {
        $path = dirname(__DIR__) . '/' . ltrim((string) $relative, '/');

        if (is_file($path)) {
            unlink($path);
        }
    }

    if (($m['_files'] ?? []) !== []) {
        printf("  %-28s %s removed\n", 'copied images', number_format(count($m['_files'])));
    }

    $rebuilt = ArrivalRepository::rebuildSummary();
    printf("\n  arrival_daily_summary rebuilt from what remains: %s row(s)\n", number_format($rebuilt));

    unlink(MANIFEST);
    echo "  manifest deleted\n\nDone.\n";
    exit(0);
}

/* ---- seed --------------------------------------------------------------- */

$write = $mode === '--write';

if (manifest_read() !== [] && $write) {
    fwrite(STDERR, "Sample data is already seeded. Run --undo first, or --status to see it.\n");
    exit(1);
}

$today = new DateTimeImmutable('today');
$from  = $today->modify('-12 months');

printf("%s\n", $write ? 'WRITING sample data.' : 'DRY RUN — nothing will be written. Add --write to commit.');
printf("  period      %s to %s\n", $from->format('M j, Y'), $today->format('M j, Y'));
printf("  attaching to %d active destination(s): %s\n\n",
    count($destinations), implode(', ', array_column($destinations, 'name')));

$manifest = ['tourist_arrivals' => [], 'arrival_reports' => [], 'arrival_report_entries' => [],
             'arrival_report_days' => [], 'destination_heritage' => [], 'destinations' => [], 'announcements' => [],
             'destination_photos' => [], '_files' => []];

mt_srand(20260830);   // the same sample every run, so a rehearsal is repeatable

/* ===========================================================================
   0. Destinations
   ---------------------------------------------------------------------------
   First, so everything below spreads across all of them rather than piling
   onto the two that already existed and leaving the new ones reading zero.
   ======================================================================== */

$photoPool = glob(dirname(__DIR__) . '/uploads/destinations/*.jpg') ?: [];
$existing  = array_column($destinations, 'name');
$toAdd     = array_values(array_filter(EXTRA_DESTINATIONS,
    static fn (array $d): bool => !in_array($d['name'], $existing, true)));

printf("  destinations to add: %d (%s)\n\n", count($toAdd),
    $toAdd === [] ? 'none — they already exist' : implode(', ', array_column($toAdd, 'name')));

if ($write && $toAdd !== []) {
    foreach ($toAdd as $i => $spec) {
        $categoryId = Database::scalar('SELECT id FROM categories WHERE name = ?', [$spec['category']]);

        Database::run(
            'INSERT INTO destinations
                (category_id, name, slug, short_description, description, operating_hours,
                 entrance_fee, safety_notes, barangay, address, latitude, longitude,
                 qr_token, qr_version, is_featured, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, "active", ?)',
            [
                $categoryId !== null ? (int) $categoryId : null,
                $spec['name'],
                strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $spec['name']), '-')),
                $spec['short'],
                $spec['body'],
                $spec['hours'],
                $spec['fee'],
                $spec['safety'],
                $spec['barangay'],
                'Barangay ' . $spec['barangay'] . ', Tampakan, South Cotabato',
                $spec['lat'],
                $spec['lng'],
                \App\Repositories\DestinationRepository::newToken(),
                !empty($spec['featured']) ? 1 : 0,
                $officer ?: null,
            ]
        );

        $newId = (int) Database::scalar('SELECT LAST_INSERT_ID()');
        $manifest['destinations'][] = $newId;

        /* A card with no photograph looks broken rather than empty, so each
           gets a few — copies, so removing them cannot touch a real one. */
        for ($p = 0; $p < 3 && $photoPool !== []; $p++) {
            $source = $photoPool[($i * 3 + $p) % count($photoPool)];
            $name   = bin2hex(random_bytes(16)) . '.jpg';
            $target = dirname(__DIR__) . '/uploads/destinations/' . $name;

            if (!copy($source, $target)) {
                continue;
            }

            $stored = 'uploads/destinations/' . $name;
            $manifest['_files'][] = $stored;

            Database::run(
                'INSERT INTO destination_photos (destination_id, file_path, caption, is_cover, sort_order)
                 VALUES (?, ?, ?, ?, ?)',
                [$newId, $stored, '', $p === 0 ? 1 : 0, $p]
            );

            $manifest['destination_photos'][] = (int) Database::scalar('SELECT LAST_INSERT_ID()');
        }
    }

    printf("  wrote %s destinations and %s photograph(s)\n\n",
        number_format(count($manifest['destinations'])),
        number_format(count($manifest['destination_photos'])));

    /* Everything below walks this list, so it has to include what was just
       created — otherwise the new destinations get no arrivals at all. */
    $destinations = Database::all(
        "SELECT id, name, slug, qr_version FROM destinations WHERE status = 'active' ORDER BY id");
}

/* A dry run creates nothing, so without this the counts it prints would be for
   two destinations and the real run would write far more than it promised. A
   preview that understates the write is worse than no preview. */
if (!$write) {
    foreach ($toAdd as $spec) {
        $destinations[] = ['id' => 0, 'name' => $spec['name'], 'slug' => '', 'qr_version' => 1];
    }
}

/* ===========================================================================
   1. Arrivals
   ======================================================================== */

$rows      = [];
$byMonth   = [];
$uuidSeed  = 0;

/* No two destinations draw the same crowd, and a ranking of five equal numbers
   is not a ranking. The falls take the most, the working farmland the least. */
$pullByRank = [1.0, 0.74, 0.52, 0.40, 0.31, 0.24, 0.19, 0.15, 0.11, 0.08];

foreach ($destinations as $i => $dest) {
    $pull = $pullByRank[$i] ?? 0.12;

    for ($day = $from; $day <= $today; $day = $day->modify('+1 day')) {
        $expected = day_weight($day) * 3.2 * $pull;
        $count    = (int) floor($expected) + (mt_rand(0, 99) < (int) (fmod($expected, 1) * 100) ? 1 : 0);

        for ($n = 0; $n < $count; $n++) {
            $roll = mt_rand(1, 100);

            if ($roll <= 44) {
                $type = 'local';
                $city = pick(TAMPAKAN_BARANGAYS);
                [$prov, $country, $nat, $name] = ['South Cotabato', 'Philippines', 'Filipino', filipino_name()];
            } elseif ($roll <= 72) {
                $type = 'domestic';
                $city = pick(SOUTH_COTABATO);
                [$prov, $country, $nat, $name] = ['South Cotabato', 'Philippines', 'Filipino', filipino_name()];
            } elseif ($roll <= 88) {
                $type = 'domestic';
                [$city, $prov] = pick(DOMESTIC_ELSEWHERE);
                [$country, $nat, $name] = ['Philippines', 'Filipino', filipino_name()];
            } elseif ($roll <= 96) {
                $type = 'foreign';
                [$country, $nat] = pick(FOREIGN);
                [$city, $prov, $name] = ['', '', pick(FOREIGN_NAMES)];
            } else {
                $type = 'overseas_filipino';
                [$city, $prov] = pick(DOMESTIC_ELSEWHERE);
                [$country, $nat, $name] = ['Philippines', 'Filipino', filipino_name()];
            }

            $group = mt_rand(1, 100) <= 55 ? mt_rand(1, 4) : 0;   // families and barkadas
            $hour  = mt_rand(6, 16);

            /* A small number of records the office has to deal with, because a
               moderation screen with nothing flagged demonstrates nothing. */
            $statusRoll = mt_rand(1, 1000);
            $status     = $statusRoll <= 12 ? 'flagged' : ($statusRoll <= 16 ? 'voided' : 'valid');

            $rows[] = [
                'destination_id'  => (int) $dest['id'],
                'visit_date'      => $day->format('Y-m-d'),
                'arrived_at'      => $day->setTime($hour, mt_rand(0, 59))->format('Y-m-d H:i:s'),
                'full_name'       => $name,
                'age_bracket'     => pick(['18-24', '25-34', '25-34', '35-44', '35-44', '45-54', '55-64', 'under18', '65plus']),
                'sex'             => pick(['male', 'female', 'female', 'male', 'prefer_not_to_say']),
                'tourist_type'    => $type,
                'stay_type'       => mt_rand(1, 100) <= 78 ? 'day_trip' : 'overnight',
                'nationality'     => $nat,
                'origin_country'  => $country,
                'origin_province' => $prov,
                'origin_city'     => $city,
                'purpose'         => pick(['leisure', 'leisure', 'leisure', 'leisure', 'education', 'vfr', 'business', 'religious']),
                'companions_count'=> $group,
                'total_visitors'  => 1 + $group,
                'consent_given'   => mt_rand(1, 100) <= 82 ? 1 : 0,
                'source'          => mt_rand(1, 100) <= 88 ? 'qr' : 'manual',
                'recorded_by'     => $officer ?: null,
                'qr_version_used' => (int) $dest['qr_version'],
                'client_uuid'     => sprintf('5eed%04x-%04x-4%03x-8%03x-%012x',
                                      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xfff),
                                      mt_rand(0, 0xfff), ++$uuidSeed),
                'status'          => $status,
                'flag_reason'     => $status === 'flagged' ? 'Two records from the same device within a minute' : null,
                'void_reason'     => $status === 'voided'  ? 'Duplicate of an earlier entry the same morning' : null,
            ];

            $byMonth[$day->format('Y-m')] = ($byMonth[$day->format('Y-m')] ?? 0) + 1 + $group;
        }
    }
}

printf("  arrivals to write: %s records across %d month(s)\n", number_format(count($rows)), count($byMonth));

ksort($byMonth);
$peak = max($byMonth);

foreach ($byMonth as $m => $v) {
    printf("    %s  %5s  %s\n", $m, number_format($v),
        str_repeat('#', max(1, (int) round($v / $peak * 46))));
}

if ($write) {
    $columns = array_keys($rows[0]);
    $list    = '`' . implode('`, `', $columns) . '`';
    $holder  = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';

    /* In chunks: one statement per row is thousands of round trips, and one
       statement for all of them exceeds max_allowed_packet. */
    foreach (array_chunk($rows, 200) as $chunk) {
        $params = [];

        foreach ($chunk as $row) {
            foreach ($columns as $c) {
                $params[] = $row[$c];
            }
        }

        Database::run(
            "INSERT INTO tourist_arrivals ($list) VALUES " .
            implode(',', array_fill(0, count($chunk), $holder)),
            $params
        );

        $first = (int) Database::scalar('SELECT LAST_INSERT_ID()');

        for ($k = 0; $k < count($chunk); $k++) {
            $manifest['tourist_arrivals'][] = $first + $k;
        }
    }

    printf("\n  wrote %s arrivals\n", number_format(count($manifest['tourist_arrivals'])));

    $summary = ArrivalRepository::rebuildSummary();
    printf("  arrival_daily_summary rebuilt: %s row(s)\n", number_format($summary));
}

/* ===========================================================================
   2. Barangay arrival reports
   ---------------------------------------------------------------------------
   The review queue is the screen the office is being asked to approve, so it
   needs one of everything: something waiting for them, something they already
   approved, something they sent back, and a draft nobody has submitted.
   ======================================================================== */

/* ONE REPORT PER MONTH, NOT SIX IN TOTAL.
 *
 * The DOT visitor record counts only arrivals that sit behind an APPROVED
 * report — a deliberate rule, and the right one: nothing reaches the sheet the
 * office signs without an officer having reviewed it. The first version of this
 * seeder wrote arrivals with no report at all, so every one of them was
 * excluded and the DOT form printed a full page of dashes for a month with
 * 2,232 recorded visitors.
 *
 * So there is a report for every month an arrival exists in, the older ones
 * approved, and the arrivals are attached to them below. The recent months stay
 * unapproved on purpose: the review queue needs something in it, and the DOT
 * form needs to demonstrate that it excludes what has not been reviewed. */
$reportPlan = [];

for ($ago = 0; $ago <= 13; $ago++) {
    $reportPlan[] = [
        'months_ago' => $ago,
        'status'     => match (true) {
            $ago === 0            => 'draft',
            $ago <= 2             => 'submitted',
            $ago === 3            => 'rejected',
            default               => 'approved',
        },
        'note'    => match (true) {
            $ago === 0 => 'Being encoded now.',
            $ago <= 2  => 'Monthly logbook for review.',
            $ago === 3 => 'Sheet 2 was unreadable — pages missing.',
            default    => 'Logbook transcribed at the barangay tourism desk.',
        },
        /* Transcribed lines only for the recent months. Thirteen months of them
           for ten destinations is tens of thousands of rows nobody will look
           at, and the screen that reads them shows one month at a time. */
        'detail'  => $ago <= 5,
    ];
}

printf("\n  reports to write: %d (%s)\n", count($reportPlan) * count($destinations),
    implode(', ', array_unique(array_column($reportPlan, 'status'))));

if ($write) {
    foreach ($destinations as $dest) {
        foreach ($reportPlan as $plan) {
            /* Anchored to the first of the month BEFORE subtracting.
               "30 August minus 6 months" is 30 February, which PHP rolls
               forward to 2 March — so February was skipped entirely and its
               1,544 visitors sat in no report at all, invisible on the DOT
               sheet with nothing to say why. Subtracting from the 1st cannot
               overflow. */
            $start = $today->modify('first day of this month')
                           ->modify('-' . $plan['months_ago'] . ' months');
            $end   = $start->modify('last day of this month');

            Database::run(
                'INSERT INTO arrival_reports
                    (destination_id, period_start, period_end, period_type, status, notes,
                     submitted_by, submitted_at, reviewed_by, reviewed_at, rejection_reason)
                 VALUES (?, ?, ?, "monthly", ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $dest['id'],
                    $start->format('Y-m-d'),
                    $end->format('Y-m-d'),
                    $plan['status'],
                    $plan['note'],
                    $plan['status'] === 'draft' ? null : ($officer ?: null),
                    $plan['status'] === 'draft' ? null : $end->format('Y-m-d 16:30:00'),
                    in_array($plan['status'], ['approved', 'rejected'], true) ? ($officer ?: null) : null,
                    in_array($plan['status'], ['approved', 'rejected'], true)
                        ? $end->modify('+3 days')->format('Y-m-d 09:15:00') : null,
                    $plan['status'] === 'rejected'
                        ? 'Pages 3 and 4 of the logbook were not included. Please rescan and resubmit.' : null,
                ]
            );

            $reportId = (int) Database::scalar('SELECT LAST_INSERT_ID()');
            $manifest['arrival_reports'][] = $reportId;

            /* A handful of transcribed logbook lines and the day totals the
               office actually reads off them. */
            $row = 0;

            for ($d = $start; $plan['detail'] && $d <= $end && $d <= $today; $d = $d->modify('+1 day')) {
                if (day_weight($d) < 1.2) {
                    continue;   // the desk only kept a sheet on the busy days
                }

                $local = mt_rand(2, 9);
                $dom   = mt_rand(1, 7);
                $for   = mt_rand(0, 2);
                $ofw   = mt_rand(0, 1);

                Database::run(
                    'INSERT INTO arrival_report_days
                        (report_id, visit_date, local_count, domestic_count, foreign_count,
                         ofw_count, total_visitors)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$reportId, $d->format('Y-m-d'), $local, $dom, $for, $ofw, $local + $dom + $for + $ofw]
                );

                $manifest['arrival_report_days'][] = (int) Database::scalar('SELECT LAST_INSERT_ID()');

                for ($e = 0; $e < min(4, $local + $dom); $e++) {
                    $isLocal = mt_rand(1, 100) <= 60;
                    $city    = $isLocal ? pick(TAMPAKAN_BARANGAYS) : pick(SOUTH_COTABATO);

                    Database::run(
                        'INSERT INTO arrival_report_entries
                            (report_id, visit_date, row_no, full_name, address_text,
                             tourist_type, origin_city, origin_province, origin_country, confidence)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Philippines", ?)',
                        [
                            $reportId, $d->format('Y-m-d'), ++$row,
                            filipino_name(),
                            $city . ', ' . ($isLocal ? 'Tampakan' : 'South Cotabato'),
                            $isLocal ? 'local' : 'domestic',
                            $city, 'South Cotabato',
                            mt_rand(1, 100) <= 80 ? 'high' : 'low',
                        ]
                    );

                    $manifest['arrival_report_entries'][] = (int) Database::scalar('SELECT LAST_INSERT_ID()');
                }
            }
        }
    }

    printf("  wrote %s reports, %s day totals, %s transcribed lines\n",
        number_format(count($manifest['arrival_reports'])),
        number_format(count($manifest['arrival_report_days'])),
        number_format(count($manifest['arrival_report_entries'])));

    /* ATTACHING THE ARRIVALS TO THEIR REPORT.
     *
     * Without this every arrival has report_id NULL, which the DOT visitor
     * record reads — correctly — as "not approved by anybody" and leaves out.
     * Matched on destination and month, which is exactly what a monthly
     * submission covers. */
    $linked = 0;

    foreach ($manifest['arrival_reports'] as $reportId) {
        $r = Database::first('SELECT destination_id, period_start, period_end FROM arrival_reports WHERE id = ?',
            [$reportId]);

        if ($r === null) {
            continue;
        }

        Database::run(
            'UPDATE tourist_arrivals
                SET report_id = ?
              WHERE destination_id = ?
                AND visit_date BETWEEN ? AND ?
                AND report_id IS NULL',
            [$reportId, (int) $r['destination_id'], $r['period_start'], $r['period_end']]
        );

        $linked += (int) Database::scalar(
            'SELECT COUNT(*) FROM tourist_arrivals WHERE report_id = ?', [$reportId]);
    }

    $orphans = (int) Database::scalar('SELECT COUNT(*) FROM tourist_arrivals WHERE report_id IS NULL');
    $onSheet = (int) Database::scalar(
        "SELECT COALESCE(SUM(a.total_visitors), 0)
           FROM tourist_arrivals a
           JOIN arrival_reports r ON r.id = a.report_id AND r.status = 'approved'
          WHERE a.status = 'valid'");

    printf("  attached %s arrival(s) to a report; %s left unattached\n",
        number_format($linked), number_format($orphans));
    printf("  %s visitor(s) now sit behind an APPROVED report and will appear on the DOT sheet\n",
        number_format($onSheet));
}

/* ===========================================================================
   3. Heritage items on the QR page
   ---------------------------------------------------------------------------
   The images are COPIES of existing destination photographs, never references
   to them. HeritageRepository::delete() unlinks the file when no other item
   uses it — pointing at a real photograph would mean --undo deleting one of
   the office's own pictures.
   ======================================================================== */

$heritage = [
    ['Woven abaca and t\'nalak', 'Cloth woven on a backstrap loom by weavers from the surrounding barangays. '
        . 'Patterns are learned by memory rather than written down, which is why no two lengths are identical.'],
    ['The stone marker at the trail head', 'Placed by the families who first cleared the path to this site. '
        . 'Visitors are asked not to climb on it or move the stones around its base.'],
    ['Brassware and everyday tools', 'Cast and hammered locally and still used at gatherings. '
        . 'The pieces on display were lent by households in the barangay.'],
];

$pool = glob(dirname(__DIR__) . '/uploads/destinations/*.jpg') ?: [];

printf("\n  heritage items to write: %d (%d per destination)\n",
    count($heritage) * count($destinations), count($heritage));

if ($pool === []) {
    echo "    (no source photographs in uploads/destinations — heritage items will have no image)\n";
}

if ($write) {
    foreach ($destinations as $dest) {
        foreach ($heritage as $order => [$title, $body]) {
            $stored = '';

            if ($pool !== []) {
                $source = $pool[($order + (int) $dest['id']) % count($pool)];
                $name   = bin2hex(random_bytes(16)) . '.jpg';
                $target = dirname(__DIR__) . '/uploads/destinations/' . $name;

                if (copy($source, $target)) {
                    $stored = 'uploads/destinations/' . $name;
                    $manifest['_files'][] = $stored;
                }
            }

            Database::run(
                'INSERT INTO destination_heritage (destination_id, image_path, title, body, sort_order)
                 VALUES (?, ?, ?, ?, ?)',
                [(int) $dest['id'], $stored, $title, $body, $order + 1]
            );

            $manifest['destination_heritage'][] = (int) Database::scalar('SELECT LAST_INSERT_ID()');
        }
    }

    printf("  wrote %s heritage items and copied %s image(s)\n",
        number_format(count($manifest['destination_heritage'])),
        number_format(count($manifest['_files'])));
}

/* ===========================================================================
   4. Announcements
   ======================================================================== */

$existingSlugs = array_column(Database::all('SELECT slug FROM announcements'), 'slug');

$slugify = static fn (string $s): string =>
    substr(strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-')), 0, 200);

$newNotices = array_values(array_filter(ANNOUNCEMENTS,
    static fn (array $a): bool => !in_array($slugify($a[2]), $existingSlugs, true)));

printf("\n  announcements to write: %d\n", count($newNotices));

if ($write) {
    foreach ($newNotices as $i => [$type, $daysOff, $title, $summary]) {
        $when = $today->modify(($daysOff >= 0 ? '+' : '') . $daysOff . ' days');

        Database::run(
            'INSERT INTO announcements
                (title, slug, body, summary, type, audience, status,
                 event_date, event_location, publish_at, created_by)
             VALUES (?, ?, ?, ?, ?, "public", "published", ?, ?, ?, ?)',
            [
                $title,
                $slugify($title),
                $summary . "\n\n" . 'Enquiries: Municipal Tourism Office, Tampakan, South Cotabato.',
                $summary,
                $type,
                $type === 'event' ? $when->format('Y-m-d') : null,
                $type === 'event' ? 'Tampakan, South Cotabato' : null,
                /* Published in the past even for a future event: publish_at is
                   when the notice went up, not when the event happens. A
                   publish_at in the future would hide it from the homepage. */
                $today->modify('-' . (30 + $i) . ' days')->format('Y-m-d H:i:s'),
                $officer ?: null,
            ]
        );

        $manifest['announcements'][] = (int) Database::scalar('SELECT LAST_INSERT_ID()');
    }

    printf("  wrote %s announcement(s)\n", number_format(count($manifest['announcements'] ?? [])));
}

/* ---------------------------------------------------------------------- */

if (!$write) {
    echo "\nNothing was written. Run again with --write to commit.\n";
    exit(0);
}

manifest_write($manifest);

printf("\nDone. Manifest: %s\n", MANIFEST);
echo "Remove all of it with:  php database/seed-demo.php --undo\n";
