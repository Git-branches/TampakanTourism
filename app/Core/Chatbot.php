<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — visitor assistant                                     Feature 4
 * -----------------------------------------------------------------------------
 *  Answers questions about every section of the public site from the
 *  municipality's own records. Rule-based, not a language model, and the
 *  distinction is the point rather than a limitation:
 *
 *   · It cannot invent an entrance fee. Every figure it states was typed into
 *     the admin by the Tourism Office. A wrong price quoted by the municipal
 *     website is the municipality's problem, and no amount of grounding makes
 *     a generative model incapable of producing one.
 *   · Nothing leaves the server. No API key on a government host, no visitor's
 *     question sent abroad, no recurring cost the office has to budget for
 *     every year, and nothing to break when a card expires.
 *   · It answers with a signal it can defend: when it does not know, it says
 *     so and hands the visitor the Tourism Office, rather than guessing.
 *
 *  It answers in Filipino when it is asked in Filipino. This is a municipal
 *  site in South Cotabato; replying in English to a question asked in Tagalog
 *  would be a small daily discourtesy.
 *
 *  Should the office later decide it wants free-form conversational answers,
 *  a model-backed implementation can sit behind ask() unchanged — the endpoint,
 *  the widget, and the answer shape are all independent of what produces them.
 * =============================================================================
 */
final class Chatbot
{
    public const MAX_QUESTION_LENGTH = 300;

    /**
     * Intent keywords, most specific first.
     *
     * Order is load-bearing: "paano pumunta sa Jadas" contains both a
     * directions cue and a location cue, and must be read as directions. Every
     * intent carries both English and Filipino cues in one list because a
     * single question routinely mixes the two — "magkano ang entrance fee" is
     * how the question actually arrives.
     */
    private const INTENTS = [
        'directions'    => ['paano pumunta', 'paano makarating', 'papunta', 'how to get', 'how do i get', 'get there',
                            'direction', 'ruta', 'route', 'daan', 'byahe', 'biyahe', 'navigate', 'makarating'],
        'closure'       => ['sarado', 'saradong', 'closed', 'closure', 'closures', 'nakasara', 'suspendido',
                            'cancelled', 'canceled'],
        'fee'           => ['magkano', 'bayad', 'bayaran', 'entrance', 'presyo', 'singil', 'price', 'prices', 'cost',
                            'how much', 'fee', 'fees', 'libre', 'free', 'ticket', 'tickets'],
        'hours'         => ['anong oras', 'oras', 'bukas', 'open', 'opening', 'closing', 'schedule', 'operating',
                            'what time', 'hours', 'iskedyul'],
        'facilities'    => ['cr', 'comfort room', 'banyo', 'palikuran', 'parking', 'paradahan', 'wifi', 'cottage',
                            'cottages', 'kubo', 'facility', 'facilities', 'amenities', 'pasilidad', 'kainan',
                            'tindahan', 'store', 'stores'],
        'accommodation' => ['tulugan', 'matutuluyan', 'matutulugan', 'matutulog', 'matulog', 'tuluyan', 'hotel',
                            'hotels', 'homestay', 'homestays', 'accommodation', 'inn', 'inns', 'lodging',
                            'camping', 'camp', 'overnight', 'magpalipas'],
        'transport'     => ['habal', 'tricycle', 'traysikel', 'jeep', 'jeepney', 'van', 'sakay', 'sasakyan',
                            'transport', 'transportation', 'commute'],
        'safety'        => ['ligtas', 'safe', 'safety', 'delikado', 'panganib', 'tips', 'dapat dalhin', 'what to bring',
                            'dalhin', 'baon', 'precaution'],
        'best_time'     => ['best time', 'kailan pinakamaganda', 'kailan maganda', 'anong buwan', 'anong panahon',
                            'season', 'tag-ulan', 'tag-init', 'summer', 'rainy'],
        'weather'       => ['panahon', 'weather', 'ulan', 'umuulan', 'uulan', 'init', 'lamig', 'malamig', 'mainit',
                            'temperature', 'forecast', 'klima'],
        'events'        => ['event', 'events', 'festival', 'fiesta', 'piyesta', 'selebrasyon', 'celebration',
                            'activity', 'aktibidad', 'okasyon', 'upcoming'],
        'contact'       => ['contact', 'kontak', 'tawag', 'tawagan', 'number', 'numero', 'email', 'hotline',
                            'opisina', 'office', 'makipag-ugnayan', 'reklamo', 'complaint'],
        'logbook'       => ['logbook', 'log book', 'qr', 'scan', 'register', 'magparehistro', 'check in', 'checkin',
                            'log my visit', 'mag-log'],
        'reviews'       => ['review', 'reviews', 'rating', 'ratings', 'feedback', 'maganda ba', 'sulit ba', 'worth it'],
        /* 'list' is tested before 'location' because the two share the word
           "lugar", and "anong mga lugar ang mapupuntahan" is a request for the
           catalogue, not for one place's address. 'location' therefore relies
           on saan / nasaan / where, which are unambiguous. */
        'list'          => ['anong mga', 'anu-ano', 'ano ang', 'list', 'lahat', 'all', 'destination', 'destinations',
                            'destinasyon', 'tourist spot', 'tourist spots', 'spot', 'spots', 'puntahan',
                            'mapupuntahan', 'pasyalan', 'attraction', 'attractions', 'place', 'places', 'lugar',
                            'visit', 'bisitahin', 'makita'],
        'location'      => ['saan', 'nasaan', 'where', 'location', 'lokasyon', 'address', 'adres', 'matatagpuan'],
        'greeting'      => ['hello', 'hi', 'hey', 'kumusta', 'kamusta', 'magandang', 'good morning', 'good afternoon',
                            'good evening', 'musta'],
        'thanks'        => ['salamat', 'thank you', 'thanks', 'ty'],
    ];

    /**
     * Words that mark a question as Filipino.
     *
     * Deliberately excludes words English shares or that appear in place names
     * — "sa" alone would match "Casa", and the cost of a false positive is
     * answering a tourist from Manila in the wrong language.
     */
    private const FILIPINO_MARKERS = [
        'magkano', 'saan', 'nasaan', 'paano', 'kailan', 'ano', 'anong', 'anu', 'bakit', 'sino',
        'bayad', 'oras', 'bukas', 'sarado', 'lugar', 'puntahan', 'pumunta', 'makarating',
        'salamat', 'kumusta', 'kamusta', 'magandang', 'meron', 'mayroon', 'pwede', 'puwede',
        'ba', 'ang', 'ng', 'sa', 'mga', 'ako', 'namin', 'kayo', 'po', 'yung', 'iyong', 'dito',
        'ligtas', 'panahon', 'ulan', 'malamig', 'mainit', 'tulugan', 'matutuluyan', 'dalhin',
    ];

