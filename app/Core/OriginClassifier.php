<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — reading the Address column of the paper logbook       Feature 2
 * -----------------------------------------------------------------------------
 *  The paper form has one column that decides a statistic: Address. Everything
 *  the Municipal Tourism Office reports about local vs domestic vs foreign
 *  arrivals comes out of what a visitor wrote in that box, in ballpoint, at a
 *  waterfall, in whatever form occurred to them:
 *
 *      "Cannery, Polomolok"   "Kor City"   "Tamp."   "TUPI"   "GENSAN"
 *      "Carpenter Hill"       "NORALA"     "kor city"          "—"
 *
 *  A human doing this tally by hand is doing it twenty-five times a page, and
 *  the arithmetic is exactly where the wrong number enters the system. So the
 *  classifier reads the address instead.
 *
 *  THE RULE THIS CLASS FOLLOWS: it never quietly decides something it does not
 *  know. Every result carries a confidence, and 'low' means "I guessed — ask
 *  the manager". A guess presented as a fact becomes a municipal statistic that
 *  nobody can trace back to a decision.
 *
 *  That is why generic barangay names are not treated as Tampakan. Liberty,
 *  Poblacion, San Isidro and Santa Cruz are barangays in Tampakan AND in half
 *  the municipalities around it. Matching them as local would silently convert
 *  visitors from Surallah into residents of Tampakan, and the error would be
 *  invisible: the total stays right while the split goes wrong.
 * =============================================================================
 */
final class OriginClassifier
{
    private const PROVINCE = 'South Cotabato';
    private const COUNTRY  = 'Philippines';

    /**
     * Barangays distinctive enough to identify Tampakan on their own. Names
     * that also belong to neighbouring municipalities are deliberately absent —
     * see the class note above.
     */
    private const TAMPAKAN_BARANGAYS = [
        'kipalbig', 'maltana', 'tablu', 'danlag', 'albagan', 'pula bato',
        'pulabato', 'lambayong',
    ];

    /** Written forms of Tampakan itself, including how it is abbreviated. */
    private const TAMPAKAN = ['tampakan', 'tampakan south cotabato', 'tamp', 'tampkan', 'tampakn'];

    /**
     * Places that settle a domestic classification, as [municipality, province].
     *
     * THE PROVINCE IS NOT DECORATION. The Tourism Attraction Visitor Record the
     * office submits splits residence into "This province" / "Other Province" /
     * "Foreign Country" — a PROVINCE-level cut, not a municipality-level one.
     * Polomolok, Koronadal and Tupi are all South Cotabato, so on that form they
     * belong in the same column as Tampakan. Recording only the municipality
     * left the report unable to tell them from a visitor from Manila.
     *
     * Keys are matched against the normalised address; the municipality is what
     * goes into origin_city, so "kor city", "koronadal" and "carpenter hill"
     * group as one place instead of three.
     */
    private const PHILIPPINE_PLACES = [
        // ---- South Cotabato: the office's own province -----------------------
        'koronadal'      => ['Koronadal City', 'South Cotabato'],
        'kor city'       => ['Koronadal City', 'South Cotabato'],
        'korcity'        => ['Koronadal City', 'South Cotabato'],
        'carpenter hill' => ['Koronadal City', 'South Cotabato'],
        'marbel'         => ['Koronadal City', 'South Cotabato'],
        'polomolok'      => ['Polomolok', 'South Cotabato'],
        'cannery'        => ['Polomolok', 'South Cotabato'],
        'tupi'           => ['Tupi', 'South Cotabato'],
        'norala'         => ['Norala', 'South Cotabato'],
        'surallah'       => ['Surallah', 'South Cotabato'],
        'banga'          => ['Banga', 'South Cotabato'],
        'tantangan'      => ['Tantangan', 'South Cotabato'],
        'santo nino'     => ['Santo Niño', 'South Cotabato'],
        'sto nino'       => ['Santo Niño', 'South Cotabato'],
        'lake sebu'      => ['Lake Sebu', 'South Cotabato'],
        'tboli'          => ["T'boli", 'South Cotabato'],
        't boli'         => ["T'boli", 'South Cotabato'],

        /* General Santos is a highly urbanised city — geographically inside
           South Cotabato, administratively independent of it. Which column it
           belongs in on the DOT form is the Tourism Officer's call, not a
           programmer's, so it is recorded under its own name and the report
           screen exposes the choice rather than burying it here. */
        'general santos' => ['General Santos City', 'General Santos City'],
        'gensan'         => ['General Santos City', 'General Santos City'],
        'gen santos'     => ['General Santos City', 'General Santos City'],

        // ---- Elsewhere in the Philippines ------------------------------------
        'sarangani'      => ['Sarangani', 'Sarangani'],
        'alabel'         => ['Alabel', 'Sarangani'],
        'malungon'       => ['Malungon', 'Sarangani'],
        'kiamba'         => ['Kiamba', 'Sarangani'],
        'maasim'         => ['Maasim', 'Sarangani'],
        'glan'           => ['Glan', 'Sarangani'],
        'tacurong'       => ['Tacurong City', 'Sultan Kudarat'],
        'isulan'         => ['Isulan', 'Sultan Kudarat'],
        'sultan kudarat' => ['Sultan Kudarat', 'Sultan Kudarat'],
        'kidapawan'      => ['Kidapawan City', 'Cotabato'],
        'cotabato'       => ['Cotabato', 'Cotabato'],
        'midsayap'       => ['Midsayap', 'Cotabato'],
        'digos'          => ['Digos City', 'Davao del Sur'],
        'davao'          => ['Davao City', 'Davao del Sur'],
        'tagum'          => ['Tagum City', 'Davao del Norte'],
        'zamboanga'      => ['Zamboanga City', 'Zamboanga del Sur'],
        'cagayan de oro' => ['Cagayan de Oro City', 'Misamis Oriental'],
        'iligan'         => ['Iligan City', 'Lanao del Norte'],
        'butuan'         => ['Butuan City', 'Agusan del Norte'],
        'ozamiz'         => ['Ozamiz City', 'Misamis Occidental'],
        'pagadian'       => ['Pagadian City', 'Zamboanga del Sur'],
        'dipolog'        => ['Dipolog City', 'Zamboanga del Norte'],
        'marawi'         => ['Marawi City', 'Lanao del Sur'],
        'cebu'           => ['Cebu City', 'Cebu'],
        'iloilo'         => ['Iloilo City', 'Iloilo'],
        'bacolod'        => ['Bacolod City', 'Negros Occidental'],
        'tacloban'       => ['Tacloban City', 'Leyte'],
        'manila'         => ['Manila', 'Metro Manila'],
        'quezon city'    => ['Quezon City', 'Metro Manila'],
        'makati'         => ['Makati City', 'Metro Manila'],
        'pasig'          => ['Pasig City', 'Metro Manila'],
        'caloocan'       => ['Caloocan City', 'Metro Manila'],
        'taguig'         => ['Taguig City', 'Metro Manila'],
        'paranaque'      => ['Parañaque City', 'Metro Manila'],
        'baguio'         => ['Baguio City', 'Benguet'],
        'cavite'         => ['Cavite', 'Cavite'],
        'laguna'         => ['Laguna', 'Laguna'],
        'batangas'       => ['Batangas', 'Batangas'],
        'bulacan'        => ['Bulacan', 'Bulacan'],
        'pampanga'       => ['Pampanga', 'Pampanga'],
        'rizal'          => ['Rizal', 'Rizal'],
    ];

