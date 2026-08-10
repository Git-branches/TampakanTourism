<?php
declare(strict_types=1);

/**
 * Input handling shared by the announcement create and edit pages.
 */

use App\Core\Validator;
use App\Repositories\AnnouncementRepository;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

function validate_announcement(Validator $v): void
{
    $v->require('title', 'body', 'type', 'audience', 'status')
      ->length('title', 4, 200)
      ->length('summary', 0, 300)
      ->in('type', array_keys(AnnouncementRepository::TYPES))
      ->in('audience', array_keys(AnnouncementRepository::AUDIENCES))
      ->in('status', ['draft', 'published', 'archived']);

    // An expiry before the publish time would hide the notice the moment it
    // appeared — a mistake that is invisible until somebody asks why nothing
    // was posted.
    $publish = (string) $v->value('publish_at', '');
    $expires = (string) $v->value('expires_at', '');

    if ($expires !== '' && $publish !== '' && strtotime($expires) <= strtotime($publish)) {
        $v->addError('expires_at', 'The expiry must come after the publish time.');
    }

    if ($expires !== '' && $publish === '' && strtotime($expires) <= time()) {
        $v->addError('expires_at', 'That expiry has already passed, so the announcement would never appear.');
    }
}

function collect_announcement_input(Validator $v): array
{
    $toDateTime = static function (string $value): ?string {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    };

    return [
        'title'          => (string) $v->value('title'),
        'body'           => (string) $v->value('body'),
        'summary'        => (string) $v->value('summary', ''),
        'type'           => (string) $v->value('type'),
        'audience'       => (string) $v->value('audience'),
        'status'         => (string) $v->value('status'),
        'destination_id' => (int) $v->value('destination_id', 0),
        'event_date'     => (string) $v->value('event_date', '') ?: null,
        'event_location' => (string) $v->value('event_location', ''),
        'publish_at'     => $toDateTime((string) $v->value('publish_at', '')),
        'expires_at'     => $toDateTime((string) $v->value('expires_at', '')),
    ];
}