    /**
     * Cebuano markers, kept separate from the Filipino list.
     *
     * South Cotabato is largely Cebuano-speaking, so a visitor asking "pila ang
     * bayad" is not an edge case here — it is the local question. Only words
     * Cebuano does not share with Tagalog are listed, because the shared ones
     * would make every Tagalog question look Cebuano.
     *
     * The rule-based answers below are written in Filipino, which a Cebuano
     * speaker reads comfortably; what this flag changes is the language Gemini
     * is asked to reply in. Claiming to answer in Cebuano and then producing
     * Tagalog would be worse than not claiming it.
     */
    private const CEBUANO_MARKERS = [
        'pila', 'asa', 'unsa', 'unsay', 'kanus-a', 'ngano', 'kinsa', 'giunsa',
        'nia', 'naa', 'wala', 'aduna', 'duna', 'kini', 'kana', 'diri', 'didto',
        'salamat kaayo', 'maayong', 'nindot', 'gwapo', 'lami', 'mahal',
        'ako', 'ikaw', 'kami', 'kita', 'nimo', 'nako', 'ninyo', 'gyud', 'jud',
        'bayranan', 'abli', 'sirado', 'adto', 'moadto', 'lugar',
    ];

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    /**
     * @return array{reply:string, facts:array, links:array, suggestions:array, intent:string, answered:bool}
     */
    public static function ask(string $question): array
    {
        $raw = trim($question);

        if ($raw === '') {
            return self::unsure(false, '');
        }

        $raw  = mb_substr($raw, 0, self::MAX_QUESTION_LENGTH);
        $norm = self::normalise($raw);
        $fil  = self::isFilipino($norm);

        /* Asked before anything else, and answered with the honest refusal.
         *
         * These are questions about things this system holds but must never
         * discuss with the public — logbook records, admin credentials, staff
         * details — and questions about things it does not hold at all. Both
         * are refused here rather than allowed to reach an intent that would
         * answer something adjacent and look like it complied. */
        if (self::isOutOfScope($norm)) {
            return self::unsure($fil, $raw);
        }

        $kb = KnowledgeBase::all();
        $place  = self::matchDestination($norm, $kb['destinations']);
        $intent = self::matchIntent($norm);

        /* LEVEL 2 — questions that need language rather than lookup.
         *
         * Checked before the factual intents because the two overlap in
         * wording: "which destination is good for families" carries a listing
         * cue, and answering it with the catalogue would be a worse answer
         * than the one it is asking for.
         *
         * Everything below this line is Level 1 — answered from the database
         * without Gemini ever being contacted. */
        $advice = self::matchAdvice($norm, $raw);

        if ($advice !== '') {
            return self::advise($advice, $raw, $norm, $fil, $kb, $place);
        }

        /* Naming a place makes the question specific, whatever else it looked
           like. "Jadas Falls" on its own means "tell me about it", and "ano ang
           Jadas Falls" is a question about that one place, not a request for
           the whole catalogue — even though it carries a listing cue. */
        if ($place !== null && ($intent === '' || $intent === 'greeting' || $intent === 'list')) {
            $intent = 'overview';
        }

        return match ($intent) {
            'greeting'      => self::greeting($fil),
            'thanks'        => self::thanks($fil),
            'overview'      => self::overview($place, $fil, $kb),
            'fee'           => self::fee($place, $fil, $kb),
            'hours'         => self::hours($place, $fil, $kb),
            'closure'       => self::closure($place, $fil, $kb),
            'location',
            'directions'    => self::whereAndHow($place, $fil, $kb, $intent === 'directions'),
            'facilities'    => self::facilities($place, $fil, $kb),
            'reviews'       => self::reviews($place, $fil, $kb),
            'list'          => self::listing($fil, $kb),
            'events'        => self::events($fil, $kb),
            'weather'       => self::weather($fil, $kb),
            'contact'       => self::contact($fil, $kb),
            'logbook'       => self::logbook($fil),
            'accommodation' => self::guide($fil, $kb, 'accommodation'),
            'transport'     => self::guide($fil, $kb, 'transport'),
            'safety'        => self::guide($fil, $kb, 'safety'),
            'best_time'     => self::guide($fil, $kb, 'best_time'),
            default         => self::unsure($fil, $raw),
        };
    }

    // -------------------------------------------------------------------------
    // Understanding the question
    // -------------------------------------------------------------------------

    /** Lower-cased, punctuation flattened to spaces, whitespace collapsed. */
    private static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function isFilipino(string $norm): bool
    {
        $words = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hits  = count(array_intersect($words, self::FILIPINO_MARKERS));

        /* Two markers, not one. A single "ano" can appear in an English
           sentence containing a Filipino place name; two rarely do. */
        return $hits >= 2 || self::isCebuano($norm);
    }

    /**
     * Cebuano is detected on a single marker, unlike Filipino's two.
     *
     * The markers listed are ones Tagalog does not use — "pila", "asa",
     * "unsa", "giunsa" — so one is already strong evidence. Requiring two
     * would miss the short questions people actually type at a destination.
     */
    private static function isCebuano(string $norm): bool
    {
        $words = preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        /* Excluded from the single-marker rule because Tagalog shares them;
           they only count towards Cebuano alongside a distinctive word. */
        $shared = ['ako', 'ikaw', 'wala', 'lugar', 'kami'];

        $distinct = array_diff(self::CEBUANO_MARKERS, $shared);

        return array_intersect($words, $distinct) !== [];
    }

    /** The language a Gemini reply should come back in. */
    public static function languageOf(string $question): string
    {
        $norm = self::normalise($question);

        if (self::isCebuano($norm)) {
            return 'Cebuano';
        }

        return self::isFilipino($norm) ? 'Filipino' : 'English';
    }

    /**
     * Cues match whole words, never fragments.
     *
     * This is not fussiness. A bare str_contains made "Where is Jadas Falls?"
     * a listing request, because "falls" contains "all" — so the assistant
     * answered a question about one waterfall with the catalogue. Padding both
     * sides with a space turns every cue, single word or phrase, into a
     * word-boundary match with no regex to escape.
     */
    /**
     * Questions this assistant must not answer, whatever they resemble.
     *
     * Two kinds, refused for one reason. Some name data the system genuinely
     * holds and the public must never see — arrival records, visitor details,
     * logins. Others name things it has never held. Left to the ordinary
     * intents, "what is the wifi password at the office" matched on "wifi" and
     * came back with the published facilities list: harmless in content, but it
     * reads as an answer to the question that was asked, and a system that
     * appears to engage with a credential request has already behaved badly.
     */
    private static function isOutOfScope(string $norm): bool
    {
        $haystack = ' ' . $norm . ' ';

        $forbidden = [
            'password', 'passwords', 'login', 'log in', 'username', 'credential', 'api key',
            'admin panel', 'administrator', 'database', 'sql', 'server',
            'arrival record', 'arrival records', 'visitor list', 'visitor records',
            'personal data', 'phone number of the visitor', 'who visited', 'sino ang bumisita',
            'salary', 'sweldo', 'payroll', 'employee list',
        ];

        foreach ($forbidden as $term) {
            if (str_contains($haystack, ' ' . $term . ' ')) {
                return true;
            }
        }

        return false;
    }

