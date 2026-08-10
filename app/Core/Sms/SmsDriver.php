<?php
declare(strict_types=1);

namespace App\Core\Sms;

/**
 * Contract every SMS driver implements.
 *
 * The interface exists so the provider is a configuration choice rather than a
 * rewrite. Philippine SMS providers change pricing and terms often, and a
 * capstone system that hard-codes one is a system its office cannot maintain.
 */
interface SmsDriver
{
    /**
     * Sends one message.
     *
     * Implementations must never throw for an ordinary delivery failure — a
     * provider being down is expected, not exceptional, and the queue needs a
     * result it can record and retry.
     *
     * @param string $number  Recipient in E.164, e.g. +639171234567
     * @param string $message Plain text, already truncated for the provider
     *
     * @return array{ok: bool, reference: ?string, error: ?string}
     */
    public function send(string $number, string $message): array;

    /** Short name recorded against each notification, e.g. 'log', 'semaphore'. */
    public function name(): string;

    /** Human-readable note shown in the admin so the officer knows what is live. */
    public function describe(): string;
}
