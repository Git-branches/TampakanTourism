<?php
declare(strict_types=1);

/**
 * =============================================================================
 *  TourSync — visitor assistant endpoint                            Feature 4
 * -----------------------------------------------------------------------------
 *  Public and unauthenticated, like the logbook, and for the same reason: a
 *  tourist asking what time a waterfall opens will not sign in first.
 *
 *  It is also cheap to serve — every answer is composed from records already in
 *  memory — so the guards below are about keeping the server honest rather than
 *  about cost. The rate limit exists so a script cannot turn a public endpoint
 *  into a database load generator; the CSRF check keeps it usable only from our
 *  own pages; and the length cap stops a caller pasting a novel into it.
 *
 *  Nothing from the question is stored. There is no transcript table, no log of
 *  what visitors asked, and no analytics — a municipal site does not need a
 *  record of a tourist's questions, and not keeping one is the simplest way to
 *  honour that.
 * =============================================================================
 */

require_once __DIR__ . '/../../bootstrap.php';

use App\Core\Chatbot;
use App\Core\Csrf;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

/* ---- 1. Method ---------------------------------------------------------- */
if (!is_post()) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Send this as a POST request.']);
    exit;
}

/* ---- 2. CSRF ------------------------------------------------------------
   The widget collects a token from api/arrivals/token.php, the same endpoint
   the offline logbook queue uses. One token source, one thing to reason about. */
if (!Csrf::check($_POST['_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session expired. Please reload the page.']);
    exit;
}

/* ---- 3. Rate limit ------------------------------------------------------
   Thirty questions per minute is far beyond anyone typing, and far below what
   a script would need to be a nuisance. */
$key = 'chat:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if (!RateLimiter::allow($key, 30, 60)) {
    http_response_code(429);
    echo json_encode([
        'ok'    => false,
        'error' => 'That is a lot of questions at once. Give it a moment and try again.',
    ]);
    exit;
}

/* ---- 4. Answer ---------------------------------------------------------- */
$question = (string) ($_POST['q'] ?? '');

if (trim($question) === '') {
    echo json_encode([
        'ok'          => true,
        'reply'       => 'Ask me anything about visiting Tampakan.',
        'facts'       => [],
        'links'       => [],
        'suggestions' => Chatbot::topSuggestions(),
        'answered'    => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $answer = Chatbot::ask($question);
} catch (Throwable $e) {
    error_log('Chatbot failed: ' . $e->getMessage());

    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'The assistant is unavailable just now. The Tourism Office contact details are in the footer.',
    ]);
    exit;
}

echo json_encode(['ok' => true] + $answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
