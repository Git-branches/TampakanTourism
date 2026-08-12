<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — validating an imported tourist list                    Feature 2
 * -----------------------------------------------------------------------------
 *  Turns a spreadsheet into either a preview the manager confirms, or a list of
 *  numbered problems they can fix. NOTHING IS WRITTEN HERE. This class produces
 *  a verdict; the page decides what to do with it, and only after the manager
 *  has looked at it.
 *
 *  Row numbers in every message are the row numbers the manager sees in Excel,
 *  not zero-based offsets into an array. "Row 12: missing tourist name" has to
 *  send them to row 12 of their own file or it is worse than saying nothing.
 *
 *  WHAT IS AND IS NOT A DUPLICATE
 *
 *  Two visitors on the same day can legitimately share a name — this is a
 *  municipality where that is common, and refusing the second one loses a real
 *  arrival. So a duplicate is only flagged when the name, the date AND the
 *  contact number all match, which is a row that was pasted twice. Where the
 *  contact is blank there is nothing to distinguish the two people by, so the
 *  row is ALLOWED and merely noted — a warning the manager can judge, never a
 *  silent drop.
 * =============================================================================
 */
final class LogbookImport
{
    /**
     * Header spellings that map to each field. Matched case-insensitively with
     * spaces and punctuation stripped, because a header is typed by a person:
     * "Contact No.", "contact_number" and "CONTACT #" are one column.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNS = [
        'full_name' => ['name', 'fullname', 'tourist', 'touristname', 'pangalan', 'nameoftourist', 'visitor', 'visitorname'],
        'address'   => ['address', 'addr', 'tirahan', 'from', 'origin', 'residence', 'place'],
        'contact'   => ['contactno', 'contact', 'contactnumber', 'cellphone', 'mobile', 'mobileno', 'phone', 'phoneno', 'cp', 'cpno', 'number'],
        'date'      => ['date', 'visitdate', 'dateofvisit', 'petsa', 'datevisited', 'arrival', 'arrivaldate'],
    ];

    private const REQUIRED = ['full_name', 'date'];

    /** @var array<int, string> */
    private array $fatal = [];

    /** @var array<int, array{row:int, message:string, level:string}> */
    private array $issues = [];

    /** @var array<int, array<string, string>> */
    private array $valid = [];

    private int $consideredRows = 0;

    /**
     * @param array<int, array<int, string>> $rows      as read from the file
     * @param string                         $periodStart YYYY-MM-DD
     * @param string                         $periodEnd   YYYY-MM-DD
     */
    public function __construct(
        private array $rows,
        private string $periodStart,
        private string $periodEnd
    ) {
    }

