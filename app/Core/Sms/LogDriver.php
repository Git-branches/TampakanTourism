<?php
declare(strict_types=1);

namespace App\Core\Sms;

/**
 * Writes messages to a file instead of sending them.
 *
 * This is the default, and that is deliberate. A capstone gets rehearsed
 * repeatedly — every practice run of the announcement feature would otherwise
 * spend real SMS credits and text real people who did not ask to be part of a
 * demonstration.
 *
 * Everything else behaves identically: the queue, the retry logic, and the
 * delivery board all see a normal successful send, so switching to a live
 * provider changes one line of configuration and nothing else.
 */
final class LogDriver implements SmsDriver
{
    public function send(string $number, string $message): array
    {
        $path = $this->logPath();

        $entry = sprintf(
            "[%s] TO %s (%d chars, %d segment%s)\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $number,
            mb_strlen($message),
            $segments = (int) ceil(mb_strlen($message) / 160),
            $segments === 1 ? '' : 's',
            $message,
            str_repeat('-', 66)
        );

        $written = @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            return [
                'ok'        => false,
                'reference' => null,
                'error'     => 'Could not write to the SMS log. Check that storage/logs is writable.',
            ];
        }

        return [
            'ok'        => true,
            'reference' => 'log-' . bin2hex(random_bytes(6)),
            'error'     => null,
        ];
    }

    public function name(): string
    {
        return 'log';
    }

    public function describe(): string
    {
        return 'Test mode — messages are written to storage/logs/sms.log and no real SMS is sent.';
    }

    public function logPath(): string
    {
        $dir = dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'sms.log';
    }

    /** Most recent entries, newest first, for the admin preview. */
    public function recent(int $limit = 20): array
    {
        $path = $this->logPath();

        if (!is_file($path)) {
            return [];
        }

        $blocks = preg_split('/^-{60,}$/m', (string) file_get_contents($path)) ?: [];
        $blocks = array_values(array_filter(array_map('trim', $blocks)));

        return array_slice(array_reverse($blocks), 0, $limit);
    }
}