    private static function matchIntent(string $norm): string
    {
        $haystack = ' ' . $norm . ' ';

        foreach (self::INTENTS as $intent => $cues) {
            foreach ($cues as $cue) {
                if (str_contains($haystack, ' ' . trim($cue) . ' ')) {
                    return $intent;
                }
            }
        }

        return '';
    }

    /**
     * Which destination is the visitor asking about?
     *
     * Scored rather than first-match: "falls" alone should not beat an exact
     * name, and a question naming two places should resolve to the stronger
     * signal. A full-name match outweighs any number of loose word hits.
     */
    private static function matchDestination(string $norm, array $destinations): ?array
    {
        $haystack = ' ' . $norm . ' ';
        $best     = null;
        $score    = 0;

        foreach ($destinations as $d) {
            $current = 0;

            if (str_contains($haystack, ' ' . mb_strtolower($d['name']) . ' ')) {
                $current += 100;
            }

            /* Whole words here too, for the same reason as matchIntent. */
            foreach ($d['terms'] as $term) {
                if (mb_strlen($term) >= 4 && str_contains($haystack, ' ' . $term . ' ')) {
                    $current += 5;
                }
            }

            if ($current > $score) {
                $score = $current;
                $best  = $d;
            }
        }

        /* Below this, the match rests on one generic word like "falls" and is
           more likely to mislead than to help. */
        return $score >= 10 ? $best : null;
    }

    /** Picks the wording for the language the question was asked in. */
    private static function say(bool $fil, string $en, string $tl): string
    {
        return $fil ? $tl : $en;
    }