    public function run(): void
    {
        $header = $this->findHeader();

        if ($header === null) {
            return;
        }

        [$headerIndex, $map] = $header;

        $seen = [];

        foreach ($this->rows as $index => $cells) {
            if ($index <= $headerIndex) {
                continue;
            }

            /* Excel rows are 1-based and the array is 0-based. */
            $rowNumber = $index + 1;

            $name    = $this->cell($cells, $map, 'full_name');
            $address = $this->cell($cells, $map, 'address');
            $contact = $this->cell($cells, $map, 'contact');
            $rawDate = $this->cell($cells, $map, 'date');

            /* A wholly blank row is the empty space below the data, not a
               mistake. Skipped silently — flagging it would bury the real
               problems under fifty complaints about nothing. */
            if ($name === '' && $address === '' && $contact === '' && $rawDate === '') {
                continue;
            }

            $this->consideredRows++;

            $rowIssues = [];

            if ($name === '') {
                $rowIssues[] = 'Missing tourist name.';
            } elseif (mb_strlen($name) > 160) {
                $rowIssues[] = 'Name is longer than 160 characters.';
            }

            $date = $this->normaliseDate($rawDate);

            if ($rawDate === '') {
                $rowIssues[] = 'Missing date.';
            } elseif ($date === null) {
                $rowIssues[] = 'Invalid date "' . mb_substr($rawDate, 0, 30) . '". Use YYYY-MM-DD.';
            } elseif ($date < $this->periodStart || $date > $this->periodEnd) {
                $rowIssues[] = 'Date ' . $date . ' is outside the reporting period ('
                    . $this->periodStart . ' to ' . $this->periodEnd . ').';
            }

            if ($contact !== '' && !$this->looksLikeContact($contact)) {
                $rowIssues[] = 'Contact number "' . mb_substr($contact, 0, 30) . '" does not look like a phone number.';
            }

            if ($rowIssues !== []) {
                foreach ($rowIssues as $message) {
                    $this->issues[] = ['row' => $rowNumber, 'message' => $message, 'level' => 'error'];
                }

                continue;
            }

            /* Same person, same day, same number: a row pasted twice. */
            $fingerprint = mb_strtolower($name) . '|' . $date . '|' . preg_replace('/\D+/', '', $contact);

            if ($contact !== '' && isset($seen[$fingerprint])) {
                $this->issues[] = [
                    'row'     => $rowNumber,
                    'message' => 'Duplicate of row ' . $seen[$fingerprint] . ' — same name, date and contact number.',
                    'level'   => 'error',
                ];

                continue;
            }

            if ($contact !== '') {
                $seen[$fingerprint] = $rowNumber;
            } else {
                /* No contact number, so two people of the same name on the same
                   day cannot be told apart. Both are kept — a real second
                   visitor lost is worse than a duplicate an officer can spot —
                   and the manager is told to look. */
                $nameDate = mb_strtolower($name) . '|' . $date;

                if (isset($seen[$nameDate])) {
                    $this->issues[] = [
                        'row'     => $rowNumber,
                        'message' => 'Same name and date as row ' . $seen[$nameDate]
                            . ', and no contact number to tell them apart. Kept — please check it is not a repeat.',
                        'level'   => 'warning',
                    ];
                } else {
                    $seen[$nameDate] = $rowNumber;
                }
            }

            $this->valid[] = [
                'row'            => (string) $rowNumber,
                'full_name'      => mb_substr($name, 0, 160),
                'address_text'   => mb_substr($address, 0, 160),
                'contact_number' => mb_substr($contact, 0, 40),
                'visit_date'     => $date,
            ];
        }

        if ($this->valid === [] && $this->issues === []) {
            $this->fatal[] = 'That file has a header but no tourist rows underneath it.';
        }
    }

    // -------------------------------------------------------------------------
    // Results
    // -------------------------------------------------------------------------

    /** @return array<int, string> */
    public function fatalErrors(): array
    {
        return $this->fatal;
    }

    /** @return array<int, array{row:int, message:string, level:string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** @return array<int, array<string, string>> */
    public function validRows(): array
    {
        return $this->valid;
    }

    public function errorCount(): int
    {
        return count(array_filter($this->issues, static fn (array $i): bool => $i['level'] === 'error'));
    }

    public function warningCount(): int
    {
        return count(array_filter($this->issues, static fn (array $i): bool => $i['level'] === 'warning'));
    }

    public function consideredRows(): int
    {
        return $this->consideredRows;
    }

