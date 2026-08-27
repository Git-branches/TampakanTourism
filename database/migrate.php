<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — schema migrations
 * -----------------------------------------------------------------------------
 *  Applies structural changes to an existing database without destroying data.
 *  install.php drops and recreates every table; this does not, which is what
 *  makes it safe to run against a system that already holds arrival records.
 *
 *      php database/migrate.php
 *
 *  Each migration is guarded so running the script twice is harmless.
 * =============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Migrations run from the command line only.');
}

$config = require dirname(__DIR__) . '/app/config/config.php';
$db = $config['database'];

$pdo = new PDO(
    "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
    $db['user'], $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo "\nTourSync — migrations\n" . str_repeat('=', 60) . "\n";

/** Adds a column only when it is missing. */
$addColumn = static function (PDO $pdo, string $table, string $column, string $definition) use ($db): void {
    $exists = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $exists->execute([$db['name'], $table, $column]);

    if ($exists->fetchColumn()) {
        echo "  skip  {$table}.{$column} — already present\n";
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    echo "  ok    {$table}.{$column} added\n";
};

// -----------------------------------------------------------------------------
// 2026-08 — Track when a password was last changed.
//
// The installer generates the first password and prints it to a terminal.
// Without this column there is no way to tell whether the officer ever changed
// it, and a system in production still using an installer-generated password
// is a finding waiting to happen.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'admins', 'password_changed_at', 'password_changed_at DATETIME NULL AFTER password_hash');

// -----------------------------------------------------------------------------
// 2026-08 — Record when personal fields were anonymised.
//
// RA 10173 asks that personal data not be kept longer than the purpose
// requires. The retention job clears identifying columns while leaving the
// counts intact; this records that it happened so the office can show it did.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'tourist_arrivals', 'anonymised_at', 'anonymised_at DATETIME NULL AFTER created_at');

/** Adds an index only when it is missing. */
$addIndex = static function (PDO $pdo, string $table, string $index, string $definition) use ($db): void {
    $exists = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $exists->execute([$db['name'], $table, $index]);

    if ($exists->fetchColumn()) {
        echo "  skip  {$table}.{$index} — already present\n";
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
    echo "  ok    {$table}.{$index} added\n";
};

// -----------------------------------------------------------------------------
// 2026-08 — Offline capture and synchronisation.                    Feature 2
//
// An arrival written on a phone with no signal is held on that device and sent
// later. Two columns are what make that safe.
//
// client_uuid is generated on the device before the record leaves it, and is
// UNIQUE. A sync that is interrupted after the insert but before the device
// hears the answer will be retried, and the retry has to be recognised as the
// same arrival rather than counted twice. Without this column the honest
// failure mode of a flaky connection is inflated tourism figures — which is
// precisely the number this system exists to keep defensible.
//
// synced_at separates the two clocks. arrived_at is when the visitor stood at
// the destination; synced_at is when the record reached the server. Equal for
// an online submission, hours apart for an offline one — and the gap is the
// evidence that the offline path did its job.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'tourist_arrivals', 'client_uuid', 'client_uuid CHAR(36) NULL AFTER device_hash');
$addColumn($pdo, 'tourist_arrivals', 'synced_at',   'synced_at DATETIME NULL AFTER client_uuid');

$addIndex($pdo, 'tourist_arrivals', 'uniq_arr_client_uuid', 'UNIQUE KEY uniq_arr_client_uuid (client_uuid)');

// -----------------------------------------------------------------------------
// 2026-08 — Budget planning lines.                                   Feature 4
//
// The office's own cost drivers, one row per budget line. Deliberately a table
// rather than constants in code: these are policy figures the Tourism Officer
// must be able to revise without a developer, and must be able to defend line
// by line to the Mayor and to COA.
//
// unit_cost is DECIMAL, never a float. Money compared or summed as binary
// floating point eventually disagrees with the ledger by a centavo, and a
// budget that does not foot is a budget that gets sent back.
// -----------------------------------------------------------------------------
$tableExists = static function (PDO $pdo, string $table) use ($db): bool {
    $q = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $q->execute([$db['name'], $table]);

    return (bool) $q->fetchColumn();
};

if ($tableExists($pdo, 'budget_lines')) {
    echo "  skip  budget_lines — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE budget_lines (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label       VARCHAR(160) NOT NULL,
            category    VARCHAR(80)  NOT NULL DEFAULT 'Operations',

            -- What the quantity is counted in. The planner multiplies the
            -- quantity this implies by unit_cost; nothing else varies.
            basis       ENUM('per_visitor','per_destination_month','per_destination_year','fixed_annual')
                        NOT NULL DEFAULT 'fixed_annual',

            unit_cost   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes       VARCHAR(255) NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_active   TINYINT(1) NOT NULL DEFAULT 1,
            updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_budget_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    budget_lines created\n";

    /* Seeded with the lines a municipal tourism office actually carries, and
       with every unit cost at zero.

       That is deliberate. Inventing plausible peso figures would produce a
       screen that looks authoritative and is fiction — the exact failure this
       whole feature was chosen over an AI to avoid. Zero is the honest
       starting value: nobody has set it yet, and the screen says so. */
    $seed = $pdo->prepare(
        'INSERT INTO budget_lines (label, category, basis, unit_cost, notes, sort_order)
         VALUES (?, ?, ?, 0.00, ?, ?)'
    );

    $lines = [
        ['Solid waste collection and disposal', 'Site operations', 'per_visitor',           'Cost per visitor for collection, hauling, and disposal.'],
        ['Potable water and sanitation supplies', 'Site operations', 'per_visitor',         'Consumables at comfort rooms and hand-washing points.'],
        ['First aid and emergency consumables', 'Visitor safety', 'per_visitor',            'Restocking of site first aid kits.'],
        ['Visitor desk personnel', 'Personnel', 'per_destination_month',                    'Honorarium or wage per destination, per month.'],
        ['Site maintenance and minor repairs', 'Site operations', 'per_destination_month',  'Trail clearing, railings, signage upkeep.'],
        ['QR signage replacement', 'Signage', 'per_destination_year',                       'Reprinting and remounting after weathering or damage.'],
        ['Guide accreditation and training', 'Personnel', 'fixed_annual',                   'Annual accreditation cycle for local guides.'],
        ['Promotional materials and campaigns', 'Promotion', 'fixed_annual',                'Print, digital, and event promotion for the year.'],
        ['Internet and system hosting', 'Administration', 'fixed_annual',                   'Hosting and connectivity for this system.'],
    ];

    foreach ($lines as $i => [$label, $category, $basis, $note]) {
        $seed->execute([$label, $category, $basis, $note, ($i + 1) * 10]);
    }

    echo "  ok    budget_lines seeded with " . count($lines) . " lines at zero cost\n";
}

/* Contingency, as a percentage the office sets. Zero until they choose one —
   same reasoning as the unit costs above. */
$hasSetting = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
$hasSetting->execute(['budget_contingency_pct']);

if ($hasSetting->fetchColumn()) {
    echo "  skip  budget_contingency_pct — already present\n";
} else {
    $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
        ->execute(['budget_contingency_pct', '0']);
    echo "  ok    budget_contingency_pct setting added\n";
}

// -----------------------------------------------------------------------------
// 2026-08 — Destination managers become accounts.                    Feature 2
//
// Until now this table was a contact list: a name, a mobile number, and an SMS
// opt-in. The office texted managers; managers could not reach the system at
// all, which is precisely why they still travel to the Municipal Tourism Office
// to hand over arrival reports on paper.
//
// These columns turn each row into something a manager can sign in with. The
// existing rows keep working as contacts — a manager with no username simply
// cannot log in yet, which is the correct state until the officer issues them
// credentials rather than a broken one.
//
// Credentials live here rather than in `admins` deliberately. A manager is not
// a weaker administrator; they are a different kind of user, scoped to one
// destination, and putting them in the admin table would mean every existing
// `Auth::require()` in the dashboard suddenly admits them.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'destination_managers', 'username',
    'username VARCHAR(60) NULL AFTER full_name');
$addColumn($pdo, 'destination_managers', 'password_hash',
    'password_hash VARCHAR(255) NULL AFTER username');
$addColumn($pdo, 'destination_managers', 'last_login_at',
    'last_login_at DATETIME NULL AFTER password_hash');
$addColumn($pdo, 'destination_managers', 'password_changed_at',
    'password_changed_at DATETIME NULL AFTER last_login_at');

/* Same lockout discipline the admin accounts already carry. A manager account
   reaches the same arrival data an officer does, for one destination — it does
   not get weaker brute-force protection because it is not called "admin". */
$addColumn($pdo, 'destination_managers', 'failed_attempts',
    'failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER password_changed_at');
$addColumn($pdo, 'destination_managers', 'locked_until',
    'locked_until DATETIME NULL AFTER failed_attempts');

$addIndex($pdo, 'destination_managers', 'uniq_manager_username',
    'UNIQUE KEY uniq_manager_username (username)');

// -----------------------------------------------------------------------------
// 2026-08 — Arrival reports submitted by destination managers.       Feature 2
//
// The problem this solves is a journey: a manager keeps a paper logbook at the
// destination and then travels to the Municipal Tourism Office to hand the
// figures over. These two tables are the electronic route.
//
// TWO TABLES, NOT ONE
//
//   arrival_reports       one submission — a destination, a period, and where
//                         it stands between the manager and the officer
//   arrival_report_days   the figures themselves, one row per calendar day
//
// Split because a submission is reviewed as a whole and counted by the day. A
// single flat table would either force one submission per day — which is the
// daily trip to the Office, in software — or bury a month of figures in one
// row that cannot be checked against a logbook page.
//
// WHERE APPROVED FIGURES GO
//
// Nowhere new. On approval the day rows are written into arrival_daily_summary,
// the rollup the dashboard, Insights, ReportBuilder and the budget planner
// already read. There is deliberately no second place the same visitors are
// counted; these tables hold the submission and its audit trail, and the
// existing summary stays the one source for statistics.
// -----------------------------------------------------------------------------
if ($tableExists($pdo, 'arrival_reports')) {
    echo "  skip  arrival_reports — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE arrival_reports (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            destination_id  INT UNSIGNED NOT NULL,

            period_start    DATE NOT NULL,
            period_end      DATE NOT NULL,

            -- draft      the manager is still working on it; invisible to the Office
            -- submitted  handed over, waiting to be looked at
            -- reviewing  an officer has opened it
            -- approved   counted — the figures are in arrival_daily_summary
            -- rejected   sent back with a reason the manager can act on
            status          ENUM('draft','submitted','reviewing','approved','rejected')
                            NOT NULL DEFAULT 'draft',

            notes           VARCHAR(500) NULL,

            submitted_by    INT UNSIGNED NULL,      -- destination_managers.id
            submitted_at    DATETIME NULL,
            reviewed_by     INT UNSIGNED NULL,      -- admins.id
            reviewed_at     DATETIME NULL,

            -- Required by the workflow when rejecting: a report sent back
            -- without a reason is a manager guessing what to change.
            rejection_reason VARCHAR(500) NULL,

            created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_ar_dest_period (destination_id, period_start),
            KEY idx_ar_status (status),

            CONSTRAINT fk_ar_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE RESTRICT,
            CONSTRAINT fk_ar_manager FOREIGN KEY (submitted_by)
                REFERENCES destination_managers (id) ON DELETE SET NULL,
            CONSTRAINT fk_ar_reviewer FOREIGN KEY (reviewed_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    arrival_reports created\n";
}

if ($tableExists($pdo, 'arrival_report_days')) {
    echo "  skip  arrival_report_days — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE arrival_report_days (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id     INT UNSIGNED NOT NULL,
            visit_date    DATE NOT NULL,

            -- The four categories the office already reports on. Deliberately
            -- the same names arrival_daily_summary uses, so approval is a copy
            -- rather than a translation, and the same words the Department of
            -- Tourism asks for.
            local_count    INT UNSIGNED NOT NULL DEFAULT 0,
            domestic_count INT UNSIGNED NOT NULL DEFAULT 0,
            foreign_count  INT UNSIGNED NOT NULL DEFAULT 0,
            ofw_count      INT UNSIGNED NOT NULL DEFAULT 0,

            -- Held as a stored column rather than trusted from the form: a
            -- total that disagrees with its parts is the single most common
            -- error on a hand-tallied logbook page, and the database should
            -- not be able to hold one.
            total_visitors INT UNSIGNED AS (local_count + domestic_count + foreign_count + ofw_count) STORED,

            -- Optional splits. The office asks for them; a logbook page does
            -- not always carry them, and a required field nobody can fill is a
            -- field that gets filled with a guess.
            male_count     INT UNSIGNED NULL,
            female_count   INT UNSIGNED NULL,
            children_count INT UNSIGNED NULL,
            adults_count   INT UNSIGNED NULL,
            seniors_count  INT UNSIGNED NULL,

            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            -- One row per date per report. Two rows for the same day inside one
            -- submission is a double count waiting to be approved.
            UNIQUE KEY uniq_ard_report_date (report_id, visit_date),

            CONSTRAINT fk_ard_report FOREIGN KEY (report_id)
                REFERENCES arrival_reports (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    arrival_report_days created\n";
}

// -----------------------------------------------------------------------------
// 2026-08 — Transcribed logbook entries.                             Feature 2
//
// The paper logbook at each destination has five columns: Name, Address,
// Contact no., Signature, and a single Date at the top of the page. The
// destination manager copies those rows in, one row per person, and the system
// derives the local/domestic/foreign/OFW split from the addresses — the split
// is where a hand tally goes wrong, and the address column is the only thing
// that can settle it.
//
// No new table: tourist_arrivals already carries full_name, contact_number,
// origin_city, origin_province, visit_date and tourist_type, along with
// consent_given and anonymised_at for retention. It was built for the QR
// logbook that Feature 1 no longer has, so it is standing empty with exactly
// the right shape. Three columns tie a row back to the page it came from.
//
//   report_id        which submission this row belongs to; cascades, so
//                    deleting a draft takes its transcription with it
//   logbook_address  the address EXACTLY as written on the paper. origin_city
//                    holds the resolved municipality for analytics, but a
//                    transcription that silently tidies its source cannot be
//                    checked against the photograph of the page
//   logbook_row      position on the page, so the digital page can be read
//                    line-by-line against the photograph
// -----------------------------------------------------------------------------
/* INT UNSIGNED, matching arrival_reports.id. A BIGINT here is refused by InnoDB
   with "foreign key constraint is incorrectly formed" — the widths have to
   agree exactly, not merely both be large enough. */
$addColumn($pdo, 'tourist_arrivals', 'report_id',
    'report_id INT UNSIGNED NULL AFTER destination_id');

$addColumn($pdo, 'tourist_arrivals', 'logbook_address',
    'logbook_address VARCHAR(160) NULL AFTER origin_city');

$addColumn($pdo, 'tourist_arrivals', 'logbook_row',
    'logbook_row SMALLINT UNSIGNED NULL AFTER logbook_address');

$addIndex($pdo, 'tourist_arrivals', 'idx_arr_report',
    'KEY idx_arr_report (report_id, visit_date, logbook_row)');

/* Cascade rather than SET NULL: a transcribed row has no meaning once its
   report is gone — it would become an arrival nobody can trace to a page. */
$addForeignKey = static function (PDO $pdo, string $table, string $name, string $definition) use ($db): void {
    $exists = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
    );
    $exists->execute([$db['name'], $table, $name]);

    if ($exists->fetchColumn()) {
        echo "  skip  {$table}.{$name} — already present\n";
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD CONSTRAINT {$definition}");
    echo "  ok    {$table}.{$name} added\n";
};

$addForeignKey($pdo, 'tourist_arrivals', 'fk_arr_report',
    'fk_arr_report FOREIGN KEY (report_id) REFERENCES arrival_reports (id) ON DELETE CASCADE');

// -----------------------------------------------------------------------------
// 2026-08 — The transcription itself, before it is accepted.          Feature 2
//
// One row per line on the paper page. This is deliberately NOT tourist_arrivals:
// a draft is not a record. Rows typed straight into tourist_arrivals would
// appear in the arrivals list, in analytics and in the DOT-format reports the
// moment the manager typed them — before any officer had looked at the page.
// The office would be publishing figures nobody had accepted.
//
// So the transcription lives here while it is being written and reviewed, and
// approval copies it across into tourist_arrivals. Withdrawing an approval
// deletes those copies again. Two places holding the same names is the cost;
// the alternative is unreviewed personal data leaking into every report the
// municipality produces.
//
// PRIVACY. These are names, addresses and contact numbers of private
// individuals — personal data under RA 10173. Reachable only by the destination
// manager who typed them and the Municipal Tourism Office. Never by the public
// site, and never by the chatbot's knowledge base.
// -----------------------------------------------------------------------------
if ($tableExists($pdo, 'arrival_report_entries')) {
    echo "  skip  arrival_report_entries — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE arrival_report_entries (
            id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,

            -- The date written once at the top of the paper page.
            visit_date DATE NOT NULL,

            -- Position on that page, so the typed page can be read line by line
            -- against the photograph of the original.
            row_no SMALLINT UNSIGNED NOT NULL,

            -- The five columns of the paper form. Only the name is required:
            -- real pages have blanks in the contact column, and a transcription
            -- that cannot represent a blank cannot represent the page.
            -- The signature stays on paper; the photograph is its record.
            full_name      VARCHAR(160) NOT NULL,
            address_text   VARCHAR(160) NULL,
            contact_number VARCHAR(40)  NULL,

            -- Derived from address_text, overridable by the manager.
            tourist_type ENUM('local','domestic','foreign','overseas_filipino') NOT NULL DEFAULT 'domestic',

            -- The resolved place, for analytics. address_text keeps what was
            -- actually written; these hold what the system made of it.
            origin_city     VARCHAR(120) NULL,
            origin_province VARCHAR(120) NULL,
            origin_country  VARCHAR(80)  NULL,

            -- 'low' means the classifier guessed. The form surfaces these so a
            -- manager can settle them rather than have a guess quietly become
            -- one of the municipality's statistics.
            confidence ENUM('high','low') NOT NULL DEFAULT 'low',

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_are_line (report_id, visit_date, row_no),
            KEY idx_are_date (report_id, visit_date),

            CONSTRAINT fk_are_report FOREIGN KEY (report_id)
                REFERENCES arrival_reports (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    arrival_report_entries created\n";
}

// -----------------------------------------------------------------------------
// 2026-08 — Supporting logbook documents.                            Feature 2
//
// The photograph or PDF of the paper page. A manager with a completed logbook
// should not have to key in twenty-five names to prove they had twenty-five
// visitors — they photograph the page and the office inspects the original.
//
// NOT stored under uploads/. That directory is publicly readable; its only
// protection is an unguessable filename, which is obscurity rather than access
// control. A logbook page carries names, home addresses and mobile numbers of
// private individuals, so these files live under storage/ — already
// "Require all denied" — and are served through a PHP endpoint that checks who
// is asking. A leaked URL is then worth nothing to anyone not signed in.
//
// stored_name is generated server-side and never derived from what the browser
// sent; original_name is kept only to show the manager which file they picked.
// -----------------------------------------------------------------------------
if ($tableExists($pdo, 'arrival_report_documents')) {
    echo "  skip  arrival_report_documents — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE arrival_report_documents (
            id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,

            -- Random, server-generated, no extension taken from the upload.
            stored_name VARCHAR(80) NOT NULL,

            -- Display only. Never used to build a path.
            original_name VARCHAR(200) NOT NULL,

            mime_type ENUM('image/jpeg','image/png','application/pdf') NOT NULL,
            byte_size INT UNSIGNED NOT NULL,

            -- Which page of the paper logbook this is, when the manager says.
            covers_date DATE NULL,
            caption     VARCHAR(200) NULL,

            uploaded_by INT UNSIGNED NULL,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_ard_stored (stored_name),
            KEY idx_ard_report (report_id),

            CONSTRAINT fk_ardoc_report FOREIGN KEY (report_id)
                REFERENCES arrival_reports (id) ON DELETE CASCADE,
            CONSTRAINT fk_ardoc_manager FOREIGN KEY (uploaded_by)
                REFERENCES destination_managers (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    arrival_report_documents created\n";
}

// -----------------------------------------------------------------------------
// 2026-08 — Reporting period type.                                   Feature 2
//
// Reuses the enum the existing reports table already defines rather than
// inventing a second vocabulary for the same idea. 'custom' is the default
// because a manager picking two arbitrary dates is the general case; daily,
// monthly and quarterly are the shapes the office asks for by name.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'arrival_reports', 'period_type',
    "period_type ENUM('daily','weekly','monthly','quarterly','annual','custom') NOT NULL DEFAULT 'custom' AFTER period_end");

// -----------------------------------------------------------------------------
// 2026-08 — Audit actor for destination managers.                    Feature 2
//
// activity_logs.admin_id references admins, so a manager's action logged
// NULL — the audit trail recorded that something happened and not who did it.
// A parallel column rather than a widened one: the two identities live in
// separate tables on purpose, and a single "user_id" that means different
// things depending on a sibling column is exactly the ambiguity an audit trail
// must not have.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'activity_logs', 'manager_id',
    'manager_id INT UNSIGNED NULL AFTER admin_id');

$addIndex($pdo, 'activity_logs', 'idx_log_manager',
    'KEY idx_log_manager (manager_id, created_at)');

$addForeignKey($pdo, 'activity_logs', 'fk_log_manager',
    'fk_log_manager FOREIGN KEY (manager_id) REFERENCES destination_managers (id) ON DELETE SET NULL');

// -----------------------------------------------------------------------------
// 2026-08 — Tourism Attraction Visitor Record.                       Feature 2
//
// The form the Municipal Tourism Office actually submits has an "Attraction
// Code" column beside each attraction name, and splits residence by PROVINCE —
// "This province" / "Other Province" / "Foreign Country" — not by municipality.
//
// The code is blank on the sample page, so it is optional here too: a column
// the office can fill in when the regional office issues codes, and which
// prints empty until then rather than inventing a value.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'destinations', 'attraction_code',
    'attraction_code VARCHAR(40) NULL AFTER slug');

// -----------------------------------------------------------------------------
// 2026-08 — Sex on a transcribed logbook line.                       Feature 2
//
// The visitor record form carries Male and Female columns for every residence
// category. The paper logbook has no sex column at all — it is Name, Address,
// Contact no., Signature, Date — so this cannot be transcribed from the page.
//
// NULLABLE, and it stays NULL unless somebody actually knows. The form's own
// note says "** Sex & ***Residence entries are optional. Total number of this
// month must be reported", so an unknown sex is a supported answer. Guessing it
// from a first name would put invented figures into a report to the DOT.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'arrival_report_entries', 'sex',
    "sex ENUM('male','female') NULL AFTER contact_number");

// -----------------------------------------------------------------------------
// 2026-08 — What the QR code actually carries.                       Feature 1
//
// The sign at a destination no longer opens a logbook. The tourist writes in the
// paper book at the fill-up station; the code opens the information they need
// while standing there:
//
//   spot information     already on destinations (hours, fee, facilities...)
//   emergency hotlines   who to ring, from a waterfall, with one bar of signal
//   cultural heritage    what the place means, which is why they came
//
// TWO KINDS OF HOTLINE, AND THEY LIVE IN DIFFERENT PLACES
//
// Municipal numbers — police station, MDRRMO, rural health unit — are the same
// for every destination in Tampakan. Those are settings: one place to correct
// when the station changes its number, instead of twenty destination records
// that quietly disagree with each other.
//
// Destination numbers — the caretaker on site, the nearest barangay — differ
// per spot, so they belong on the destination. contact_person and contact_phone
// already exist for the caretaker; local_hotline is the extra one a sign needs.
// -----------------------------------------------------------------------------
$addColumn($pdo, 'destinations', 'cultural_heritage',
    'cultural_heritage TEXT NULL AFTER history');

$addColumn($pdo, 'destinations', 'local_hotline',
    'local_hotline VARCHAR(120) NULL AFTER contact_phone');

$addColumn($pdo, 'destinations', 'safety_notes',
    'safety_notes TEXT NULL AFTER reminders');

/* Municipal hotlines, seeded blank. Blank on purpose: a placeholder number
   printed on a sign at a waterfall is worse than an empty row, because someone
   will dial it in an emergency. The office fills these in from Settings. */
foreach ([
    'hotline_emergency' => '',
    'hotline_police'    => '',
    'hotline_medical'   => '',
    'hotline_fire'      => '',
    'hotline_rescue'    => '',
    'hotline_tourism'   => '',
] as $key => $value) {
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_key = setting_key'
    );
    $stmt->execute([$key, $value]);
}

echo "  ok    municipal hotline settings seeded (blank until the office fills them)\n";

// =============================================================================
//  2026-08 — Digital Compliance Inspection / Tourism Standards Verification
// -----------------------------------------------------------------------------
//  The problem: a compliance inspection means an officer travelling to a
//  destination that may be an hour away over bad road, to look at a fire
//  extinguisher. Most of what is being checked is a thing that either exists or
//  does not, and a photograph settles it.
//
//  So the manager photographs the evidence and the office reviews it. What a
//  photograph CANNOT settle — a smell, a structural doubt, an extinguisher that
//  is present but expired and unreadable in the shot — the office escalates to
//  a site visit. The feature does not claim to replace inspection; it removes
//  the trips where nothing needed a person on site.
//
//  FOUR TABLES, AND WHY EACH IS SEPARATE
//
//    inspection_requirements  what the office asks for. A table rather than a
//                             constant, because the office adds requirements
//                             and a code deploy is not how a tourism officer
//                             should have to add "Fire Exit Plan".
//
//    inspection_reports       one submission by one destination. Carries the
//                             overall status and the site-visit decision.
//
//    inspection_items         one row per requirement per report. THIS is where
//                             a status lives, because the office marks each
//                             requirement individually — approving four of five
//                             is the normal case, and a single status on the
//                             report cannot express it.
//
//    inspection_photos        many photos per item. "Clean Restroom" is not one
//                             photograph, and forcing it to be one is how the
//                             office ends up asking for a second visit anyway.
// =============================================================================

if ($tableExists($pdo, 'inspection_requirements')) {
    echo "  skip  inspection_requirements — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE inspection_requirements (
            id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,

            -- What the office wants to see IN the photograph. The difference
            -- between 'Fire Extinguisher' and 'show the pressure gauge and the
            -- inspection tag' is the difference between one submission and
            -- three, so the guidance travels with the requirement.
            guidance TEXT NULL,

            -- Optional requirements exist: a destination with no restroom
            -- cannot photograph a clean one, and blocking their whole
            -- submission over it would push them back to a phone call.
            is_required TINYINT(1) NOT NULL DEFAULT 1,

            -- Retired rather than deleted. A requirement removed outright would
            -- orphan the items on every past report that answered it.
            is_active TINYINT(1) NOT NULL DEFAULT 1,

            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_ir_active (is_active, sort_order),
            CONSTRAINT fk_ir_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    inspection_requirements created\n";

    /* The five the client named, plus the guidance that stops a resubmission.
       Seeded only on creation, so an office that edits them keeps their edits. */
    $seed = $pdo->prepare(
        'INSERT INTO inspection_requirements (title, guidance, is_required, sort_order) VALUES (?, ?, ?, ?)'
    );

    foreach ([
        ['Fire Extinguisher',
         'Photograph the extinguisher where it is mounted. Include the pressure gauge and the inspection tag if they can be read.', 1, 10],
        ['First Aid Kit',
         'Photograph the kit open, so the contents are visible, and a second photo showing where it is kept.', 1, 20],
        ['Clean Restroom',
         'Photograph each restroom: the bowl, the sink, and the water supply. Show that it is operational, not only tidy.', 0, 30],
        ['Emergency and Safety Signage',
         'Photograph the exit signs, hazard warnings, and any depth or trail markers. Stand far enough back that the sign is readable.', 1, 40],
        ['Clean and Safe Tourist Area',
         'Photograph the main visitor area, the walkways, and any waste bins. Include anything a visitor could trip on or fall from.', 1, 50],
    ] as $row) {
        $seed->execute($row);
    }

    echo "  ok    5 standard requirements seeded\n";
}

if ($tableExists($pdo, 'inspection_reports')) {
    echo "  skip  inspection_reports — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE inspection_reports (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            destination_id INT UNSIGNED NOT NULL,

            status ENUM('draft','submitted','reviewing','approved','rejected')
                   NOT NULL DEFAULT 'draft',

            submitted_by INT UNSIGNED NULL,
            submitted_at DATETIME NULL,
            reviewed_by  INT UNSIGNED NULL,
            reviewed_at  DATETIME NULL,

            -- The office's overall message back. Per-requirement comments live
            -- on the item; this is the covering note.
            office_remarks VARCHAR(1000) NULL,

            -- Compliance has a shelf life. An approval from two years ago is
            -- not evidence that the extinguisher is still charged.
            valid_until DATE NULL,

            -- What a photograph could not settle. Set by the office when the
            -- evidence is inconclusive rather than wrong — the honest middle
            -- between approving and rejecting.
            site_visit_required TINYINT(1) NOT NULL DEFAULT 0,
            site_visit_at       DATETIME NULL,
            site_visit_note     VARCHAR(500) NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_insp_dest   (destination_id, status),
            KEY idx_insp_status (status, submitted_at),

            CONSTRAINT fk_insp_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE CASCADE,
            CONSTRAINT fk_insp_mgr FOREIGN KEY (submitted_by)
                REFERENCES destination_managers (id) ON DELETE SET NULL,
            CONSTRAINT fk_insp_admin FOREIGN KEY (reviewed_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    inspection_reports created\n";
}

if ($tableExists($pdo, 'inspection_items')) {
    echo "  skip  inspection_items — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE inspection_items (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_id      INT UNSIGNED NOT NULL,
            requirement_id INT UNSIGNED NOT NULL,

            -- 'needs_revision' is not a synonym for rejected. Rejected means
            -- the requirement is not met; needs_revision means the office
            -- cannot tell from what was sent. The manager acts differently on
            -- each, so the system has to say which it means.
            status ENUM('pending','submitted','approved','rejected','needs_revision')
                   NOT NULL DEFAULT 'pending',

            -- The manager's note about their own evidence.
            remarks VARCHAR(600) NULL,

            -- The office's note back about this requirement specifically.
            office_comment VARCHAR(600) NULL,

            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            -- One row per requirement per report. Two would mean two answers to
            -- the same question on the same submission.
            UNIQUE KEY uniq_item (report_id, requirement_id),
            KEY idx_item_status (report_id, status),

            CONSTRAINT fk_item_report FOREIGN KEY (report_id)
                REFERENCES inspection_reports (id) ON DELETE CASCADE,
            CONSTRAINT fk_item_req FOREIGN KEY (requirement_id)
                REFERENCES inspection_requirements (id) ON DELETE CASCADE,
            CONSTRAINT fk_item_admin FOREIGN KEY (reviewed_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    inspection_items created\n";
}

if ($tableExists($pdo, 'inspection_photos')) {
    echo "  skip  inspection_photos — already present\n";
} else {
    /* Same storage discipline as the logbook documents: random server-side
       name, original kept for display only, file under storage/ behind the
       deny-all rule and served through an authorising endpoint. PDF is not
       accepted here — the requirement is a photograph of a thing. */
    $pdo->exec("
        CREATE TABLE inspection_photos (
            id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_id BIGINT UNSIGNED NOT NULL,

            stored_name   VARCHAR(80)  NOT NULL,
            original_name VARCHAR(200) NOT NULL,
            mime_type     ENUM('image/jpeg','image/png') NOT NULL,
            byte_size     INT UNSIGNED NOT NULL,

            caption VARCHAR(300) NULL,

            uploaded_by INT UNSIGNED NULL,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_photo_stored (stored_name),
            KEY idx_photo_item (item_id),

            CONSTRAINT fk_photo_item FOREIGN KEY (item_id)
                REFERENCES inspection_items (id) ON DELETE CASCADE,
            CONSTRAINT fk_photo_mgr FOREIGN KEY (uploaded_by)
                REFERENCES destination_managers (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    inspection_photos created\n";
}

// =============================================================================
//  2026-08 — Destination alerts, raised by the manager                Feature 3
// -----------------------------------------------------------------------------
//  The existing SMS machinery goes one way: the office blasts an announcement
//  to managers. This is the other direction, and it is the direction that
//  matters in an emergency.
//
//  A landslide closes the trail at Jadas Falls. The manager is standing at the
//  waterfall. There is no data signal — there rarely is — but there is one bar
//  of GSM. They text the office, and the office knows within a minute instead
//  of when somebody drives out on Friday.
//
//  TWO CHANNELS, ONE TABLE
//
//  A manager with a signal uses the portal; a manager with only GSM sends a
//  text. Both produce the same alert and appear in the same inbox, because the
//  officer reading it should not have to check two places to learn the same
//  fact.
//
//  WHY THE SENDER'S NUMBER IS STORED EVEN WHEN IT MATCHES A MANAGER
//
//  An inbound text is unauthenticated by nature. Matching the number to a known
//  manager is the best identification available, and it is not proof — numbers
//  can be spoofed by a determined sender. Keeping the raw number means an
//  officer can see what actually arrived, and an alert from an unrecognised
//  number is quarantined rather than dropped, because the one time it matters
//  it may be a bystander reporting a drowning.
// =============================================================================

if ($tableExists($pdo, 'destination_alerts')) {
    echo "  skip  destination_alerts — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE destination_alerts (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

            -- NULL when a text arrives from a number nobody recognises. The
            -- alert is still recorded; the office decides what it is.
            destination_id INT UNSIGNED NULL,
            raised_by      INT UNSIGNED NULL,

            channel ENUM('portal','sms') NOT NULL DEFAULT 'portal',

            category ENUM('closure','hazard','accident','weather','utility','crowding','other')
                     NOT NULL DEFAULT 'other',

            -- 'urgent' means someone is in danger or the site must close now.
            -- Kept to three levels on purpose: a scale with seven points is a
            -- scale nobody calibrates the same way twice.
            severity ENUM('info','warning','urgent') NOT NULL DEFAULT 'warning',

            message VARCHAR(1000) NOT NULL,

            -- Exactly what arrived, before any parsing. An officer doubting how
            -- the system read a text needs to see the text.
            raw_text     VARCHAR(1000) NULL,
            from_number  VARCHAR(20) NULL,
            provider_ref VARCHAR(120) NULL,

            status ENUM('new','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'new',

            acknowledged_by INT UNSIGNED NULL,
            acknowledged_at DATETIME NULL,
            resolved_at     DATETIME NULL,
            resolution_note VARCHAR(600) NULL,

            -- Whether the office texted the manager back. An acknowledgement
            -- the manager never receives has not acknowledged anything.
            reply_sent_at DATETIME NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_alert_status (status, severity, created_at),
            KEY idx_alert_dest   (destination_id, created_at),

            CONSTRAINT fk_alert_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE SET NULL,
            CONSTRAINT fk_alert_mgr FOREIGN KEY (raised_by)
                REFERENCES destination_managers (id) ON DELETE SET NULL,
            CONSTRAINT fk_alert_admin FOREIGN KEY (acknowledged_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    destination_alerts created\n";
}

// -----------------------------------------------------------------------------
//  Every inbound text, accepted or not.
//
//  Separate from destination_alerts because most of what a webhook receives is
//  not an alert: duplicates the provider retries, texts from wrong numbers,
//  and — the reason this table exists — attempts to post to the endpoint by
//  someone who found the URL. An alerts table that also held those would be
//  unreadable, and dropping them silently would leave nothing to look at when
//  a manager insists they sent a text that never appeared.
// -----------------------------------------------------------------------------
if ($tableExists($pdo, 'sms_inbox')) {
    echo "  skip  sms_inbox — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE sms_inbox (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            from_number  VARCHAR(20) NULL,
            body         VARCHAR(1000) NULL,
            provider_ref VARCHAR(120) NULL,

            outcome ENUM('alert_created','unknown_sender','duplicate','rejected','empty')
                    NOT NULL DEFAULT 'rejected',

            alert_id   INT UNSIGNED NULL,
            note       VARCHAR(255) NULL,
            ip_address VARBINARY(16) NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            -- The provider's own message id, when it gives one. A retry after a
            -- timeout must not raise the same alert twice.
            UNIQUE KEY uniq_inbox_ref (provider_ref),
            KEY idx_inbox_outcome (outcome, created_at),

            CONSTRAINT fk_inbox_alert FOREIGN KEY (alert_id)
                REFERENCES destination_alerts (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    sms_inbox created\n";
}

/* The shared secret the webhook checks. Blank until the office sets it, and the
   endpoint refuses everything while it is blank — an open inbound endpoint is
   an invitation to write alerts into a municipal system. */
$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_key = setting_key'
);
$stmt->execute(['sms_inbound_secret', '']);

echo "  ok    sms_inbound_secret seeded (blank — the webhook refuses everything until it is set)\n";

// =============================================================================
//  2026-08 — Alert notifications follow the ACCOUNT, not a typed list
// -----------------------------------------------------------------------------
//  A destination manager reports something through the system. The system texts
//  whoever is responsible, because the officer is not sitting in front of the
//  dashboard — they are in a meeting, or out at a site. Then the office replies
//  through the system, and the manager gets that reply as a text too.
//
//  The numbers used to live in one settings row, typed by hand. That row is a
//  standing hazard: a staff member leaves, their account is deactivated, and
//  their phone keeps receiving municipal alerts because nobody remembered a
//  setting on another screen. Tied to the account, deactivating the account
//  stops the texts, and a new hire who fills in their number starts receiving
//  them without anyone being asked to remember.
//
//  opt_in is separate from having a number: an officer may want the number on
//  file for the record and still not want a text at eleven at night.
// =============================================================================
$addColumn($pdo, 'admins', 'mobile_number',
    'mobile_number VARCHAR(20) NULL AFTER email');

$addColumn($pdo, 'admins', 'alert_sms_opt_in',
    'alert_sms_opt_in TINYINT(1) NOT NULL DEFAULT 1 AFTER mobile_number');

/* Managers already carry mobile_number and sms_opt_in for announcement blasts.
   This is the separate question of whether they want the OFFICE'S REPLY to
   their own report as a text — a different thing from municipal announcements,
   and someone may reasonably want one and not the other. */
$addColumn($pdo, 'destination_managers', 'reply_sms_opt_in',
    'reply_sms_opt_in TINYINT(1) NOT NULL DEFAULT 1 AFTER sms_opt_in');

/* How serious a report has to be before it becomes a text. 'warning' by
   default: a manager writing "closed for maintenance" is exactly the message
   this flow exists to carry, and it is not an emergency. Set to 'urgent' if the
   balance is tight; 'info' texts everything, which is how a recipient learns to
   ignore the messages. */
$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_key = setting_key'
);
$stmt->execute(['alert_sms_threshold', 'warning']);

/* The office's answer to the manager, kept apart from resolution_note.
   They are different sentences to different readers: "Barangay rescue is on the
   way" is what the manager needs to hear now; "Trail cleared, reopened 2pm" is
   what the record needs afterwards. Collapsing them into one field means one of
   the two goes unwritten. */
$addColumn($pdo, 'destination_alerts', 'office_reply',
    'office_reply VARCHAR(600) NULL AFTER resolution_note');

$addColumn($pdo, 'destination_alerts', 'replied_by',
    'replied_by INT UNSIGNED NULL AFTER office_reply');

$addColumn($pdo, 'destination_alerts', 'replied_at',
    'replied_at DATETIME NULL AFTER replied_by');

echo "  ok    alert notification settings seeded\n";

// =============================================================================
//  2026-08 — Tour guide requests.
// -----------------------------------------------------------------------------
//  A tourist asks for a guide; the Municipal Tourism Office finds one.
//
//  WHY THIS IS NOT A BOOKING SYSTEM
//
//  The office does not keep a roster of accredited guides, does not hold their
//  calendars, and assigns whoever is free by phoning around. A schema built for
//  availability windows and confirmations would describe a process that does not
//  exist, and every one of those columns would sit empty. What the office
//  actually needs is the request written down, a way to say yes, and the guide's
//  name and number recorded once it is arranged — which is what this is.
//
//  WHY THE VISITOR'S NUMBER IS REQUIRED AND THE EMAIL IS NOT
//
//  The reply goes out by SMS. A request the office cannot answer is a request
//  that wastes everyone's afternoon, and in Tampakan a mobile number is the
//  contact a visitor reliably has. Email is kept as an optional second channel
//  for the occasional foreign visitor on roaming data.
//
//  RETENTION
//
//  visitor_name and contact_number are personal data under RA 10173, held for
//  as long as the request is live and a short while after so the office can
//  answer a follow-up call. anonymised_at mirrors tourist_arrivals so the same
//  retention job can clear these without deleting the count.
// =============================================================================

if ($tableExists($pdo, 'tour_guide_requests')) {
    echo "  skip  tour_guide_requests — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE tour_guide_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

            -- Spoken aloud on the phone: 'about request TG-2A7F'. An id is
            -- read back wrongly; four unambiguous characters are not.
            reference_code VARCHAR(12) NOT NULL,

            -- NULL when someone asks from the website without naming a spot.
            -- The office still wants the request.
            destination_id INT UNSIGNED NULL,

            -- 'qr' means they were standing at the destination when they asked,
            -- which changes how soon the office needs to answer.
            source ENUM('qr','website') NOT NULL DEFAULT 'website',

            visitor_name   VARCHAR(120) NOT NULL,
            contact_number VARCHAR(20)  NOT NULL,
            contact_email  VARCHAR(190) NULL,

            party_size     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            preferred_date DATE NULL,

            -- Free text, not an enum. 'morning', 'after lunch' and '7am sharp'
            -- are all things people write, and none of them survive a dropdown.
            preferred_time VARCHAR(40)  NULL,
            notes          VARCHAR(600) NULL,

            -- 'assigned' is the state the office cares about: a guide has a
            -- name and a number and the visitor has been told. 'declined' needs
            -- a reason, enforced in the repository rather than here.
            status ENUM('new','acknowledged','assigned','completed','declined','cancelled')
                   NOT NULL DEFAULT 'new',

            -- Free text on purpose. See the note above about the roster.
            guide_name    VARCHAR(120) NULL,
            guide_contact VARCHAR(20)  NULL,

            office_note VARCHAR(600) NULL,

            handled_by INT UNSIGNED NULL,
            handled_at DATETIME NULL,

            -- Whether the office's phone actually rang, and whether the visitor
            -- was actually told. A request answered in a browser that the
            -- visitor never learns about has not been answered.
            office_notified_at  DATETIME NULL,
            visitor_notified_at DATETIME NULL,

            -- Abuse control, same device hash the review form uses.
            device_hash CHAR(64) NULL,

            anonymised_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_guide_ref (reference_code),
            KEY idx_guide_status (status, created_at),
            KEY idx_guide_dest   (destination_id, created_at),

            CONSTRAINT fk_guide_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE SET NULL,
            CONSTRAINT fk_guide_admin FOREIGN KEY (handled_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    tour_guide_requests created\n";
}

/* Whether the office wants a text for every guide request.
   On by default — the whole point of the feature is that the office learns
   about it before the visitor gives up and leaves. */
$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_key = setting_key'
);
$stmt->execute(['guide_sms_enabled', '1']);

echo "  ok    tour guide request settings seeded\n";

// =============================================================================
//  2026-08 — Getting there.
// -----------------------------------------------------------------------------
//  WHY A TABLE AND NOT A COLUMN
//
//  "Directions" is not one paragraph. A visitor coming from General Santos
//  needs a different first sentence from one already standing at the municipal
//  gym, and the office knows both routes. One text column forces those into a
//  single wall of prose that is wrong for everybody.
//
//  WHY LANDMARKS AND NOT COORDINATES
//
//  Nobody in Tampakan gives directions in decimal degrees. They say "past the
//  National High School, left at the covered court, follow the concrete road
//  until it turns to gravel". That is the direction that actually works, and it
//  keeps working when the signal does not — which is the whole point, because
//  the last kilometre of most of these routes has no signal at all.
// =============================================================================

if ($tableExists($pdo, 'destination_routes')) {
    echo "  skip  destination_routes — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE destination_routes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            destination_id INT UNSIGNED NOT NULL,

            -- Where the directions start FROM. A place a stranger can find and
            -- a tricycle driver recognises by name.
            from_landmark VARCHAR(160) NOT NULL,

            -- The directions themselves, written the way they are spoken.
            directions TEXT NOT NULL,

            -- Both free text. '20-30 minutes' and 'about 45 mins by habal-habal'
            -- are the honest answers; a DECIMAL column would force a precision
            -- the office does not have.
            travel_time VARCHAR(60) NULL,
            distance    VARCHAR(60) NULL,

            -- 'Tricycle, habal-habal, or private vehicle (4x4 in wet season)'.
            transport VARCHAR(160) NULL,

            -- What it costs to get there by public transport, when known.
            fare_note VARCHAR(160) NULL,

            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_route_dest (destination_id, sort_order),

            CONSTRAINT fk_route_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    destination_routes created\n";
}

/* A map the visitor can keep. Uploaded by the office rather than generated,
   because the useful map here is often a hand-drawn sketch of a trail junction
   that no tile server has ever seen. */
$addColumn($pdo, 'destinations', 'offline_map_image',
    'offline_map_image VARCHAR(255) NULL AFTER longitude');

// =============================================================================
//  2026-08 — Managers proposing changes to their own destination.
// -----------------------------------------------------------------------------
//  A manager may not edit the destination record directly. The website is the
//  municipality's official statement about the place, and a live edit box on it
//  is an unreviewed change to a government publication.
//
//  So a manager proposes, and the office publishes. The proposal is stored as
//  the SET OF FIELDS THEY WANT CHANGED, not as a finished row: storing a whole
//  destination row would silently revert anything the office changed in the
//  meantime the moment the proposal was approved.
// =============================================================================

if ($tableExists($pdo, 'destination_change_requests')) {
    echo "  skip  destination_change_requests — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE destination_change_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            destination_id INT UNSIGNED NOT NULL,
            requested_by   INT UNSIGNED NULL,

            -- JSON: {\"operating_hours\": \"6am-5pm\", \"entrance_fee\": \"P30\"}.
            -- Only the fields being changed appear. Validated against an
            -- allow-list in the repository — a manager must not be able to
            -- propose a change to a column the office never exposed.
            proposed JSON NOT NULL,

            -- Why. The office is being asked to change a public statement and
            -- deserves a sentence explaining it.
            reason VARCHAR(600) NULL,

            status ENUM('pending','approved','rejected','withdrawn')
                   NOT NULL DEFAULT 'pending',

            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            review_note VARCHAR(600) NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_dcr_status (status, created_at),
            KEY idx_dcr_dest   (destination_id, created_at),

            CONSTRAINT fk_dcr_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE CASCADE,
            CONSTRAINT fk_dcr_mgr FOREIGN KEY (requested_by)
                REFERENCES destination_managers (id) ON DELETE SET NULL,
            CONSTRAINT fk_dcr_admin FOREIGN KEY (reviewed_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    destination_change_requests created\n";
}

// =============================================================================
//  2026-08 — The homepage contact form.
// -----------------------------------------------------------------------------
//  The form has been on the site since the first commit and has never sent
//  anything anywhere: it validated in the browser, showed a success message,
//  and discarded the message. Every enquiry made through it is gone.
//
//  Stored rather than emailed because there is no mail sender configured on
//  this host, and a message in a table the office can open is worth more than a
//  message handed to a mail() call that silently fails on cPanel.
// =============================================================================

if ($tableExists($pdo, 'contact_messages')) {
    echo "  skip  contact_messages — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE contact_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

            name    VARCHAR(120) NOT NULL,
            email   VARCHAR(190) NOT NULL,
            phone   VARCHAR(40)  NULL,
            subject VARCHAR(120) NOT NULL,
            message VARCHAR(2000) NOT NULL,

            status ENUM('new','read','answered','spam') NOT NULL DEFAULT 'new',

            handled_by INT UNSIGNED NULL,
            handled_at DATETIME NULL,
            office_note VARCHAR(600) NULL,

            device_hash CHAR(64) NULL,
            anonymised_at DATETIME NULL,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_contact_status (status, created_at),

            CONSTRAINT fk_contact_admin FOREIGN KEY (handled_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    contact_messages created\n";
}

// =============================================================================
//  2026-08 — 'road' as an alert category in its own right.
// -----------------------------------------------------------------------------
//  A manager reporting a washed-out approach road could already file it as
//  'hazard'. But the client asked for road conditions by name, and a category
//  list is a vocabulary: if the word the manager is thinking of is not in the
//  list, they pick 'other' and the classification stops meaning anything.
//
//  ADDED, never reordered. MySQL stores ENUM values by ordinal position, so
//  inserting 'road' in the middle would silently relabel every existing row.
// =============================================================================

$categoryColumn = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'destination_alerts'
        AND COLUMN_NAME = 'category'"
)->fetchColumn();

if (is_string($categoryColumn) && str_contains($categoryColumn, "'road'")) {
    echo "  skip  destination_alerts.category — 'road' already present\n";
} else {
    $pdo->exec(
        "ALTER TABLE destination_alerts
         MODIFY category ENUM('closure','hazard','accident','weather','utility','crowding','other','road')
                NOT NULL DEFAULT 'other'"
    );
    echo "  ok    destination_alerts.category — 'road' added\n";
}

// =============================================================================
//  2026-08 — Culture and heritage that belongs to the municipality.
// -----------------------------------------------------------------------------
//  destinations.cultural_heritage describes ONE place. The client asked for a
//  section about Tampakan itself that appears on every QR code — the same words
//  at Bulol Falls as at Kolon Ridge, because it is the same municipality.
//
//  A setting rather than a table: there is exactly one of these, it is edited by
//  the officer, and a table with a single row is a table somebody eventually
//  puts a second row in.
// =============================================================================

$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_key = setting_key'
);

$stmt->execute(['municipal_heritage', '']);
$stmt->execute(['municipal_heritage_title', 'Local Culture & Heritage of Tampakan']);

echo "  ok    municipal heritage settings seeded\n";

// =============================================================================
//  2026-08 — Promotional videos.
// -----------------------------------------------------------------------------
//  WHAT THIS REPLACES
//
//  The homepage looked for a hero clip by globbing assets/video/*.mp4. Nothing
//  in the admin panel could put a file there, so changing the video meant FTP
//  access — and the office had already dropped Tampakan.mp4 into uploads/Video/
//  by hand, where no page ever looked at it. Their video has been on the server
//  and off the website since August.
//
//  TWO SOURCES, ONE TABLE
//
//  An uploaded file, or a link to YouTube or Facebook. Both are needed and for
//  a plain reason: PHP here accepts 40MB per upload, which is around a minute
//  of 1080p. Anything longer has to live somewhere else, and telling a tourism
//  office they may not use YouTube is telling them not to use the feature.
//
//  Kept in one table rather than two because everything else about them is the
//  same — title, caption, which destination, published or not, what order.
// =============================================================================

if ($tableExists($pdo, 'promo_videos')) {
    echo "  skip  promo_videos — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE promo_videos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

            title   VARCHAR(160) NOT NULL,
            caption VARCHAR(600) NULL,

            -- Which place this is about. NULL for a municipal campaign that is
            -- not about one destination — a festival, a season, the town itself.
            destination_id INT UNSIGNED NULL,

            source ENUM('upload','external') NOT NULL DEFAULT 'upload',

            -- Set when source = 'upload'. Relative to the project root, the same
            -- shape as destination_photos.file_path.
            file_path VARCHAR(255) NULL,
            mime_type VARCHAR(60)  NULL,
            file_size INT UNSIGNED NULL,

            -- Set when source = 'external'. The page URL as the office pasted
            -- it; the embed URL is derived at render time so a change in what
            -- YouTube accepts does not require rewriting stored data.
            external_url VARCHAR(500) NULL,

            -- The still frame shown before playback. Optional for an upload
            -- (the browser shows its own first frame) and unused for external,
            -- which brings its own thumbnail.
            poster_path VARCHAR(255) NULL,

            -- At most one video is the homepage hero. Enforced in the
            -- repository rather than by a unique index, because the index would
            -- have to be on a nullable expression and MySQL would let two rows
            -- hold 0 but refuse a second 1 in a way that is hard to read later.
            is_hero TINYINT(1) NOT NULL DEFAULT 0,

            status ENUM('draft','published') NOT NULL DEFAULT 'draft',

            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_video_public (status, sort_order, id),
            KEY idx_video_dest   (destination_id, status),

            CONSTRAINT fk_video_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE SET NULL,
            CONSTRAINT fk_video_admin FOREIGN KEY (created_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    promo_videos created\n";
}

// =============================================================================
//  2026-08 — The tour guide booking record.
// -----------------------------------------------------------------------------
//  The client asked for a digital receipt the tourist can hold, and for the
//  arrangement to finish in person at the Municipal Tourism Office rather than
//  being treated as a confirmed booking the moment the form is sent.
//
//  Two columns carry that. issued_at marks when the receipt became viewable —
//  which is at submission, because a tourist who has just filled in a form is
//  owed something to show for it. met_at is stamped by the office when the
//  tourist actually turns up, which is the only moment anybody can honestly say
//  the guide and the visitor have found each other.
// =============================================================================

$addColumn($pdo, 'tour_guide_requests', 'issued_at',
    'issued_at DATETIME NULL AFTER reference_code');

$addColumn($pdo, 'tour_guide_requests', 'met_at',
    'met_at DATETIME NULL AFTER visitor_notified_at');

/* Existing rows predate the receipt, so they are backfilled from their own
   creation time rather than left NULL — otherwise a request made yesterday
   would show no receipt at all. */
$pdo->exec('UPDATE tour_guide_requests SET issued_at = created_at WHERE issued_at IS NULL');

echo "  ok    tour guide booking receipt columns\n";

// =============================================================================
//  2026-08 — Videos get a kind, and one of them leads.
// -----------------------------------------------------------------------------
//  A destination's videos were a flat list under a heading that said "Video".
//  That is fine for one clip and wrong for five: the office's own promotional
//  film, last year's, the fiesta, and something a visitor sent in are four
//  different things to a reader, and stacking them unlabelled makes the newest
//  upload look like the official one.
//
//  WHY A FEATURED FLAG RATHER THAN "the first by sort_order"
//
//  Sort order answers "in what sequence", not "which one is the office's
//  statement about this place". They come apart the moment somebody reorders
//  the list — and the featured clip is the one that gets the large frame and the
//  destination's name in the heading, so it has to be chosen deliberately.
//
//  At most one per destination, enforced in the repository. A unique index would
//  have to cover (destination_id, is_featured) and would then refuse a second
//  row holding 0, which is the common case.
// =============================================================================

$addColumn($pdo, 'promo_videos', 'category',
    "category ENUM('promo','event','archive','visitor') NOT NULL DEFAULT 'promo' AFTER destination_id");

$addColumn($pdo, 'promo_videos', 'is_featured',
    'is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_hero');

/* Anything already published against a destination becomes that destination's
   featured clip, unless it already has one. Without this every existing video
   drops into the secondary list and the office's only film stops being the one
   with its name on the heading — a silent demotion nobody asked for.

   NOT NULL-safe: a video with no destination is skipped, because "featured on
   the destination page" means nothing without a destination. */
$existing = $pdo->query(
    "SELECT destination_id, MIN(id) AS first_id
       FROM promo_videos
      WHERE destination_id IS NOT NULL AND status = 'published'
      GROUP BY destination_id"
)->fetchAll(PDO::FETCH_ASSOC);

$promote = $pdo->prepare(
    'UPDATE promo_videos SET is_featured = 1
      WHERE id = ?
        AND NOT EXISTS (
            SELECT 1 FROM (SELECT * FROM promo_videos) AS held
             WHERE held.destination_id = ? AND held.is_featured = 1
        )'
);

$promoted = 0;

foreach ($existing as $row) {
    $promote->execute([(int) $row['first_id'], (int) $row['destination_id']]);
    $promoted += $promote->rowCount();
}

echo "  ok    promo_videos.category and is_featured ({$promoted} existing video(s) promoted)\n";


// =============================================================================
//  2026-08 — The office needs to see the calendar, not just the queue.
// -----------------------------------------------------------------------------
//  A guide request carries preferred_date, and until now nothing looked at it.
//  A request accepted three weeks ahead was assigned and then forgotten, and
//  the first anyone knew of the visit was the visitor standing at the counter.
//
//  An assigned request whose date has passed also sat open for ever, because
//  the only way to close one was to remember it existed.
//
//  'no_show' is added because "completed" for a visit that never happened is a
//  false record — and this office reports its numbers upward. A guide whose
//  afternoon was held open deserves the distinction too.
// =============================================================================

$guideStatus = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tour_guide_requests'
        AND COLUMN_NAME = 'status'"
)->fetchColumn();

if (is_string($guideStatus) && str_contains($guideStatus, "'no_show'")) {
    echo "  skip  tour_guide_requests.status — 'no_show' already present\n";
} else {
    $pdo->exec(
        "ALTER TABLE tour_guide_requests
         MODIFY status ENUM('new','acknowledged','assigned','completed',
                            'declined','cancelled','no_show')
                NOT NULL DEFAULT 'new'"
    );
    echo "  ok    tour_guide_requests.status — 'no_show' added\n";
}

/* The calendar view reads preferred_date with a status filter on every load. */
$addIndex($pdo, 'tour_guide_requests', 'idx_guide_date_status',
    'KEY idx_guide_date_status (preferred_date, status)');

// =============================================================================
//  2026-08 - Where the visitor actually meets their guide.
// -----------------------------------------------------------------------------
//  The receipt told every visitor to come to the Municipal Tourism Office and
//  meet their guide there. The form promised, three steps earlier, to text them
//  "where to meet" - and the time picker accepts 5:00 AM, which no municipal
//  office is open for. Three answers to one question.
//
//  The office says it varies: sometimes the hall, sometimes the trailhead, and
//  a sunrise trek is met at the site. So it is recorded per request rather than
//  assumed by a sentence on a page.
//
//  160 characters, and the SMS keeps it shorter still - an assigned message is
//  already two segments and a third one costs the office real money.
// =============================================================================

$addColumn($pdo, 'tour_guide_requests', 'meeting_point',
    'meeting_point VARCHAR(160) NULL AFTER guide_contact');

// =============================================================================
//  2026-08 - The bell in the topbar.
// -----------------------------------------------------------------------------
//  NOT the existing `notifications` table. That one records whether an
//  announcement's SMS reached a destination manager - announcement_id,
//  provider_ref, attempts - and repurposing it would take announcement delivery
//  tracking down with it. Two different things that happen to share a word.
//
//  WHY TWO TABLES AND NOT ONE
//
//  Six officers share this system. "Read" is not a property of the event; it is
//  a property of one person's relationship to it. One row per event, and a
//  small row per person who has read it - so a new officer joining does not
//  inherit somebody else's read state, and marking one read never hides it from
//  the rest of the office.
// =============================================================================

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admin_notifications (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        type          VARCHAR(40)  NOT NULL,
        title         VARCHAR(160) NOT NULL,
        body          VARCHAR(400) NULL,
        link          VARCHAR(255) NULL,
        entity_type   VARCHAR(40)  NULL,
        entity_id     INT UNSIGNED NULL,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_notif_created (created_at),
        KEY idx_notif_entity (entity_type, entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "  ok    admin_notifications\n";

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS admin_notification_reads (
        notification_id INT UNSIGNED NOT NULL,
        admin_id        INT UNSIGNED NOT NULL,
        read_at         DATETIME     NOT NULL,
        PRIMARY KEY (notification_id, admin_id),
        KEY idx_read_admin (admin_id),
        CONSTRAINT fk_notif_read_notification
            FOREIGN KEY (notification_id) REFERENCES admin_notifications (id) ON DELETE CASCADE,
        CONSTRAINT fk_notif_read_admin
            FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "  ok    admin_notification_reads\n";

// =============================================================================
//  2026-08 — One request, several destinations.
// -----------------------------------------------------------------------------
//  A visitor asking for a guide rarely wants one waterfall. They want Jadas in
//  the morning and Kolon Ridge after lunch, and until now the form made them
//  choose one and send a second request for the other — which reaches the office
//  as two unrelated arrangements for the same four people on the same day.
//
//  WHY A TABLE AND NOT A COMMA-SEPARATED COLUMN
//
//  'Jadas Falls, Kolon Ridge' cannot be joined, cannot be counted, and cannot be
//  filtered. The office's inbox already filters by destination and the reports
//  already count visits per site; a text column would silently drop every
//  multi-destination request out of both.
//
//  WHY tour_guide_requests.destination_id STAYS
//
//  It is not a second copy of the truth — it is the PRIMARY destination, the one
//  the SMS names first and the one the inbox filter matches on. Every existing
//  query in TourGuideRepository reads it, and rewriting all of them to join
//  would be a large change to working code for no gain the office would notice.
//  New code reads the table below; the column keeps the first destination.
// =============================================================================

if ($tableExists($pdo, 'tour_request_destinations')) {
    echo "  skip  tour_request_destinations — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE tour_request_destinations (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,

            -- SET NULL rather than CASCADE: a destination the office archives
            -- must not silently delete itself out of an arrangement somebody is
            -- holding a receipt for. The row survives, naming nothing, and the
            -- receipt still shows what was asked for.
            destination_id INT UNSIGNED NULL,

            -- The order the visitor chose them in, which is the order they
            -- intend to visit. 'Destination 1' on the form is sort_order 0.
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            -- The same place twice in one request is not a longer visit, it is
            -- a mistake. The form prevents it and so does the database, because
            -- the form is JavaScript and the endpoint is not.
            UNIQUE KEY uniq_trd_pair (request_id, destination_id),
            KEY idx_trd_request (request_id, sort_order),
            KEY idx_trd_dest    (destination_id),

            CONSTRAINT fk_trd_request FOREIGN KEY (request_id)
                REFERENCES tour_guide_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_trd_dest FOREIGN KEY (destination_id)
                REFERENCES destinations (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    tour_request_destinations created\n";

    /* Every request already taken becomes a one-destination request. Without
       this backfill the receipt for an arrangement made yesterday would list no
       destinations at all — the new code reads the table, and the table would
       be empty for every row that predates it. */
    $moved = $pdo->exec(
        'INSERT INTO tour_request_destinations (request_id, destination_id, sort_order)
         SELECT id, destination_id, 0 FROM tour_guide_requests
          WHERE destination_id IS NOT NULL'
    );

    echo "  ok    tour_request_destinations backfilled ({$moved} existing request(s))\n";
}

/* THE DIFFERENCE BETWEEN 'ADVISE ME' AND SILENCE.
 *
 * destination_id has always been NULL for a request that named no place, and
 * that NULL carried two different meanings: a visitor who deliberately picked
 * "I am not sure yet — please advise", and one who simply skipped the field.
 * The office answers those differently — the first is asking for a
 * recommendation and the second is not — so the deliberate choice is recorded
 * as a fact rather than inferred from an absence. */
$addColumn($pdo, 'tour_guide_requests', 'needs_advice',
    'needs_advice TINYINT(1) NOT NULL DEFAULT 0 AFTER destination_id');

// =============================================================================
//  2026-08 — The guides themselves.
// -----------------------------------------------------------------------------
//  WHAT CHANGED SINCE tour_guide_requests WAS BUILT
//
//  That table's own comment says the office keeps no roster and assigns whoever
//  is free by phoning around, which is why guide_name and guide_contact are free
//  text. The office has since asked for accredited guides with credentials,
//  certificates and a printed ID — so the roster now exists, and this is it.
//
//  guide_name and guide_contact are NOT removed. They stay as the SNAPSHOT of
//  who was assigned: a guide who later leaves, is revoked, or changes their
//  number must not rewrite what a visitor was told last March. guide_id points
//  at the roster row; the two text columns record what the SMS actually said.
//
//  EXPIRY IS DERIVED, NOT STORED
//
//  status holds what a person decided — active, suspended, revoked. Whether the
//  ID has run out is a question about today's date and valid_until, and a stored
//  'expired' would be a lie on the morning after it lapsed until some job got
//  round to running. The repository computes it; nothing schedules anything.
// =============================================================================

if ($tableExists($pdo, 'tour_guides')) {
    echo "  skip  tour_guides — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE tour_guides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

            -- Printed on the ID and read down a phone: TGID-2026-0001.
            --
            -- 'TGID' and not 'TG': requests already use TG- with five random
            -- characters, and an officer holding both a receipt and a card
            -- should not have to work out which kind of code they are reading.
            -- The two prefixes are the only thing that tells them apart.
            --
            -- Sequential within the year, and deliberately so — this is an
            -- accreditation number a municipality issues, not a secret. What
            -- must not be guessable is verify_token below, which is why that is
            -- a separate column rather than this one doing both jobs.
            guide_code VARCHAR(20) NOT NULL,

            -- What the QR code on the ID actually points at. Random, so knowing
            -- one guide's URL tells you nothing about the next guide's, and
            -- rotatable if a card is lost without renumbering the accreditation.
            verify_token CHAR(32) NOT NULL,

            full_name     VARCHAR(160) NOT NULL,
            address       VARCHAR(255) NULL,
            mobile_number VARCHAR(20)  NULL,
            email         VARCHAR(190) NULL,

            -- Public: it is on the ID and on the verification page, which is the
            -- whole point of the verification page. Under uploads/ with the
            -- other public images, re-encoded through GD by Uploader.
            photo_path VARCHAR(255) NULL,

            -- What a person decided. 'expired' is deliberately absent — see the
            -- note above.
            status ENUM('active','suspended','revoked') NOT NULL DEFAULT 'active',

            -- The date on the card. NULL means no ID has been issued yet, which
            -- is a guide on the roster who cannot yet be assigned.
            valid_until  DATE NULL,
            id_issued_at DATETIME NULL,

            -- Why a guide was suspended or revoked. Shown to the office, never
            -- on the public verification page — that page says VALID or it does
            -- not, and a stranger scanning a card is not owed somebody's
            -- disciplinary history.
            status_note VARCHAR(600) NULL,

            notes VARCHAR(600) NULL,

            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_tg_code  (guide_code),
            UNIQUE KEY uniq_tg_token (verify_token),
            KEY idx_tg_status (status, full_name),

            CONSTRAINT fk_tg_admin FOREIGN KEY (created_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    tour_guides created\n";
}

/* One line each: 'Tour Guide Accreditation', 'First Aid Training'. These are
   the bullet points on the back of the ID and on the verification page, and
   they are a list because a guide has several and the office adds more. */
if ($tableExists($pdo, 'tour_guide_credentials')) {
    echo "  skip  tour_guide_credentials — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE tour_guide_credentials (
            id       INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            guide_id INT UNSIGNED NOT NULL,

            label  VARCHAR(160) NOT NULL,
            issuer VARCHAR(160) NULL,

            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            KEY idx_tgc_guide (guide_id, sort_order),

            CONSTRAINT fk_tgc_guide FOREIGN KEY (guide_id)
                REFERENCES tour_guides (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    tour_guide_credentials created\n";
}

/* The scanned certificate itself. Same storage discipline as the logbook pages
   and the inspection photos: a random server-side name, the original kept for
   display only, the file under storage/ behind the deny-all rule, and served
   through an endpoint that checks who is asking. A training certificate carries
   a private individual's full name and often their birth date. */
if ($tableExists($pdo, 'tour_guide_certificates')) {
    echo "  skip  tour_guide_certificates — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE tour_guide_certificates (
            id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            guide_id INT UNSIGNED NOT NULL,

            title  VARCHAR(160) NOT NULL,
            issuer VARCHAR(160) NULL,

            issued_on  DATE NULL,
            -- NULL means it does not expire. A first aid certificate does; a
            -- diploma does not, and a required date would have somebody invent
            -- one.
            expires_on DATE NULL,

            stored_name   VARCHAR(80)  NOT NULL,
            original_name VARCHAR(200) NOT NULL,
            mime_type     ENUM('image/jpeg','image/png','application/pdf') NOT NULL,
            byte_size     INT UNSIGNED NOT NULL,

            uploaded_by INT UNSIGNED NULL,
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_tgcert_stored (stored_name),
            KEY idx_tgcert_guide (guide_id, issued_on),

            CONSTRAINT fk_tgcert_guide FOREIGN KEY (guide_id)
                REFERENCES tour_guides (id) ON DELETE CASCADE,
            CONSTRAINT fk_tgcert_admin FOREIGN KEY (uploaded_by)
                REFERENCES admins (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ok    tour_guide_certificates created\n";
}

/* Which roster guide was assigned. SET NULL rather than CASCADE for the same
   reason the text columns stay: deleting a guide from the roster must not
   delete the record of the arrangements they were part of. */
$addColumn($pdo, 'tour_guide_requests', 'guide_id',
    'guide_id INT UNSIGNED NULL AFTER status');

$addIndex($pdo, 'tour_guide_requests', 'idx_guide_assigned',
    'KEY idx_guide_assigned (guide_id, preferred_date)');

$addForeignKey($pdo, 'tour_guide_requests', 'fk_request_guide',
    'fk_request_guide FOREIGN KEY (guide_id) REFERENCES tour_guides (id) ON DELETE SET NULL');

// =============================================================================
//  2026-08 — The office's Facebook page.
// -----------------------------------------------------------------------------
//  Printed on the back of the tour guide ID, and the office asked for it by
//  name. Stored as the page NAME rather than a URL: a visitor reads it off a
//  laminated card and types it into the Facebook search box, and an https://
//  address is longer, harder to read at 2 mm, and no easier to act on.
//
//  Seeded with the page the office gave, and editable in Settings — where a
//  field now exists for it, because a setting nobody can edit is worse than a
//  constant in the template.
// =============================================================================
$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_key = setting_key'
);
$stmt->execute(['office_facebook', 'Tampakan Municipal Tourism Office, South Cotabato']);

echo "  ok    office_facebook seeded\n";

// =============================================================================
//  2026-08 — The homepage hero, editable by the office.
// -----------------------------------------------------------------------------
//  The three hero slides were hard-coded in index.php and their photographs came
//  from images.unsplash.com — stock pictures of somewhere else, fetched from a
//  third party on every homepage load. The municipality's own front page showed a
//  mountain that is not theirs, and went blank whenever that CDN was slow.
//
//  img()'s own docblock said this was temporary: "Replaced naturally as real
//  photographs are added through the admin area." This is that replacement.
//
//  TWELVE ROWS RATHER THAN ONE JSON COLUMN. The settings screen renders a flat
//  key/value form; a JSON blob in a textarea is not something an officer edits,
//  it is something they break. Four plain fields per slide is what they can use.
//
//  Seeded with EXACTLY what index.php shows today, so running this changes
//  nothing on screen until somebody deliberately edits a field. A migration that
//  silently redesigns the front page is a migration nobody trusts.
// =============================================================================
$heroSeed = [
    ["Welcome to South Cotabato's Highland Heart",
     'Discover the Beauty of Tampakan',
     "Where cool mountain air, rolling highlands, and the living traditions of the B'laan people meet a warm municipal welcome."],
    ['Trails, Ridges & Rivers',
     'Adventure Above the Clouds',
     'Trek forest ridges, chase hidden waterfalls, and wake to a sea of clouds rolling over the mountain range.'],
    ['Culture & Heritage',
     'Stories Woven Into the Land',
     'Experience indigenous craftsmanship, heirloom weaving, and festivals that carry generations of Tampakan heritage.'],
];

$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_key = setting_key'
);

foreach ($heroSeed as $i => [$eyebrow, $title, $text]) {
    $n = $i + 1;

    /* The image stays blank on purpose. Blank means "fall back to the stock
       photo", so the page keeps working until the office uploads their own. */
    $stmt->execute(["hero_{$n}_image",   '']);
    $stmt->execute(["hero_{$n}_eyebrow", $eyebrow]);
    $stmt->execute(["hero_{$n}_title",   $title]);
    $stmt->execute(["hero_{$n}_text",    $text]);
}

echo "  ok    homepage hero settings seeded (12 rows, images blank until uploaded)\n";

// =============================================================================
//  HERO SLIDES — the front page becomes a list the office owns
// -----------------------------------------------------------------------------
//  The twelve settings rows above gave the office the WORDS on three slides. It
//  could not give them a fourth, could not retire one for the rainy season
//  without deleting the text, and could not put them in a different order.
//  Three was not a decision anybody made; it was how many were hard-coded in
//  index.php the day this was lifted out of it.
//
//  A row per slide instead: add, remove, reorder, and hold one back as a draft
//  while its photograph is still being taken.
//
//  MIGRATED, NOT RESET. The seed below reads whatever is in the hero_* settings
//  — including anything the office has already typed into that screen — and
//  carries it across verbatim, in the same order, with the same images. An
//  office that has edited its front page finds it unchanged.
//
//  The settings rows are deliberately LEFT IN PLACE. They are two hundred bytes,
//  nothing reads them once this table exists, and they are the only copy of the
//  old content if this migration turns out to have got something wrong. Dropping
//  them is a separate decision for a day when nobody needs them.
// =============================================================================
if ($tableExists($pdo, 'hero_slides')) {
    echo "  skip  hero_slides — already present\n";
} else {
    $pdo->exec("
        CREATE TABLE hero_slides (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

            -- Blank is meaningful: it means 'no photograph yet', and the public
            -- page falls back to a stock image rather than showing a gap. Same
            -- convention the settings rows used, kept so the fallback code did
            -- not have to change.
            image_path VARCHAR(255) NOT NULL DEFAULT '',

            eyebrow VARCHAR(120) NOT NULL DEFAULT '',
            title   VARCHAR(160) NOT NULL DEFAULT '',
            body    VARCHAR(400) NOT NULL DEFAULT '',

            -- draft keeps a slide out of the rotation without destroying the
            -- words. The alternative the office had was to delete it and type
            -- it again in March.
            status ENUM('published','draft') NOT NULL DEFAULT 'published',

            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,

            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_hero_order (status, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $read = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');

    $get = static function (string $key) use ($read): string {
        $read->execute([$key]);

        return trim((string) ($read->fetchColumn() ?: ''));
    };

    $add = $pdo->prepare(
        'INSERT INTO hero_slides (image_path, eyebrow, title, body, status, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $carried = 0;

    foreach ([1, 2, 3] as $n) {
        $title   = $get("hero_{$n}_title");
        $eyebrow = $get("hero_{$n}_eyebrow");
        $body    = $get("hero_{$n}_text");

        /* A slide with nothing in it at all is not carried across. That is a
           settings row that was seeded and never filled, and copying it here
           would give the office an empty slide to wonder about. */
        if ($title === '' && $eyebrow === '' && $body === '') {
            continue;
        }

        $add->execute([$get("hero_{$n}_image"), $eyebrow, $title, $body, 'published', $carried]);
        $carried++;
    }

    printf("  ok    hero_slides created (%d slide%s carried over from settings)\n",
        $carried, $carried === 1 ? '' : 's');
}

echo str_repeat('=', 60) . "\n  Migrations complete.\n\n";
