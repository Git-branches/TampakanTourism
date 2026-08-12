<?php
declare(strict_types=1);

namespace App\Core;

/**
 * =============================================================================
 *  TourSync — visitor trip budgeting                                Feature 4
 * -----------------------------------------------------------------------------
 *  Every peso a tourist is shown is added up here, in PHP, from fees the
 *  Tourism Office typed into the admin. Gemini is handed the finished figures
 *  and asked to explain them — it never sees the inputs and is never in a
 *  position to add anything up.
 *
 *  That split is not ceremony. A tourist who budgets a day around a total the
 *  assistant produced has made a real decision with real money, and "the model
 *  computed it" is not an answer when the sum is wrong.
 *
 *  WHAT IT REFUSES TO GUESS
 *
 *  Entrance fees are in the database, so they are used. Transport and meals are
 *  not, so they are reported as unpublished rather than filled with a plausible
 *  figure — unless the Office has set an allowance, in which case the figure is
 *  theirs and is labelled as theirs. A fee stored in a form this class cannot
 *  read is reported unknown rather than treated as zero: a free destination and
 *  one whose price nobody recorded are different facts, and quietly merging
 *  them understates the trip.
 * =============================================================================
 */
final class TripBudget
{
    /** Below this, the number in the question is not a budget. */
    private const MIN_BUDGET = 50.0;

    /** Above this, it is a typo or a joke. */
    private const MAX_BUDGET = 1000000.0;

    // -------------------------------------------------------------------------
    // Reading the question
    // -------------------------------------------------------------------------

    /**
     * Pulls a peso amount out of the visitor's own words.
     *
     * Handles the forms people actually type: "₱5,000", "P3000", "5k",
     * "may 2,500 ako", "budget is 1500 pesos".
     */
    public static function amountIn(string $question): ?float
    {
        $text = mb_strtolower($question);

        /* A number alone is not a budget.
         *
         * "Who won the 1998 world cup?" contains a number in the plausible
         * range, and reading it as a budget sent a football question to a paid
         * API as a trip-planning request. The money has to be signalled — by a
         * currency mark, by "k", or by the visitor saying they have or want to
         * spend it. */
        $hasCurrency = preg_match('/(?:₱|php\b|\bp\d|\d\s*(?:pesos?|piso|php)\b)/u', $text) === 1;
        $hasK        = preg_match('/\d+\s*k\b/u', $text) === 1;
        $hasIntent   = preg_match(
            '/\b(budget|afford|spend|gastos|magkano.*(?:kaya|pwede)|i have|meron|mayroon|may\s+\d|baon|pera|money|cost me)\b/u',
            $text
        ) === 1;

        if (!$hasCurrency && !$hasK && !$hasIntent) {
            return null;
        }

        /* "5k" / "10 k" — written more often than the full figure. */
        if (preg_match('/(\d+(?:\.\d+)?)\s*k\b/u', $text, $m) === 1) {
            return self::sane((float) $m[1] * 1000);
        }

        /* Any number with optional thousands separators, peso sign, or the
           word peso either side of it. */
        if (preg_match('/(?:₱|php|p)?\s*(\d{1,3}(?:[,\s]\d{3})+|\d+)(?:\.\d{1,2})?\s*(?:pesos?|piso|php)?/u', $text, $m) === 1) {
            return self::sane((float) str_replace([',', ' '], '', $m[1]));
        }

        return null;
    }

    private static function sane(float $value): ?float
    {
        return ($value >= self::MIN_BUDGET && $value <= self::MAX_BUDGET) ? round($value, 2) : null;
    }

