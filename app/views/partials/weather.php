<?php
/**
 * Weather panel, shared by the homepage and every destination page.
 *
 * Expects $weather from Weather::forecast(). Renders nothing at all when the
 * reading is unavailable — an empty weather box on a tourism site looks
 * broken, and a visitor is better served by the section simply not being
 * there.
 *
 * Set $weatherPlace before including to name the location.
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

if (empty($weather)) {
    return;
}

$place = $weatherPlace ?? 'Tampakan';
?>
<div class="wx wx--<?= e($weather['tone']) ?>">

    <div class="wx__now">
        <div class="wx__icon"><i class="fa-solid <?= e($weather['icon']) ?>"></i></div>

        <div class="wx__reading">
            <p class="wx__temp"><?= (int) $weather['temperature'] ?><span>&deg;C</span></p>
            <p class="wx__label"><?= e($weather['label']) ?></p>
            <p class="wx__place"><i class="fa-solid fa-location-dot"></i> <?= e($place) ?></p>
        </div>

        <dl class="wx__stats">
            <div>
                <dt>Feels like</dt>
                <dd><?= (int) $weather['feels_like'] ?>&deg;</dd>
            </div>
            <div>
                <dt>Humidity</dt>
                <dd><?= (int) $weather['humidity'] ?>%</dd>
            </div>
            <div>
                <dt>Wind</dt>
                <dd><?= e((string) $weather['wind']) ?> km/h</dd>
            </div>
        </dl>
    </div>

    <!-- The reason weather belongs on a tourism page: not the number, but
         whether to bring a jacket or postpone the trek. -->
    <p class="wx__advice">
        <i class="fa-solid fa-circle-info"></i>
        <?= e($weather['advice']) ?>
    </p>

    <?php if ($weather['days'] !== []): ?>
        <ol class="wx__days">
            <?php foreach ($weather['days'] as $day): ?>
                <li class="wx__day" title="<?= e($day['full_day']) ?> &mdash; <?= e($day['label']) ?>">
                    <span class="wx__day-name"><?= e($day['day']) ?></span>
                    <i class="fa-solid <?= e($day['icon']) ?> wx__day-icon wx__day-icon--<?= e($day['tone']) ?>"></i>
                    <span class="wx__day-temp"><?= (int) $day['high'] ?>&deg;<small><?= (int) $day['low'] ?>&deg;</small></span>
                    <span class="wx__day-rain <?= $day['rain'] >= 70 ? 'is-wet' : '' ?>">
                        <i class="fa-solid fa-droplet"></i><?= (int) $day['rain'] ?>%
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <p class="wx__foot">
        <?php if ($weather['stale']): ?>
            <i class="fa-solid fa-triangle-exclamation"></i>
            Last reading <?= $weather['age_hours'] < 1 ? 'under an hour' : $weather['age_hours'] . ' hour(s)' ?> old
            &mdash; the forecast service could not be reached.
        <?php else: ?>
            Updated <?= e($weather['updated']) ?> &middot; source: Open-Meteo
        <?php endif; ?>
    </p>
</div>
