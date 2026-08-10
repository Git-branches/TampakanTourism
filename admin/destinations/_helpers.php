<?php
declare(strict_types=1);

/**
 * Input handling shared by create.php and edit.php.
 *
 * Both pages accept exactly the same fields, so the collection and the
 * coordinate rule live here rather than being written twice and drifting.
 */

use App\Core\Validator;

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

/**
 * Coordinates are optional, but if one is supplied both must be, and both
 * must fall inside the Philippines.
 *
 * The bounds check catches a transposed pair — entering longitude in the
 * latitude box silently places the destination in the Pacific, and nobody
 * notices until someone opens the public map.
 */
function validate_coordinates(Validator $v): void
{
    $lat = $v->value('latitude', '');
    $lng = $v->value('longitude', '');

    if ($lat === '' && $lng === '') {
        return;                     // both blank is valid — coordinates are optional
    }

    if ($lat === '' || $lng === '') {
        $v->addError($lat === '' ? 'latitude' : 'longitude',
            'Enter both latitude and longitude, or leave both blank.');
        return;
    }

    if (!is_numeric($lat) || (float) $lat < 4.0 || (float) $lat > 21.5) {
        $v->addError('latitude', 'Latitude must be between 4 and 21.5 — that is the range covering the Philippines.');
    }

    if (!is_numeric($lng) || (float) $lng < 116.0 || (float) $lng > 127.0) {
        $v->addError('longitude', 'Longitude must be between 116 and 127 — that is the range covering the Philippines.');
    }
}

/** Pulls every destination field out of the request in one place. */
function collect_destination_input(Validator $v): array
{
    return [
        'category_id'       => (int) $v->value('category_id', 0),
        'name'              => (string) $v->value('name', ''),
        'short_description' => (string) $v->value('short_description', ''),
        'description'       => (string) $v->value('description', ''),
        'history'           => (string) $v->value('history', ''),
        'operating_hours'   => (string) $v->value('operating_hours', ''),
        'entrance_fee'      => (string) $v->value('entrance_fee', ''),
        'facilities'        => (string) $v->value('facilities', ''),
        'reminders'         => (string) $v->value('reminders', ''),
        'barangay'          => (string) $v->value('barangay', ''),
        'address'           => (string) $v->value('address', ''),
        'latitude'          => (string) $v->value('latitude', ''),
        'longitude'         => (string) $v->value('longitude', ''),
        'contact_person'    => (string) $v->value('contact_person', ''),
        'contact_phone'     => (string) $v->value('contact_phone', ''),
        'contact_email'     => (string) $v->value('contact_email', ''),
        'is_featured'       => !empty($_POST['is_featured']),
    ];
}
