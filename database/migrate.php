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

echo str_repeat('=', 60) . "\n  Migrations complete.\n\n";