    /** Enough of the world to catch what actually turns up on these pages. */
    private const COUNTRIES = [
        'usa' => 'United States', 'u s a' => 'United States', 'united states' => 'United States',
        'america' => 'United States', 'american' => 'United States',
        'canada' => 'Canada', 'canadian' => 'Canada',
        'australia' => 'Australia', 'australian' => 'Australia',
        'japan' => 'Japan', 'japanese' => 'Japan',
        'korea' => 'South Korea', 'korean' => 'South Korea',
        'china' => 'China', 'chinese' => 'China',
        'taiwan' => 'Taiwan', 'hong kong' => 'Hong Kong',
        'singapore' => 'Singapore', 'malaysia' => 'Malaysia', 'indonesia' => 'Indonesia',
        'thailand' => 'Thailand', 'vietnam' => 'Vietnam', 'india' => 'India',
        'germany' => 'Germany', 'german' => 'Germany',
        'france' => 'France', 'french' => 'France',
        'spain' => 'Spain', 'spanish' => 'Spain',
        'italy' => 'Italy', 'italian' => 'Italy',
        'netherlands' => 'Netherlands', 'dutch' => 'Netherlands',
        'united kingdom' => 'United Kingdom', 'uk' => 'United Kingdom',
        'england' => 'United Kingdom', 'british' => 'United Kingdom',
        'norway' => 'Norway', 'sweden' => 'Sweden', 'denmark' => 'Denmark',
        'switzerland' => 'Switzerland', 'new zealand' => 'New Zealand',
        'saudi' => 'Saudi Arabia', 'saudi arabia' => 'Saudi Arabia',
        'uae' => 'United Arab Emirates', 'dubai' => 'United Arab Emirates',
        'qatar' => 'Qatar', 'kuwait' => 'Kuwait', 'bahrain' => 'Bahrain', 'oman' => 'Oman',
    ];

    /** Words a visitor uses when they are a Filipino working abroad. */
    private const OFW_MARKERS = ['ofw', 'o f w', 'overseas', 'balikbayan', 'seaman', 'seafarer'];