    /**
     * Valid rows grouped by date, ready for the page-per-date store.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public function byDate(): array
    {
        $out = [];

        foreach ($this->valid as $row) {
            $out[$row['visit_date']][] = $row;
        }

        ksort($out);

        return $out;
    }

    // -------------------------------------------------------------------------
    // Header
    // -------------------------------------------------------------------------

    /**
     * Finds the header row and maps each required field to a column index.
     *
     * Searched across the first few rows rather than assumed to be row 1: real
     * exported files start with a title and a blank line above the headings.
     *
     * @return array{0:int, 1:array<string,int>}|null
     */
    private function findHeader(): ?array
    {
        $limit = min(10, count($this->rows));

        for ($index = 0; $index < $limit; $index++) {
            $map = [];

            foreach ($this->rows[$index] as $column => $value) {
                $key = self::normaliseHeader($value);

                if ($key === '') {
                    continue;
                }

                foreach (self::COLUMNS as $field => $spellings) {
                    if (!isset($map[$field]) && in_array($key, $spellings, true)) {
                        $map[$field] = $column;
                    }
                }
            }

            $missing = array_diff(self::REQUIRED, array_keys($map));

            if ($missing === []) {
                return [$index, $map];
            }
        }

        $this->fatal[] = 'The file needs a header row with at least a "Name" column and a "Date" column. '
            . 'Address and Contact No. are optional but recommended, so the file matches the paper logbook.';

        return null;
    }

    private static function normaliseHeader(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value)));
    }

    /** @param array<string, int> $map */
    private function cell(array $cells, array $map, string $field): string
    {
        if (!isset($map[$field])) {
            return '';
        }

        return trim((string) ($cells[$map[$field]] ?? ''));
    }

    // -------------------------------------------------------------------------
    // Values
    // -------------------------------------------------------------------------

    /**
     * Normalises whatever the file calls a date into YYYY-MM-DD.
     *
     * Handles the Excel serial number, because a real .xlsx stores dates as a
     * day count and a cell that reads "07/12/2024" on screen arrives here as
     * "45633". Without this, every date in a genuine Excel export is rejected.
     *
     * Day/month order is deliberately NOT guessed for ambiguous slashed dates:
     * 07/12/2024 is either 7 December or 12 July, and guessing silently files
     * a whole month of arrivals into the wrong one. Those are rejected with a
     * message asking for YYYY-MM-DD.
     */
    private function normaliseDate(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        /* Excel serial: days since 1899-12-30 (the offset absorbs Excel's
           deliberate 1900 leap-year bug). */
        if (preg_match('/^\d{5}(\.\d+)?$/', $raw) === 1) {
            $serial = (int) $raw;

            if ($serial > 0 && $serial < 80000) {
                return date('Y-m-d', mktime(0, 0, 0, 12, 30 + $serial, 1899));
            }
        }

        /* Unambiguous: ISO, and the formats that name the month. */
        foreach (['Y-m-d', 'Y/m/d', 'd-M-Y', 'j-M-Y', 'M j, Y', 'M j Y', 'F j, Y', 'j F Y', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw);

            if ($parsed !== false && $parsed->format($format) === $raw) {
                return $parsed->format('Y-m-d');
            }
        }

        /* A four-digit year first is unambiguous whatever the separator. */
        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $raw, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : null;
        }

        /* A day above 12 settles the order on its own. */
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $raw, $m) === 1) {
            $a = (int) $m[1];
            $b = (int) $m[2];

            if ($a > 12 && $b <= 12) {
                return checkdate($b, $a, (int) $m[3]) ? sprintf('%04d-%02d-%02d', (int) $m[3], $b, $a) : null;
            }

            if ($b > 12 && $a <= 12) {
                return checkdate($a, $b, (int) $m[3]) ? sprintf('%04d-%02d-%02d', (int) $m[3], $a, $b) : null;
            }

            /* Both 12 or under — genuinely ambiguous, so it is refused. */
            return null;
        }

        return null;
    }

    /**
     * A plausible phone number, not a validated one.
     *
     * The paper logbook has numbers written in every shape: 09XX XXX XXXX,
     * +639XXXXXXXXX, with dashes, with a landline area code. The check is
     * loose on purpose — it exists to catch a column that is not phone numbers
     * at all, not to reject a real number typed unusually.
     */
    private function looksLikeContact(string $value): bool
    {
        if (preg_match('/^[0-9+()\/\-. ]+$/', $value) !== 1) {
            return false;
        }

        $digits = strlen((string) preg_replace('/\D+/', '', $value));

        return $digits >= 7 && $digits <= 15;
    }
}
