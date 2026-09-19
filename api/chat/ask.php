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
   Two windows, because they stop different things. Thirty a minute is far
   beyond anyone typing and far below what a script needs to be a nuisance; the
   hourly ceiling is what stops a slow, patient script from sitting on the
   endpoint all afternoon — each question costs the office a Gemini call. */
$ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$burst  = 'chat:' . $ip;
$hourly = 'chat-hour:' . $ip;

if (!RateLimiter::allow($burst, 30, 60) || !RateLimiter::allow($hourly, 200, 3600)) {
    error_log('Chatbot rate limit hit by ' . $ip);

    http_response_code(429);
    header('Retry-After: ' . max(1, RateLimiter::retryAfter($burst, 60)));
    echo json_encode([
        'ok'    => false,
        'error' => 'That is a lot of questions at once. Give it a moment and try again.',
    ]);
    exit;
}

/* ---- 4. Answer ---------------------------------------------------------- */
$question = (string) ($_POST['q'] ?? '');

/* A QUESTION HAS A LENGTH. Nothing a visitor types is longer than a sentence or
   two; a megabyte of text in this field is somebody feeding the model a payload
   at the office's expense. Refused rather than truncated, so the visitor is told
   what happened instead of being answered about half a question. */
if (mb_strlen($question) > 500) {
    error_log('Chatbot oversized question (' . mb_strlen($question) . ' chars) from ' . $ip);

    http_response_code(413);
    echo json_encode([
        'ok'    => false,
        'error' => 'That question is too long. Please shorten it to about 500 characters.',
    ]);
    exit;
}

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
