<?php
declare(strict_types=1);

namespace App\Core\Sms;

/**
 * PhilSMS (philsms.com) — a Philippine SMS provider.
 *
 * Domestic like Semaphore: peso pricing, local sender IDs, no international
 * routing to Philippine networks. Which of the two an office uses is a
 * procurement question, not a technical one, so both live behind SmsDriver and
 * the choice is a line in config.
 *
 * API SHAPE (v3)
 *
 *   POST https://dashboard.philsms.com/api/v3/sms/send
 *   Authorization: Bearer <token>
 *   Content-Type: application/json
 *   { "recipient": "+639171234567", "sender_id": "...", "type": "plain",
 *     "message": "..." }
 *
 * THE SENDER ID IS THE THING THAT CATCHES PEOPLE OUT. PhilSMS only accepts a
 * sender ID that has been registered and approved on the account. An
 * unregistered one is rejected at send time with a message that does not
 * obviously say so, which reads as "SMS is broken" rather than "that name is
 * not approved yet" — so this class says it plainly when the provider refuses.
 */
final class PhilSmsDriver implements SmsDriver
{
    private const ENDPOINT = 'https://dashboard.philsms.com/api/v3/sms/send';
    private const TIMEOUT  = 20;

    public function __construct(
        private string $apiKey,
        private string $senderId = ''
    ) {
    }

    /**
     * @return array{ok: bool, reference: ?string, error: ?string}
     */
    public function send(string $number, string $message): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'reference' => null, 'error' => 'No PhilSMS API token is configured.'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'reference' => null, 'error' => 'The cURL extension is not available on this server.'];
        }

        $payload = [
            'recipient' => $number,
            'message'   => $message,
            'type'      => 'plain',
        ];

        /* Omitted rather than sent blank. PhilSMS falls back to the account
           default when the field is absent; an empty string is an invalid
           sender ID and is rejected. */
        if ($this->senderId !== '') {
            $payload['sender_id'] = $this->senderId;
        }

        $ch = curl_init(self::ENDPOINT);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                /* The token travels in a header, never in the URL — a query
                   string ends up in access logs and in any proxy in between. */
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body     = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            /* A network failure is expected, not exceptional — the queue records
               it and tries again. Never thrown. */
            return ['ok' => false, 'reference' => null, 'error' => 'PhilSMS unreachable: ' . $curlErr];
        }

        $json = json_decode((string) $body, true);

        if (!is_array($json)) {
            return ['ok' => false, 'reference' => null,
                    'error' => 'PhilSMS returned an unreadable response (HTTP ' . $status . ').'];
        }

        if ($status >= 200 && $status < 300 && ($json['status'] ?? '') === 'success') {
            $data = $json['data'] ?? [];

            return [
                'ok'        => true,
                'reference' => isset($data['uid']) ? (string) $data['uid'] : (isset($data['id']) ? (string) $data['id'] : null),
                'error'     => null,
            ];
        }

        return ['ok' => false, 'reference' => null, 'error' => $this->explain($json, $status)];
    }

    /**
     * Turns a provider error into something an officer can act on.
     *
     * The raw messages are terse and the two that actually happen — an
     * unapproved sender ID and an exhausted balance — read like generic
     * failures. Saying which one it is turns "SMS is broken" into a task.
     *
     * @param array<string, mixed> $json
     */
    private function explain(array $json, int $status): string
    {
        $raw = (string) ($json['message'] ?? $json['error'] ?? '');

        /* Validation errors arrive as a field => [messages] map. */
        if (isset($json['errors']) && is_array($json['errors'])) {
            $parts = [];

            foreach ($json['errors'] as $field => $messages) {
                $parts[] = $field . ': ' . (is_array($messages) ? implode(' ', $messages) : (string) $messages);
            }

            if ($parts !== []) {
                $raw = implode(' | ', $parts);
            }
        }

        $lower = mb_strtolower($raw);

        if ($status === 401 || $status === 403 || str_contains($lower, 'unauthenticated') || str_contains($lower, 'unauthorized')) {
            return 'PhilSMS rejected the API token. Check it in Settings, and that it has not been revoked.';
        }

        if (str_contains($lower, 'sender')) {
            return 'PhilSMS rejected the sender ID "' . $this->senderId . '". It must be registered and '
                . 'approved on the PhilSMS account before it can be used. (' . $raw . ')';
        }

        if (str_contains($lower, 'balance') || str_contains($lower, 'credit') || str_contains($lower, 'insufficient')) {
            return 'The PhilSMS account has no credit left. Top it up to resume sending. (' . $raw . ')';
        }

        if (str_contains($lower, 'recipient') || str_contains($lower, 'number')) {
            return 'PhilSMS rejected the recipient number. (' . $raw . ')';
        }

        return $raw !== ''
            ? 'PhilSMS: ' . $raw
            : 'PhilSMS refused the message (HTTP ' . $status . ').';
    }

    public function name(): string
    {
        return 'philsms';
    }

    public function describe(): string
    {
        return 'PhilSMS'
            . ($this->senderId !== '' ? ' — sender ID "' . $this->senderId . '"' : ' — account default sender ID');
    }
}
