<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\AnnouncementRepository;
use App\Repositories\DestinationRepository;

/**
 * =============================================================================
 *  Everything the assistant is allowed to know.                     Feature 4
 * -----------------------------------------------------------------------------
 *  One place, assembled from the same sources the landing page renders, so an
 *  answer can never drift from what the visitor reads on the page. When the
 *  Tourism Office edits an entrance fee in the admin, the assistant's answer
 *  changes in the same breath — there is no second copy to remember to update.
 *
 *  WHAT IS DELIBERATELY ABSENT
 *
 *  No arrival records. No visitor names, contact numbers, or origins. Nothing
 *  from the logbook reaches this class, and nothing personal can therefore be
 *  quoted back by an assistant, leak into a log, or — should an external model
 *  ever be added behind the same interface — leave the country. The corpus is
 *  exactly the material already published on the public website.
 * =============================================================================
 */
final class KnowledgeBase
{
    /** Cached for the request; several intents read the same facts. */
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        return self::$cache = [
            'destinations' => self::destinations(),
            'notices'      => self::notices(),
            'events'       => self::events(),
            'office'       => self::office(),
            'guide'        => self::guide(),
        ];
    }

    /**
     * The forecast, fetched only when something actually asks for it.
     *
     * Deliberately not part of all(). Weather is the one entry here that can
     * reach off the server, and having it in the eager set made every question
     * — "magkano ang entrance", "anong oras bukas" — wait on an outbound HTTP
     * call it had no use for. On a slow link that turned an instant database
     * answer into a four-second one.
     *
     * Weather::forecast() caches server-side, so asking twice in a request is
     * free; the cost being avoided here is the cold fetch, not the repeat.
     */
    public static function weather(): ?array
    {
        return Weather::forecast();
    }

    /** Forgets the cache. Only useful in tests that mutate the database. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    // -------------------------------------------------------------------------
    // Destinations
    // -------------------------------------------------------------------------

    /**
     * Each destination reduced to the facts a visitor asks about, plus the
     * search terms that should reach it.
     */
    private static function destinations(): array
    {
        $out = [];

        foreach (DestinationRepository::published() as $row) {
            $facilities = DestinationRepository::decodeFacilities($row['facilities'] ?? null);

            $out[] = [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'slug'       => (string) $row['slug'],
                'category'   => (string) ($row['category_name'] ?? ''),
                'barangay'   => (string) ($row['barangay'] ?? ''),
                'address'    => (string) ($row['address'] ?? ''),
                'hours'      => (string) ($row['operating_hours'] ?? ''),
                'fee'        => (string) ($row['entrance_fee'] ?? ''),
                'summary'    => (string) ($row['short_description'] ?? ''),
                'history'    => (string) ($row['history'] ?? ''),
                'reminders'  => (string) ($row['reminders'] ?? ''),
                'facilities' => array_values(array_filter(array_map('strval', $facilities))),
                'contact'    => array_values(array_filter([
                    $row['contact_person'] ?? null,
                    $row['contact_phone'] ?? null,
                    $row['contact_email'] ?? null,
                ])),
                'lat'        => $row['latitude']  !== null ? (float) $row['latitude']  : null,
                'lng'        => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'rating'     => (float) ($row['avg_rating'] ?? 0),
                'reviews'    => (int) ($row['review_count'] ?? 0),
                'url'        => base_url('/destination.php?slug=' . $row['slug']),

                /* Everything a person might type to mean this place: the name,
                   its words, the barangay, and the category. Matching against
                   this rather than the name alone is what lets "yung falls sa
                   Liberty" find Jadas Falls. */
                'terms'      => self::terms([
                    $row['name'],
                    $row['barangay'],
                    $row['category_name'] ?? '',
                ]),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Notices and events
    // -------------------------------------------------------------------------

    /**
     * Live advisories. Closures are separated out because they are the one
     * notice that changes the answer to every other question about a place —
     * the fee and the opening hours do not matter if the site is shut.
     */
    private static function notices(): array
    {
        $out = ['closures' => [], 'advisories' => [], 'other' => []];

        foreach (AnnouncementRepository::publicFeed(null, 40) as $row) {
            $item = [
                'title'       => (string) $row['title'],
                'type'        => (string) $row['type'],
                'summary'     => (string) ($row['summary'] ?: mb_substr(strip_tags((string) $row['body']), 0, 220)),
                'destination' => (string) ($row['destination_name'] ?? ''),
                'date'        => format_date($row['publish_at'] ?: $row['created_at']),
                'url'         => base_url('/announcement.php?slug=' . $row['slug']),
            ];

            if ($row['type'] === 'closure') {
                $out['closures'][] = $item;
            } elseif ($row['type'] === 'advisory') {
                $out['advisories'][] = $item;
            } elseif ($row['type'] !== 'event') {
                $out['other'][] = $item;
            }
        }

        return $out;
    }

    private static function events(): array
    {
        $out = [];

        foreach (AnnouncementRepository::upcomingEvents(8) as $row) {
            $out[] = [
                'title'    => (string) $row['title'],
                'date'     => $row['event_date'] ? format_date($row['event_date'], 'l, F j, Y') : '',
                'location' => (string) ($row['event_location'] ?: ($row['destination_name'] ?? '')),
                'summary'  => (string) ($row['summary'] ?: mb_substr(strip_tags((string) $row['body']), 0, 180)),
                'url'      => base_url('/announcement.php?slug=' . $row['slug']),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // The office, and the parts of the page that are not in the database
    // -------------------------------------------------------------------------

    private static function office(): array
    {
        return [
            'name'    => (string) setting('office_name', 'Municipal Tourism Office'),
            'address' => (string) setting('office_address', 'Municipal Hall Compound, Poblacion, Tampakan, South Cotabato'),
            'phone'   => (string) setting('office_phone', ''),
            'email'   => (string) setting('office_email', ''),
            'hours'   => 'Monday to Friday, 8:00 AM to 5:00 PM',
        ];
    }

    /**
     * The travel-guide content, which lives in the page rather than the
     * database. Duplicated here rather than parsed out of index.php — but kept
     * to the same wording, and the comment on the page points back to this
     * class so the two are edited together.
     */
    private static function guide(): array
    {
        return [
            'getting_here' => [
                'General Santos (GES) to Tampakan: about 1 hour 30 minutes overland.',
                'Koronadal to Tampakan: about 40 minutes.',
                'Daily vans and buses run the Marbel route.',
            ],
            'transport' => [
                'Tricycles within Poblacion.',
                'Habal-habal for the upland barangays.',
                'Van rentals for group day tours.',
            ],
            'accommodation' => [
                'Inns and pension houses in Poblacion.',
                'Community-run homestays.',
                'Designated eco-camping grounds.',
            ],
            'safety' => [
                'Register at the visitor desk before trekking.',
                'Hire accredited local guides.',
                'Pack layers — nights drop below 18°C.',
            ],
            'best_time' => [
                'November to April is driest, with the clearest views.',
                'September is founding anniversary week.',
                'For sunrise treks, arrive on site by 4:30 AM.',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Lower-cased, de-duplicated words of three letters or more. */
    private static function terms(array $sources): array
    {
        $words = [];

        foreach (array_filter($sources) as $source) {
            $clean = mb_strtolower(trim((string) $source));
            if ($clean === '') {
                continue;
            }

            $words[] = $clean;
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= 3) {
                    $words[] = $word;
                }
            }
        }

        return array_values(array_unique($words));
    }
}