    /**
     * Reads a stored entrance fee into a number.
     *
     * The column is free text because the Office writes things like
     * "20 PER PERSON", "Free for residents", "₱50/head". Three outcomes, and
     * the third one matters:
     *
     *   0.0   explicitly free
     *   n     a figure was found
     *   null  nothing readable — reported as unknown, never as zero
     */
    public static function feeToNumber(?string $fee): ?float
    {
        $text = mb_strtolower(trim((string) $fee));

        if ($text === '') {
            return null;
        }

        if (preg_match('/\b(free|libre|walang bayad|no fee|no entrance)\b/u', $text) === 1) {
            return 0.0;
        }

        if (preg_match('/(\d{1,3}(?:,\d{3})+|\d+(?:\.\d{1,2})?)/u', $text, $m) === 1) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // The plan
    // -------------------------------------------------------------------------

    /**
     * Builds a costed shortlist inside a budget.
     *
     * Cheapest first, not "best" first: the goal is to fit as much of Tampakan
     * into the money as the money allows, and ranking by preference would mean
     * inventing a preference nobody stated.
     *
     * @return array{
     *   budget:float, party:int, included:array, excluded:array, unknown:array,
     *   entrance_total:float, transport:?float, meals:?float, total:float,
     *   remaining:float, notes:array
     * }
     */
    public static function plan(float $budget, int $party = 1): array
    {
        $party = max(1, min(20, $party));

        $priced  = [];
        $unknown = [];

        foreach (KnowledgeBase::all()['destinations'] as $d) {
            $fee = self::feeToNumber($d['fee']);

            if ($fee === null) {
                $unknown[] = ['name' => $d['name'], 'reason' => $d['fee'] === '' ? 'no fee recorded' : $d['fee']];
                continue;
            }

            $priced[] = [
                'name'      => $d['name'],
                'category'  => $d['category'],
                'barangay'  => $d['barangay'],
                'hours'     => $d['hours'],
                'fee_each'  => $fee,
                'fee_party' => round($fee * $party, 2),
                'url'       => $d['url'],
            ];
        }

        usort($priced, static fn($a, $b) => $a['fee_party'] <=> $b['fee_party']);

        /* Office-published allowances, deducted before destinations so the
           tourist is not shown a shortlist they cannot actually reach. Null
           when the Office has not set one — never a default. */
        $transport = self::allowance('trip_transport_estimate', $party);
        $meals     = self::allowance('trip_meal_estimate', $party);

        $spendable = $budget - ($transport ?? 0.0) - ($meals ?? 0.0);

        $included = [];
        $excluded = [];
        $spent    = 0.0;

        foreach ($priced as $item) {
            if ($spent + $item['fee_party'] <= $spendable) {
                $included[] = $item;
                $spent     += $item['fee_party'];
            } else {
                $excluded[] = $item;
            }
        }

        $total = round($spent + ($transport ?? 0.0) + ($meals ?? 0.0), 2);

        $notes = [];

        if ($transport === null) {
            $notes[] = 'Transport cost is not published in the Tourism Office records, so it is not included in this total.';
        }
        if ($meals === null) {
            $notes[] = 'Meal cost is not published in the Tourism Office records, so it is not included in this total.';
        }
        if ($unknown !== []) {
            $notes[] = count($unknown) . ' destination(s) have no readable entrance fee on record and were left out of the arithmetic.';
        }

        return [
            'budget'         => round($budget, 2),
            'party'          => $party,
            'included'       => $included,
            'excluded'       => $excluded,
            'unknown'        => $unknown,
            'entrance_total' => round($spent, 2),
            'transport'      => $transport,
            'meals'          => $meals,
            'total'          => $total,
            'remaining'      => round($budget - $total, 2),
            'notes'          => $notes,
        ];
    }

    /** An allowance the Office has published, per person, or null. */
    private static function allowance(string $key, int $party): ?float
    {
        $raw = trim((string) setting($key, ''));

        if ($raw === '' || !is_numeric($raw) || (float) $raw <= 0) {
            return null;
        }

        return round((float) $raw * $party, 2);
    }

    // -------------------------------------------------------------------------
    // Handing the figures to Gemini
    // -------------------------------------------------------------------------

    /**
     * The plan rendered as context.
     *
     * Every number is already final. The wording says so, because the surest
     * way to stop a model recomputing a total is to tell it the total is not
     * its to compute.
     */
    public static function toContext(array $plan): string
    {
        $peso = static fn(float $v): string => '₱' . number_format($v, 2);

        $lines = [
            'BUDGET PLAN — every figure below was calculated by the system. Use them exactly; do not recompute or adjust.',
            'Visitor budget: ' . $peso($plan['budget']) . ' for ' . $plan['party'] . ' person(s).',
            '',
            'AFFORDABLE DESTINATIONS (entrance fee for the whole party):',
        ];

        if ($plan['included'] === []) {
            $lines[] = '  none — the budget does not cover any recorded entrance fee.';
        } else {
            foreach ($plan['included'] as $item) {
                $lines[] = sprintf(
                    '  %s — %s%s%s',
                    $item['name'],
                    $peso($item['fee_party']),
                    $item['category'] !== '' ? ' · ' . $item['category'] : '',
                    $item['hours'] !== '' ? ' · open ' . $item['hours'] : ''
                );
            }
        }

        if ($plan['excluded'] !== []) {
            $lines[] = '';
            $lines[] = 'BEYOND THIS BUDGET:';
            foreach ($plan['excluded'] as $item) {
                $lines[] = '  ' . $item['name'] . ' — ' . $peso($item['fee_party']);
            }
        }

        $lines[] = '';
        $lines[] = 'TOTALS (final):';
        $lines[] = '  Entrance fees: ' . $peso($plan['entrance_total']);

        if ($plan['transport'] !== null) {
            $lines[] = '  Transport allowance published by the Tourism Office: ' . $peso($plan['transport']);
        }
        if ($plan['meals'] !== null) {
            $lines[] = '  Meal allowance published by the Tourism Office: ' . $peso($plan['meals']);
        }

        $lines[] = '  Estimated total: ' . $peso($plan['total']);
        $lines[] = '  Remaining budget: ' . $peso($plan['remaining']);

        if ($plan['notes'] !== []) {
            $lines[] = '';
            $lines[] = 'STATE THESE LIMITS PLAINLY IN YOUR REPLY:';
            foreach ($plan['notes'] as $note) {
                $lines[] = '  - ' . $note;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The same plan as structured rows for the chat bubble.
     *
     * Shown whether or not Gemini answers: the arithmetic is the system's own
     * work and does not depend on a third party being reachable.
     */
    public static function toFacts(array $plan): array
    {
        $facts = [];

        foreach ($plan['included'] as $item) {
            $facts[] = [$item['name'], '₱' . number_format($item['fee_party'], 2)];
        }

        if ($plan['transport'] !== null) {
            $facts[] = ['Transport (office estimate)', '₱' . number_format($plan['transport'], 2)];
        }
        if ($plan['meals'] !== null) {
            $facts[] = ['Meals (office estimate)', '₱' . number_format($plan['meals'], 2)];
        }

        $facts[] = ['Estimated total', '₱' . number_format($plan['total'], 2)];
        $facts[] = ['Remaining budget', '₱' . number_format($plan['remaining'], 2)];

        return $facts;
    }
}
