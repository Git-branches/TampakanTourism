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

/**
 * An event's photographs: removals, a new featured choice, and new uploads —
 * in that order, after the event itself has been saved.
 *
 * The order is the point. Removals first, so a photo ticked for removal cannot
 * also be made featured; the featured choice next, so "remove the current lead
 * and lead with this one" does what it says; uploads last, so the first new
 * photo only becomes featured when nothing already is.
 *
 * Every file goes through Uploader::store(): its type is read from its bytes,
 * it is re-encoded through GD, and it is saved under a random name — the name
 * the browser sent is never used. Identical files in one batch are stored once.
 * A failure is reported against the file it belongs to and never takes the
 * saved event, or the other photographs, down with it.
 */
function store_event_photos(int $id): void
{
    $repo     = \App\Repositories\AnnouncementRepository::class;
    $problems = [];

    /* The single card-picture field events had before galleries. Still
       honoured — a form or a script that sends it gets what it always got, the
       featured picture replaced — and then the gallery steps run as normal. */
    if (($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        store_announcement_banner($id);
        unset($_POST['remove_banner']);
    }

    // ---- 1. removals -------------------------------------------------------
    $remove = array_map('intval', (array) ($_POST['remove_photos'] ?? []));
    $repo::removePhotos($id, $remove);

    // ---- 2. the featured picture -------------------------------------------
    $featured      = (int) ($_POST['featured_photo'] ?? 0);
    $removeCurrent = !empty($_POST['remove_banner']);

    if ($featured > 0 && !in_array($featured, $remove, true) && $repo::makeFeatured($id, $featured)) {
        /* The swap put the previous lead into that photo's row. If the officer
           also asked for the previous lead to go, that row is what goes. */
        if ($removeCurrent) {
            $repo::removePhotos($id, [$featured]);
        }
    } elseif ($removeCurrent) {
        $repo::removeFeatured($id);
    }

    // ---- 3. new photographs ------------------------------------------------
    $files = $_FILES['photos'] ?? null;
    $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;

    if ($count > 0) {
        $room     = max(0, $repo::MAX_PHOTOS - $repo::photoCount($id));
        $uploader = new \App\Core\Uploader();
        $stored   = [];
        $seen     = [];
        $skipped  = 0;

        for ($i = 0; $i < $count; $i++) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $name = (string) ($files['name'][$i] ?? 'photo');

            if (count($stored) >= $room) {
                $skipped++;
                continue;
            }

            /* The same file chosen twice — easy to do from a phone gallery —
               is stored once rather than appearing twice in the gallery. */
            $tmp  = (string) ($files['tmp_name'][$i] ?? '');
            $hash = $error === UPLOAD_ERR_OK && is_uploaded_file($tmp) ? (string) sha1_file($tmp) : '';

            if ($hash !== '' && isset($seen[$hash])) {
                $problems[] = $name . ': the same picture was chosen twice, so it was added once.';
                continue;
            }

            $path = $uploader->store([
                'name'     => $name,
                'type'     => $files['type'][$i] ?? '',
                'tmp_name' => $tmp,
                'error'    => $error,
                'size'     => $files['size'][$i] ?? 0,
            ], 'banners');

            if ($path === null) {
                $problems[] = $name . ': ' . ($uploader->firstError() ?? 'it could not be read as an image.');
                continue;
            }

            if ($hash !== '') {
                $seen[$hash] = true;
            }

            $stored[] = $path;
        }

        $repo::addPhotos($id, $stored);

        if ($skipped > 0) {
            $problems[] = $skipped . ' photo(s) were not added: an event holds at most '
                . $repo::MAX_PHOTOS . ' photographs. Remove some to make room.';
        }

        /* PHP stops reading files at max_file_uploads and says nothing, so the
           office would simply find fewer photos than they chose. */
        $limit = (int) ini_get('max_file_uploads');

        if ($limit > 0 && $count >= $limit) {
            $problems[] = 'At most ' . $limit . ' photos can be sent in one save. If you chose more, '
                . 'the rest were not received — open the event again and add them.';
        }
    }

    if ($problems !== []) {
        \App\Core\Session::flash('warning', 'The event was saved, but not every photo: ' . implode(' ', $problems));
    }
}

/**
 * The picture step for either kind of record: the gallery for an event, the
 * single card picture for everything else.
 */
function store_announcement_media(int $id, string $type): void
{
    if (\App\Repositories\AnnouncementRepository::isEventType($type)) {
        store_event_photos($id);
    } else {
        store_announcement_banner($id);
    }
}
