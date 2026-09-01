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

/**
 * Attaches the card picture, after the announcement itself has been saved.
 *
 * Deliberately separate from collect_announcement_input(): an upload can fail
 * on its own — a file above post_max_size, a format GD will not decode — and it
 * must not take an edit to the words down with it. The words are already saved
 * by the time this runs, so the worst case is a saved announcement with a
 * message about the picture.
 *
 * Stored through Uploader, which re-encodes through GD: anything smuggled into
 * an image's metadata does not survive, and the file is named randomly rather
 * than from whatever the browser sent.
 */
function store_announcement_banner(int $id): void
{
    $sent = ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($sent) {
        $uploader = new \App\Core\Uploader();
        $stored   = $uploader->store($_FILES['banner'], 'banners');

        if ($stored === null) {
            \App\Core\Session::flash('warning',
                'The announcement was saved, but the picture was not: '
                . ($uploader->firstError() ?? 'it could not be read as an image.'));

            return;
        }

        \App\Repositories\AnnouncementRepository::setBanner($id, $stored);

        return;
    }

    /* Only when nothing new was sent — otherwise ticking "remove" and choosing
       a replacement in the same save would throw the replacement away. */
    if (!empty($_POST['remove_banner'])) {
        \App\Repositories\AnnouncementRepository::clearBanner($id);
    }
}