    /**
     * Is there a live closure notice for this destination?
     *
     * Checked alongside the opening hours and the overview, not only when
     * somebody thinks to ask "is it closed?" — a visitor who asks what time a
     * waterfall opens and drives out to a shut gate was told the truth and
     * still had their day ruined. The notice outranks the timetable.
     */
    private static function closureFor(array $d, array $kb): ?array
    {
        foreach ($kb['notices']['closures'] as $closure) {
            if ($closure['destination'] !== '' && $closure['destination'] === $d['name']) {
                return $closure;
            }

            /* A closure filed against no particular destination but naming one
               in its title still concerns that destination. */
            if (str_contains(mb_strtolower($closure['title']), mb_strtolower($d['name']))) {
                return $closure;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Answers — a named destination
    // -------------------------------------------------------------------------

    private static function overview(?array $d, bool $fil, array $kb): array
    {
        if ($d === null) {
            return self::listing($fil, $kb);
        }

        $facts = [];
        if ($d['category'] !== '') { $facts[] = [self::say($fil, 'Category', 'Uri'), $d['category']]; }
        if ($d['barangay'] !== '') { $facts[] = [self::say($fil, 'Barangay', 'Barangay'), $d['barangay']]; }
        if ($d['hours'] !== '')    { $facts[] = [self::say($fil, 'Open', 'Bukas'), $d['hours']]; }
        if ($d['fee'] !== '')      { $facts[] = [self::say($fil, 'Entrance', 'Entrance'), $d['fee']]; }

        $reply = $d['summary'] !== ''
            ? $d['name'] . ' — ' . $d['summary']
            : self::say($fil,
                'Here is what the Tourism Office has on record for ' . $d['name'] . '.',
                'Ito ang nakatala ng Tourism Office tungkol sa ' . $d['name'] . '.');

        $closure = self::closureFor($d, $kb);
        if ($closure !== null) {
            $reply .= "\n\n" . self::say($fil,
                'Note: there is an active closure notice for this destination — ' . $closure['title'] . '.',
                'Paalala: may kasalukuyang abiso ng pagsasara para sa lugar na ito — ' . $closure['title'] . '.');
        }

        return self::answer($reply, $facts, [
            [self::say($fil, 'Open the full page', 'Buksan ang buong pahina'), $d['url']],
        ], self::placeSuggestions($d, $fil), 'overview');
    }

    private static function fee(?array $d, bool $fil, array $kb): array
    {
        if ($d === null) {
            $priced = array_values(array_filter($kb['destinations'], static fn($x) => $x['fee'] !== ''));

            if ($priced === []) {
                return self::unsure($fil, '');
            }

            $facts = array_map(static fn($x) => [$x['name'], $x['fee']], $priced);

            return self::answer(
                self::say($fil,
                    'Here are the entrance fees currently on record. Tell me a destination and I will give you just that one.',
                    'Ito ang mga entrance fee na nakatala ngayon. Sabihin mo lang ang lugar at iyon lang ang ibibigay ko.'),
                $facts, [], self::topSuggestions($fil), 'fee'
            );
        }

        if ($d['fee'] === '') {
            return self::missing($d, $fil,
                'There is no entrance fee recorded for ' . $d['name'] . ' yet',
                'Wala pang nakatalang entrance fee para sa ' . $d['name']);
        }

        return self::answer(
            self::say($fil,
                'The entrance fee at ' . $d['name'] . ' is ' . $d['fee'] . '.',
                'Ang entrance fee sa ' . $d['name'] . ' ay ' . $d['fee'] . '.'),
            [], [[self::say($fil, 'See the full details', 'Tingnan ang buong detalye'), $d['url']]],
            self::placeSuggestions($d, $fil), 'fee'
        );
    }

    private static function hours(?array $d, bool $fil, array $kb): array
    {
        if ($d === null) {
            $open = array_values(array_filter($kb['destinations'], static fn($x) => $x['hours'] !== ''));

            if ($open === []) {
                return self::unsure($fil, '');
            }

            return self::answer(
                self::say($fil,
                    'Here are the operating hours on record.',
                    'Ito ang mga oras ng pagbubukas na nakatala.'),
                array_map(static fn($x) => [$x['name'], $x['hours']], $open),
                [], self::topSuggestions($fil), 'hours'
            );
        }

        if ($d['hours'] === '') {
            return self::missing($d, $fil,
                'There are no operating hours recorded for ' . $d['name'] . ' yet',
                'Wala pang nakatalang oras ng pagbubukas para sa ' . $d['name']);
        }

        $reply = self::say($fil,
            $d['name'] . ' is open ' . $d['hours'] . '.',
            'Bukas ang ' . $d['name'] . ' ng ' . $d['hours'] . '.');

        $closure = self::closureFor($d, $kb);
        if ($closure !== null) {
            $reply .= "\n\n" . self::say($fil,
                'But check this first — there is an active closure notice: ' . $closure['title'] . '.',
                'Pero tingnan mo muna ito — may kasalukuyang abiso ng pagsasara: ' . $closure['title'] . '.');
        }

        return self::answer($reply, [],
            [[self::say($fil, 'See the full details', 'Tingnan ang buong detalye'), $d['url']]],
            self::placeSuggestions($d, $fil), 'hours'
        );
    }

    private static function closure(?array $d, bool $fil, array $kb): array
    {
        $closures = $kb['notices']['closures'];

        if ($d !== null) {
            $one = self::closureFor($d, $kb);

            if ($one === null) {
                return self::answer(
                    self::say($fil,
                        'There is no closure notice for ' . $d['name'] . ' right now, so it should be open as usual.',
                        'Walang abiso ng pagsasara para sa ' . $d['name'] . ' ngayon, kaya dapat bukas ito gaya ng dati.'),
                    $d['hours'] !== '' ? [[self::say($fil, 'Open', 'Bukas'), $d['hours']]] : [],
                    [[self::say($fil, 'See the full details', 'Tingnan ang buong detalye'), $d['url']]],
                    self::placeSuggestions($d, $fil), 'closure'
                );
            }

            return self::answer(
                self::say($fil,
                    'Yes — ' . $one['title'] . ' (' . $one['date'] . '). ' . $one['summary'],
                    'Oo — ' . $one['title'] . ' (' . $one['date'] . '). ' . $one['summary']),
                [], [[self::say($fil, 'Read the notice', 'Basahin ang abiso'), $one['url']]],
                self::topSuggestions($fil), 'closure'
            );
        }

        if ($closures === []) {
            return self::answer(
                self::say($fil,
                    'No destination is closed at the moment. If that changes, the Tourism Office posts a notice here first.',
                    'Walang saradong destinasyon sa ngayon. Kapag nagbago iyon, dito muna naglalabas ng abiso ang Tourism Office.'),
                [], [[self::say($fil, 'All announcements', 'Lahat ng abiso'), announcements_url()]],
                self::topSuggestions($fil), 'closure'
            );
        }

        return self::answer(
            self::say($fil, 'These closure notices are currently in force.', 'Ito ang mga kasalukuyang abiso ng pagsasara.'),
            array_map(static fn($c) => [$c['destination'] ?: $c['title'], $c['summary']], $closures),
            [[self::say($fil, 'All announcements', 'Lahat ng abiso'), announcements_url()]],
            self::topSuggestions($fil), 'closure'
        );
    }

    private static function whereAndHow(?array $d, bool $fil, array $kb, bool $directions): array
    {
        if ($d === null) {
            $facts = array_map(
                static fn($x) => [$x['title'], implode(' ', $x['items'] ?? [])],
                []
            );

            return self::answer(
                self::say($fil,
                    'Tampakan is in South Cotabato. ' . implode(' ', $kb['guide']['getting_here']),
                    'Nasa South Cotabato ang Tampakan. ' . implode(' ', $kb['guide']['getting_here'])),
                $facts,
                [
                    [self::say($fil, 'Open the tourist map', 'Buksan ang tourist map'), base_url('/map.php')],
                    [self::say($fil, 'Browse destinations', 'Tingnan ang mga destinasyon'), destinations_url()],
                ],
                self::topSuggestions($fil), $directions ? 'directions' : 'location'
            );
        }

        /* The municipality is appended only when the recorded address does not
           already say it. Staff type the address freehand, and "Barangay
           Danlag, TAMPAKAN, Tampakan, South Cotabato" is what happens when the
           suffix is added unconditionally. */
        $parts = array_values(array_filter([
            $d['barangay'] !== '' ? 'Barangay ' . $d['barangay'] : null,
            $d['address'] !== '' ? $d['address'] : null,
        ]));

        $written = mb_strtolower(implode(' ', $parts));

        if (!str_contains($written, 'tampakan')) {
            $parts[] = 'Tampakan';
        }
        if (!str_contains($written, 'cotabato')) {
            $parts[] = 'South Cotabato';
        }

        $where = $parts !== [] ? $parts : ['Tampakan, South Cotabato'];

        $links = [[self::say($fil, 'See the full details', 'Tingnan ang buong detalye'), $d['url']]];

        if ($d['lat'] !== null && $d['lng'] !== null) {
            $links[] = [
                self::say($fil, 'Get directions', 'Kunin ang direksyon'),
                'https://www.google.com/maps/dir/?api=1&destination=' . $d['lat'] . ',' . $d['lng'],
            ];
        }

        $reply = self::say($fil,
            $d['name'] . ' is in ' . implode(', ', $where) . '.',
            'Ang ' . $d['name'] . ' ay nasa ' . implode(', ', $where) . '.');

        if ($directions) {
            $reply .= "\n\n" . self::say($fil,
                'Getting around: ' . implode(' ', $kb['guide']['transport']),
                'Paglilibot: ' . implode(' ', $kb['guide']['transport']));

            if ($d['lat'] === null) {
                $reply .= "\n\n" . self::say($fil,
                    'No map pin has been recorded for this one yet, so I cannot open turn-by-turn directions.',
                    'Wala pang nakatalang map pin dito, kaya hindi ko mabubuksan ang turn-by-turn na direksyon.');
            }
        }

        return self::answer($reply, [], $links, self::placeSuggestions($d, $fil), $directions ? 'directions' : 'location');
    }

    private static function facilities(?array $d, bool $fil, array $kb): array
    {
        if ($d === null) {
            $with = array_values(array_filter($kb['destinations'], static fn($x) => $x['facilities'] !== []));

            if ($with === []) {
                return self::unsure($fil, '');
            }

            return self::answer(
                self::say($fil,
                    'Here is what each destination has on record. Name one and I will give you just that list.',
                    'Ito ang nakatala sa bawat destinasyon. Sabihin mo ang isa at iyon lang ang ilalabas ko.'),
                array_map(static fn($x) => [$x['name'], implode(', ', $x['facilities'])], $with),
                [], self::topSuggestions($fil), 'facilities'
            );
        }

        if ($d['facilities'] === []) {
            return self::missing($d, $fil,
                'There are no facilities recorded for ' . $d['name'] . ' yet',
                'Wala pang nakatalang pasilidad para sa ' . $d['name']);
        }

        return self::answer(
            self::say($fil,
                $d['name'] . ' has the following on record:',
                'Ito ang nakatalang pasilidad sa ' . $d['name'] . ':'),
            array_map(static fn($f) => ['·', $f], $d['facilities']),
            [[self::say($fil, 'See the full details', 'Tingnan ang buong detalye'), $d['url']]],
            self::placeSuggestions($d, $fil), 'facilities'
        );
    }

    private static function reviews(?array $d, bool $fil, array $kb): array
    {
        if ($d === null || $d['reviews'] === 0) {
            $rated = array_values(array_filter($kb['destinations'], static fn($x) => $x['reviews'] > 0));

            if ($rated === []) {
                return self::answer(
                    self::say($fil,
                        'No ratings yet. Reviews here come only from visitors who scanned the QR code on site, so every one of them was actually there — which is why there are none until people start visiting.',
                        'Wala pang rating. Ang mga review dito ay galing lamang sa mga bisitang nag-scan ng QR code sa mismong lugar, kaya tunay silang nakapunta — kaya wala pa hangga\'t walang bumibisita.'),
                    [], [], self::topSuggestions($fil), 'reviews'
                );
            }

            return self::answer(
                self::say($fil, 'Here is how visitors have rated each destination.', 'Ito ang rating ng mga bisita sa bawat destinasyon.'),
                array_map(
                    static fn($x) => [$x['name'], $x['rating'] . ' / 5 (' . $x['reviews'] . ')'],
                    $rated
                ),
                [], self::topSuggestions($fil), 'reviews'
            );
        }

        return self::answer(
            self::say($fil,
                $d['name'] . ' is rated ' . $d['rating'] . ' out of 5 from ' . $d['reviews'] . ' visitor review(s). Every rating comes from someone who scanned the QR code on site.',
                'Ang ' . $d['name'] . ' ay may rating na ' . $d['rating'] . ' sa 5, mula sa ' . $d['reviews'] . ' review. Ang bawat rating ay galing sa taong nag-scan ng QR code sa mismong lugar.'),
            [], [[self::say($fil, 'Read the reviews', 'Basahin ang mga review'), $d['url'] . '#reviews']],
            self::placeSuggestions($d, $fil), 'reviews'
        );
    }

    // -------------------------------------------------------------------------
    // Answers — the whole municipality
    // -------------------------------------------------------------------------

    private static function listing(bool $fil, array $kb): array
    {
        if ($kb['destinations'] === []) {
            return self::answer(
                self::say($fil,
                    'The Tourism Office has not published any destinations yet.',
                    'Wala pang nailalathalang destinasyon ang Tourism Office.'),
                [], [], [], 'list'
            );
        }

        $facts = array_map(
            static fn($x) => [
                $x['name'],
                trim(($x['category'] !== '' ? $x['category'] : '') . ($x['barangay'] !== '' ? ' · Barangay ' . $x['barangay'] : '')),
            ],
            $kb['destinations']
        );

        return self::answer(
            self::say($fil,
                'There are ' . count($kb['destinations']) . ' destination(s) open to visitors. Name any one and I will tell you its hours, fee, and how to get there.',
                'May ' . count($kb['destinations']) . ' destinasyong bukas sa mga bisita. Sabihin mo ang alinman at sasabihin ko ang oras, bayad, at paano pumunta.'),
            $facts,
            [
                [self::say($fil, 'Browse all destinations', 'Tingnan lahat ng destinasyon'), destinations_url()],
                [self::say($fil, 'Open the tourist map', 'Buksan ang tourist map'), base_url('/map.php')],
            ],
            /* The destination names themselves, as chips. A bare name is a
               question this assistant already understands — it routes to the
               overview — so the label and the question are legitimately the
               same string here. Wrapped in suggest() anyway so every intent
               returns one shape. */
            array_map(
                static fn($x) => self::suggest($x['name'], $x['name']),
                array_slice($kb['destinations'], 0, 4)
            ),
            'list'
        );
    }

    private static function events(bool $fil, array $kb): array
    {
        if ($kb['events'] === []) {
            return self::answer(
                self::say($fil,
                    'No upcoming events are scheduled at the moment. The Tourism Office posts them here as soon as the dates are set.',
                    'Walang nakatakdang paparating na event sa ngayon. Dito ito inilalabas ng Tourism Office pagkatapos maitakda ang petsa.'),
                [], [[self::say($fil, 'All announcements', 'Lahat ng abiso'), announcements_url()]],
                self::topSuggestions($fil), 'events'
            );
        }

        return self::answer(
            self::say($fil, 'These events are coming up.', 'Ito ang mga paparating na event.'),
            array_map(
                static fn($e) => [$e['title'], trim($e['date'] . ($e['location'] !== '' ? ' · ' . $e['location'] : ''))],
                $kb['events']
            ),
            [[self::say($fil, 'See all events', 'Tingnan lahat ng event'), announcements_url(['type' => 'event'])]],
            self::topSuggestions($fil), 'events'
        );
    }

    private static function weather(bool $fil, array $kb): array
    {
        /* Fetched here rather than with the rest of the knowledge base: this is
           the only intent that needs it. See KnowledgeBase::weather(). */
        $w = KnowledgeBase::weather();

        if ($w === null) {
            return self::answer(
                self::say($fil,
                    'The forecast service cannot be reached right now. Tampakan generally sits between 21°C and 28°C, and November to April is the driest stretch.',
                    'Hindi maabot ang serbisyo ng forecast ngayon. Karaniwang nasa 21°C hanggang 28°C ang Tampakan, at Nobyembre hanggang Abril ang pinakatuyot.'),
                [], [], self::topSuggestions($fil), 'weather'
            );
        }

        return self::answer(
            self::say($fil,
                'It is ' . (int) $w['temperature'] . '°C in Tampakan right now — ' . $w['label'] . '. ' . $w['advice'],
                'Ngayon ay ' . (int) $w['temperature'] . '°C sa Tampakan — ' . $w['label'] . '. ' . $w['advice']),
            [
                [self::say($fil, 'Feels like', 'Pakiramdam'), (int) $w['feels_like'] . '°C'],
                [self::say($fil, 'Humidity', 'Halumigmig'), (int) $w['humidity'] . '%'],
                [self::say($fil, 'Wind', 'Hangin'), $w['wind'] . ' km/h'],
            ],
            [[self::say($fil, 'Five-day outlook', 'Limang araw na forecast'), base_url('/#weather')]],
            self::topSuggestions($fil), 'weather'
        );
    }

    private static function contact(bool $fil, array $kb): array
    {
        $o = $kb['office'];

        $facts = [[self::say($fil, 'Address', 'Adres'), $o['address']]];
        if ($o['phone'] !== '') { $facts[] = [self::say($fil, 'Telephone', 'Telepono'), $o['phone']]; }
        if ($o['email'] !== '') { $facts[] = [self::say($fil, 'Email', 'Email'), $o['email']]; }
        $facts[] = [self::say($fil, 'Open', 'Bukas'), $o['hours']];

        return self::answer(
            self::say($fil,
                'You can reach the ' . $o['name'] . ' here.',
                'Maaari mong abutin ang ' . $o['name'] . ' dito.'),
            $facts,
            [[self::say($fil, 'Contact section', 'Seksyon ng kontak'), base_url('/#contact')]],
            self::topSuggestions($fil), 'contact'
        );
    }

    private static function logbook(bool $fil): array
    {
        return self::answer(
            self::say($fil,
                'Every destination has a QR code on a sign at the site. Scan it with your phone camera and the visitor logbook opens for that exact place — you never have to pick it from a list. It works even with no signal: your entry is held on your phone and sent automatically once you are back in coverage.',
                'May QR code sa karatula ng bawat destinasyon. I-scan mo ito gamit ang camera ng telepono mo at bubukas ang logbook para sa mismong lugar na iyon — hindi mo na kailangang pumili sa listahan. Gumagana ito kahit walang signal: iniimbak sa telepono mo ang entry at awtomatikong ipapadala pagbalik ng koneksyon.'),
            [], [[self::say($fil, 'Browse destinations', 'Tingnan ang mga destinasyon'), destinations_url()]],
            self::topSuggestions($fil), 'logbook'
        );
    }

    private static function guide(bool $fil, array $kb, string $key): array
    {
        $titles = [
            'accommodation' => ['Where to stay', 'Saan matutuluyan'],
            'transport'     => ['Getting around Tampakan', 'Paglilibot sa Tampakan'],
            'safety'        => ['Staying safe on the trail', 'Pananatiling ligtas sa trail'],
            'best_time'     => ['The best time to visit', 'Ang pinakamagandang panahon bumisita'],
        ];

        return self::answer(
            self::say($fil, $titles[$key][0] . ':', $titles[$key][1] . ':'),
            array_map(static fn($item) => ['·', $item], $kb['guide'][$key]),
            [[self::say($fil, 'Full travel guide', 'Buong travel guide'), base_url('/#travel-guide')]],
            self::topSuggestions($fil), $key
        );
    }

    // -------------------------------------------------------------------------
    // Answers — social, and not knowing
    // -------------------------------------------------------------------------

    private static function greeting(bool $fil): array
    {
        return self::answer(
            self::say($fil,
                'Hello! I can answer questions about the destinations in Tampakan — entrance fees, opening hours, facilities, how to get there, upcoming events, and the weather. What would you like to know?',
                'Kumusta! Kaya kong sagutin ang mga tanong tungkol sa mga destinasyon sa Tampakan — entrance fee, oras ng pagbubukas, pasilidad, paano pumunta, paparating na event, at ang panahon. Ano ang gusto mong malaman?'),
            [], [], self::topSuggestions($fil), 'greeting'
        );
    }

    private static function thanks(bool $fil): array
    {
        return self::answer(
            self::say($fil,
                'Any time. Enjoy Tampakan.',
                'Walang anuman. Mag-enjoy ka sa Tampakan.'),
            [], [], self::topSuggestions($fil), 'thanks'
        );
    }

    /**
     * A recorded fact is missing, which is a different failure from not
     * understanding the question — and the visitor deserves to be told which,
     * because only one of them is worth rephrasing.
     */
    private static function missing(array $d, bool $fil, string $en, string $tl): array
    {
        return self::answer(
            self::say($fil,
                $en . '. The full page may have more, and the Tourism Office can confirm it directly.',
                $tl . '. Baka may iba pa sa buong pahina, at kayang kumpirmahin ito ng Tourism Office.'),
            [], [
                [self::say($fil, 'Open the full page', 'Buksan ang buong pahina'), $d['url']],
                [self::say($fil, 'Contact the office', 'Kontakin ang opisina'), base_url('/#contact')],
            ],
            self::placeSuggestions($d, $fil), 'missing'
        );
    }

    /**
     * The honest answer, and the reason this assistant is defensible.
     *
     * It does not guess, and it does not manufacture a plausible-sounding
     * entrance fee. It says what it covers and hands over the office.
     */
    private static function unsure(bool $fil, string $raw): array
    {
        return self::answer(
            self::say($fil,
                'I could not find that in the Tourism Office records, so I would rather not guess. I can answer questions about entrance fees, opening hours, facilities, locations and directions, closures, upcoming events, the weather, and how to reach the office. For anything else, the office itself is the right place to ask.',
                'Hindi ko iyon nakita sa mga tala ng Tourism Office, kaya ayokong manghula. Kaya kong sagutin ang tungkol sa entrance fee, oras ng pagbubukas, pasilidad, lokasyon at direksyon, pagsasara, paparating na event, panahon, at paano abutin ang opisina. Para sa iba pa, ang opisina mismo ang tamang tanungin.'),
            [], [[self::say($fil, 'Contact the Tourism Office', 'Kontakin ang Tourism Office'), base_url('/#contact')]],
            self::topSuggestions($fil), 'unsure', false
        );
    }

    // -------------------------------------------------------------------------
    // Shaping the reply
    // -------------------------------------------------------------------------

    private static function answer(
        string $reply,
        array $facts = [],
        array $links = [],
        array $suggestions = [],
        string $intent = '',
        bool $answered = true
    ): array {
        return [
            'reply'       => trim($reply),
            'facts'       => array_map(static fn($f) => ['label' => (string) $f[0], 'value' => (string) $f[1]], $facts),
            'links'       => array_map(static fn($l) => ['label' => (string) $l[0], 'href' => (string) $l[1]], $links),
            'suggestions' => array_values($suggestions),
            'intent'      => $intent,
            'answered'    => $answered,
        ];
    }

    /**
     * A suggestion is two things, not one.
     *
     * The label is what fits on a pill; the question is what the assistant
     * needs in order to answer. Conflating them forced whole sentences into
     * chips — "What destinations can I visit?" — which then wrapped, or got
     * sliced off at the panel edge, and read as broken layout rather than as a
     * button. Short label, full question underneath it.
     *
     * The question must still name the destination: this assistant is
     * stateless, and "Entrance fee" on its own means nothing to it.
     */
    private static function suggest(string $label, string $ask): array
    {
        return ['label' => $label, 'ask' => $ask];
    }

    /** Follow-up questions that are certain to have an answer. */
    private static function placeSuggestions(array $d, bool $fil): array
    {
        $n = $d['name'];

        return $fil
            ? [
                self::suggest('Entrance fee',  'Magkano ang entrance sa ' . $n . '?'),
                self::suggest('Oras ng bukas', 'Anong oras bukas ang ' . $n . '?'),
                self::suggest('Paano pumunta', 'Paano pumunta sa ' . $n . '?'),
                self::suggest('Pasilidad',     'Anong pasilidad meron sa ' . $n . '?'),
            ]
            : [
                self::suggest('Entrance fee',  'How much is the entrance at ' . $n . '?'),
                self::suggest('Opening hours', 'What time does ' . $n . ' open?'),
                self::suggest('Directions',    'How do I get to ' . $n . '?'),
                self::suggest('Facilities',    'What facilities does ' . $n . ' have?'),
            ];
    }

    // =========================================================================
    // LEVEL 2 — the questions that need language
    // =========================================================================

    /**
     * Cues for the four things Gemini is genuinely better at than a lookup.
     *
     * Kept narrow on purpose. Every question that matches here costs a call to
     * a paid API; every question that does not is answered for free from the
     * database. Widening these lists is how a municipal system acquires a
     * monthly bill it did not need.
     */
    private const ADVICE = [
        'compare'   => ['compare', 'comparison', 'versus', ' vs ', 'ihambing', 'alin ang mas', 'which is better',
                        'mas maganda ba', 'difference between', 'pagkakaiba'],
        'itinerary' => ['itinerary', 'plan my', 'plan a trip', 'plan our', 'one day', 'isang araw', 'dalawang araw',
                        'two days', 'weekend', 'schedule my', 'what should i do', 'ano ang gagawin',
                        'saan muna', 'which first', 'visit first', 'unahin'],
        'recommend' => ['recommend', 'suggest', 'irekomenda', 'imumungkahi', 'best for', 'good for', 'maganda para',
                        'maganda ba para', 'angkop', 'bagay para', 'nature lover', 'photography', 'romantic',
                        'family friendly', 'pang-pamilya', 'para sa pamilya', 'para sa bata', 'for kids',
                        'quiet place', 'tahimik', 'adventure', 'hiking', 'swimming', 'relaxing', 'pahinga'],
    ];

    /**
     * Which Level 2 question is this, if any?
     *
     * Budget is decided by the presence of an amount rather than by wording,
     * because "I have 3000" and "may 3k ako" carry no cue word at all — the
     * number is the signal. A named destination plus a fee question is
     * deliberately excluded: "magkano sa Kolon Ridge" is a lookup, not a
     * planning request, and must stay free.
     */
    private static function matchAdvice(string $norm, string $raw): string
    {
        $haystack = ' ' . $norm . ' ';

        foreach (self::ADVICE as $kind => $cues) {
            foreach ($cues as $cue) {
                if (str_contains($haystack, ' ' . trim($cue) . ' ')) {
                    return $kind;
                }
            }
        }

        if (TripBudget::amountIn($raw) !== null && self::matchIntent($norm) !== 'fee') {
            return 'budget';
        }

        return '';
    }

    /**
     * Assembles the facts, hands them to Gemini, and degrades to a useful
     * answer when Gemini cannot be reached.
     *
     * The fallback is not an error message. The shortlist, the totals and the
     * links are the system's own work; losing the AI costs the visitor the
     * prose around them, not the answer itself.
     */
    private static function advise(string $kind, string $raw, string $norm, bool $fil, array $kb, ?array $place): array
    {
        [$context, $facts, $links, $fallback] = match ($kind) {
            'budget'    => self::budgetMaterial($raw, $fil),
            'compare'   => self::compareMaterial($norm, $fil, $kb),
            'itinerary' => self::itineraryMaterial($fil, $kb),
            default     => self::recommendMaterial($norm, $fil, $kb, $place),
        };

        /* Nothing to reason over. Cheaper and more honest to say so than to ask
           a model to be imaginative about an empty catalogue. */
        if ($context === '') {
            return self::unsure($fil, $raw);
        }

        if (!Gemini::isConfigured() || !Gemini::withinQuota(self::quotaKey())) {
            return self::answer($fallback, $facts, $links, self::topSuggestions($fil), $kind);
        }

        /* The language is decided here, not left to the model to infer from a
           three-word question. "Pila ang bayad?" is short enough that a model
           can read it as Tagalog and answer in the wrong one. */
        $context .= "\n\nReply in " . self::languageOf($raw) . '.';

        $result = Gemini::ask($raw, $context);

        if (!$result['ok']) {
            /* One line acknowledging the AI is down, then the deterministic
               answer underneath it. The visitor still gets the shortlist. */
            $notice = self::say($fil,
                'The AI assistant is unavailable right now, so here is what the records show.',
                'Hindi available ang AI assistant ngayon, kaya heto ang nasa mga tala.');

            return self::answer($notice . "\n\n" . $fallback, $facts, $links, self::topSuggestions($fil), $kind);
        }

        return self::answer($result['text'], $facts, $links, self::topSuggestions($fil), $kind);
    }

    /** Per-visitor quota key. Session first so one office IP is not one bucket. */
    private static function quotaKey(): string
    {
        return (string) (session_id() ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }

    // -------------------------------------------------------------------------
    // Material for each kind of advice
    // -------------------------------------------------------------------------

    /** @return array{0:string,1:array,2:array,3:string} context, facts, links, fallback */
    private static function budgetMaterial(string $raw, bool $fil): array
    {
        $amount = TripBudget::amountIn($raw);

        if ($amount === null) {
            return ['', [], [], ''];
        }

        /* "for 4 people" / "kaming apat" — party size changes every figure, so
           it is read from the question rather than assumed to be one. */
        $party = 1;
        if (preg_match('/(\d+)\s*(?:pax|people|persons?|tao|katao)/u', mb_strtolower($raw), $m) === 1) {
            $party = (int) $m[1];
        }

        $plan  = TripBudget::plan($amount, $party);
        $facts = TripBudget::toFacts($plan);

        $links = [[self::say($fil, 'Browse all destinations', 'Tingnan lahat ng destinasyon'), destinations_url()]];

        $fallback = $plan['included'] === []
            ? self::say($fil,
                'On ₱' . number_format($plan['budget'], 2) . ', the recorded entrance fees do not fit. The breakdown is below.',
                'Sa ₱' . number_format($plan['budget'], 2) . ', hindi kasya ang mga nakatalang entrance fee. Nasa ibaba ang breakdown.')
            : self::say($fil,
                'With ₱' . number_format($plan['budget'], 2) . ' you can cover the entrance fees below, leaving ₱'
                    . number_format($plan['remaining'], 2) . '.',
                'Sa ₱' . number_format($plan['budget'], 2) . ' kaya mong sagutin ang mga entrance fee sa ibaba, may matitira pang ₱'
                    . number_format($plan['remaining'], 2) . '.');

        if ($plan['notes'] !== []) {
            $fallback .= "\n\n" . implode(' ', $plan['notes']);
        }

        return [TripBudget::toContext($plan), $facts, $links, $fallback];
    }

    /** @return array{0:string,1:array,2:array,3:string} */
    private static function recommendMaterial(string $norm, bool $fil, array $kb, ?array $place): array
    {
        $picks = self::rank($norm, $kb['destinations'], $place);

        if ($picks === []) {
            return ['', [], [], ''];
        }

        $links = array_map(
            static fn($d) => [$d['name'], $d['url']],
            array_slice($picks, 0, 3)
        );

        $fallback = self::say($fil,
            'From the records, these fit best:',
            'Mula sa mga tala, ito ang pinaka-angkop:');

        return [
            self::contextFor($picks, $kb),
            array_map(
                static fn($d) => [$d['name'], trim($d['category'] . ($d['summary'] !== '' ? ' · ' . $d['summary'] : ''))],
                $picks
            ),
            $links,
            $fallback,
        ];
    }

    /** @return array{0:string,1:array,2:array,3:string} */
    private static function compareMaterial(string $norm, bool $fil, array $kb): array
    {
        /* Everything the question names, not just the strongest match — a
           comparison of one destination is not a comparison. */
        $named = array_values(array_filter(
            $kb['destinations'],
            static fn($d) => str_contains(' ' . $norm . ' ', ' ' . mb_strtolower($d['name']) . ' ')
        ));

        $picks = count($named) >= 2 ? $named : array_slice($kb['destinations'], 0, 4);

        if ($picks === []) {
            return ['', [], [], ''];
        }

        return [
            self::contextFor($picks, $kb),
            array_map(
                static fn($d) => [
                    $d['name'],
                    trim(implode(' · ', array_filter([
                        $d['category'],
                        $d['fee'] !== '' ? $d['fee'] : null,
                        $d['hours'] !== '' ? $d['hours'] : null,
                    ]))),
                ],
                $picks
            ),
            array_map(static fn($d) => [$d['name'], $d['url']], array_slice($picks, 0, 3)),
            self::say($fil, 'Side by side, from the records:', 'Magkatabi, mula sa mga tala:'),
        ];
    }

    /** @return array{0:string,1:array,2:array,3:string} */
    private static function itineraryMaterial(bool $fil, array $kb): array
    {
        if ($kb['destinations'] === []) {
            return ['', [], [], ''];
        }

        $picks = array_slice($kb['destinations'], 0, 6);

        return [
            self::contextFor($picks, $kb),
            array_map(
                static fn($d) => [$d['name'], $d['hours'] !== '' ? $d['hours'] : ($d['category'] ?: '—')],
                $picks
            ),
            [[self::say($fil, 'Open the tourist map', 'Buksan ang tourist map'), base_url('/map.php')]],
            self::say($fil,
                'These are the destinations on record, with their opening hours:',
                'Ito ang mga destinasyong nakatala, kasama ang oras ng pagbubukas:'),
        ];
    }

    // -------------------------------------------------------------------------
    // Building the context
    // -------------------------------------------------------------------------

    /**
     * Scores destinations against the words in the question.
     *
     * Matching runs over the summary, category and facilities — the fields
     * where "family", "swimming" or "quiet" would actually have been written
     * by the Tourism Office. When nothing scores, the first few are returned
     * rather than an empty list: a visitor asking for a recommendation should
     * get destinations, not a shrug.
     */
    private static function rank(string $norm, array $destinations, ?array $place): array
    {
        if ($place !== null) {
            return [$place];
        }

        $words = array_filter(
            preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn($w) => mb_strlen($w) >= 4
        );

        $scored = [];

        foreach ($destinations as $d) {
            $hay = mb_strtolower(implode(' ', array_filter([
                $d['summary'], $d['category'], implode(' ', $d['facilities']), $d['barangay'],
            ])));

            $score = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $score++;
                }
            }

            $scored[] = ['score' => $score, 'd' => $d];
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

        $picked = array_map(static fn($r) => $r['d'], array_slice($scored, 0, 4));

        return $picked;
    }

    /**
     * The records, as text, and nothing else.
     *
     * This is the whole of what Gemini is shown. Four destinations at most,
     * only the fields a visitor asks about, and any live closure attached to
     * them — because a recommendation to visit a shut waterfall is worse than
     * no recommendation. No arrival records, no visitor details, no admin data.
     */
    private static function contextFor(array $picks, array $kb): string
    {
        if ($picks === []) {
            return '';
        }

        $lines = ['DESTINATIONS ON RECORD:'];

        foreach (array_slice($picks, 0, 4) as $d) {
            $bits = array_filter([
                $d['category'] !== ''  ? 'Category: ' . $d['category'] : null,
                $d['barangay'] !== ''  ? 'Barangay ' . $d['barangay'] : null,
                $d['hours'] !== ''     ? 'Open: ' . $d['hours'] : null,
                $d['fee'] !== ''       ? 'Entrance: ' . $d['fee'] : 'Entrance: not published',
                $d['facilities'] !== [] ? 'Facilities: ' . implode(', ', $d['facilities']) : null,
                $d['summary'] !== ''   ? $d['summary'] : null,
                $d['reminders'] !== '' ? 'Reminders: ' . mb_substr($d['reminders'], 0, 160) : null,
            ]);

            $lines[] = '';
            $lines[] = $d['name'];
            foreach ($bits as $bit) {
                $lines[] = '  ' . $bit;
            }

            foreach ($kb['notices']['closures'] as $closure) {
                if ($closure['destination'] === $d['name']) {
                    $lines[] = '  CLOSURE IN FORCE: ' . $closure['title'];
                }
            }
        }

        /* Only the Level 2 path pays for this — a recommendation is worth the
           extra call, a fee lookup is not. */
        $weather = KnowledgeBase::weather();

        if ($weather !== null) {
            $lines[] = '';
            $lines[] = 'Weather in Tampakan now: ' . (int) $weather['temperature'] . '°C, ' . $weather['label'] . '.';
        }

        return implode("\n", $lines);
    }

    public static function topSuggestions(bool $fil = false): array
    {
        return $fil
            ? [
                self::suggest('Mga lugar',     'Anong mga lugar ang mapupuntahan?'),
                self::suggest('May sarado?',   'May saradong destinasyon ba?'),
                self::suggest('Panahon',       'Kumusta ang panahon ngayon?'),
                self::suggest('Paano pumunta', 'Paano pumunta sa Tampakan?'),
                self::suggest('Mga event',     'Anong mga event ang paparating?'),
            ]
            : [
                self::suggest('Destinations',  'What destinations can I visit?'),
                self::suggest('Anything closed?', 'Is anything closed right now?'),
                self::suggest('Weather',       "What's the weather like?"),
                self::suggest('Getting here',  'How do I get to Tampakan?'),
                self::suggest('Events',        'What events are coming up?'),
            ];
    }
}
