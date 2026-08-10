<?php
declare(strict_types=1);

namespace App\Core\Sms;

/**
 * Semaphore (semaphore.co) — a Philippine SMS provider.
 *
 * Chosen over Twilio for this system because it is domestic: local sender IDs,
 * pricing in pesos, and delivery to Philippine networks without international
 * routing. An LGU can also settle its account locally, which matters more for
 * procurement than any API difference.
 *
 * Swap it by writing another class against SmsDriver — nothing else changes.
 */
final class SemaphoreDriver implements SmsDriver
{
    private const ENDPOINT = 'https://api.semaphore.co/api/v4/messages';
    private const TIMEOUT  = 15;

    private string $apiKey;
    private string $senderId;

    public function __construct(string $apiKey, string $senderId = '')
    {
        $this->apiKey   = $apiKey;
        $this->senderId = $senderId;
    }

    public function send(string $number, string $message): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'reference' => null, 'error' => 'No SMS API key is configured.'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'reference' => null, 'error' => 'The cURL extension is not available on this server.'];
        }

        $payload = [
            'apikey'  => $this->apiKey,
            'number'  => $number,
            'message' => $message,
        ];

        if ($this->senderId !== '') {
            $payload['sendername'] = $this->senderId;
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        // A network failure is an ordinary outcome for the queue to retry,
        // not an exception for a page to crash on.
        if ($body === false) {
            return ['ok' => false, 'reference' => null, 'error' => 'Network error: ' . $error];
        }

        $decoded = json_decode((string) $body, true);

        if ($status < 200 || $status >= 300) {
            return [
                'ok'        => false,
                'reference' => null,
                'error'     => 'Provider returned HTTP ' . $status . ': ' . mb_substr((string) $body, 0, 180),
            ];
        }

        // Semaphore answers with an array of message objects.
        $first = is_array($decoded) ? ($decoded[0] ?? $decoded) : null;

        return [
            'ok'        => true,
            'reference' => isset($first['message_id']) ? (string) $first['message_id'] : null,
            'error'     => null,
        ];
    }

    public function name(): string
    {
        return 'semaphore';
    }

    public function describe(): string
    {
        return 'Live — messages are sent through Semaphore and consume account credits.';
    }
}