    /**
     * Reads one Address cell.
     *
     * @return array{tourist_type:string, origin_city:?string, origin_province:?string,
     *               origin_country:?string, confidence:string, note:string}
     */
    public static function classify(?string $written): array
    {
        $text = self::normalise((string) $written);

        /* A blank address is a blank address. The paper has them — a row with a
           dash through the column is a real row with a real person on it — and
           the honest answer is "unknown", not a default dressed up as a fact. */
        if ($text === '' || $text === '-' || $text === '--') {
            return self::result('domestic', null, null, null, 'low', 'No address written — please set the type.');
        }

        /* OFW first. "OFW - Dubai" is a Filipino working abroad, not a foreign
           national, and the country match would otherwise win. */
        foreach (self::OFW_MARKERS as $marker) {
            if (self::contains($text, $marker)) {
                return self::result('overseas_filipino', null, null, self::COUNTRY, 'high',
                    'Read as an overseas Filipino worker.');
            }
        }

        foreach (self::COUNTRIES as $cue => $country) {
            if (self::contains($text, $cue)) {
                return self::result('foreign', null, null, $country, 'high', 'Read as ' . $country . '.');
            }
        }

        foreach (self::TAMPAKAN as $cue) {
            if (self::contains($text, $cue)) {
                return self::result('local', 'Tampakan', self::PROVINCE, self::COUNTRY, 'high',
                    'Resident of Tampakan.');
            }
        }

        foreach (self::TAMPAKAN_BARANGAYS as $barangay) {
            if (self::contains($text, $barangay)) {
                return self::result('local', 'Tampakan', self::PROVINCE, self::COUNTRY, 'high',
                    'Barangay ' . ucwords($barangay) . ', Tampakan.');
            }
        }

        /* TWO PASSES, MUNICIPALITIES BEFORE PROVINCES.
         *
         * Length alone is not enough to order these, and ordering by length
         * alone was wrong in a way that never showed up in a total.
         *
         * "Tupi, South Cotabato" contains both `tupi` and `cotabato`. Sorted by
         * length, the eight-letter province cue won and the entry was filed as
         * Cotabato — so a visitor from a South Cotabato municipality landed in
         * the DOT form's OTHER PROVINCE column while the grand total stayed
         * correct. Eleven municipality cues were shorter than `cotabato` and
         * every one of them was misfiled: tupi, banga, norala, marbel, tboli,
         * sto nino, surallah, kor city, korcity, cannery, t boli.
         *
         * An address naming a municipality is always more specific than the
         * province printed after it, whatever the two happen to be spelled
         * like. So municipality cues are matched first, and only if none hits
         * does the province-level list get a turn.
         *
         * Within each pass, longest still wins — that is what keeps
         * "general santos" from being beaten by a shorter cue inside it. */
        $municipalities = [];
        $provinces      = [];

        foreach (self::PHILIPPINE_PLACES as $cue => [$city, $province]) {
            /* city === province marks an entry that IS a province, or a city
               the DOT form treats as its own province — General Santos, for
               instance. Anything else names a town inside a province. */
            if ($city === $province) {
                $provinces[$cue] = [$city, $province];
            } else {
                $municipalities[$cue] = [$city, $province];
            }
        }

        $byLength = static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a);
        uksort($municipalities, $byLength);
        uksort($provinces, $byLength);

        foreach ([$municipalities, $provinces] as $pass) {
            foreach ($pass as $cue => [$city, $province]) {
                if (self::contains($text, $cue)) {
                    return self::result('domestic', $city, $province, self::COUNTRY, 'high',
                        'Read as ' . $city . ($province !== $city ? ', ' . $province : '') . '.');
                }
            }
        }

        /* Something was written and none of it was recognised. Defaulting to
           domestic is the likeliest answer on these pages, but it is a guess and
           it is labelled as one — the form asks the manager to confirm. */
        return self::result('domestic', self::titleCase((string) $written), null, self::COUNTRY, 'low',
            'Not recognised — please check the type.');
    }

    /**
     * Classifies a whole page at once.
     *
     * @param  array<int, string|null> $addresses
     * @return array<int, array<string, mixed>>
     */
    public static function classifyMany(array $addresses): array
    {
        return array_map(static fn ($a) => self::classify($a), $addresses);
    }

    // -------------------------------------------------------------------------

    /**
     * Lowercase, punctuation to spaces, accents folded, spaces collapsed. The
     * page is handwritten: "Kor. City", "kor city" and "KOR CITY" are one place.
     */
    private static function normalise(string $raw): string
    {
        $text = mb_strtolower(trim($raw));

        $text = strtr($text, [
            'ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        ]);

        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * Word-boundary containment.
     *
     * Padding both sides is what stops "tamp" matching "tampakan"-adjacent
     * words by accident, and more importantly stops short cues appearing inside
     * unrelated longer words.
     */
    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }

    private static function titleCase(string $raw): string
    {
        return mb_substr(ucwords(mb_strtolower(trim($raw))), 0, 120);
    }

    /** @return array<string, mixed> */
    private static function result(
        string $type,
        ?string $city,
        ?string $province,
        ?string $country,
        string $confidence,
        string $note
    ): array {
        return [
            'tourist_type'    => $type,
            'origin_city'     => $city,
            'origin_province' => $province,
            'origin_country'  => $country,
            'confidence'      => $confidence,
            'note'            => $note,
        ];
    }
}
