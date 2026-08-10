<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Weather for Tampakan, from Open-Meteo.
 *
 * Chosen over OpenWeatherMap because it needs no API key: nothing to register,
 * nothing to expire, nothing to leak, and no free-tier quota that silently
 * stops a municipal website working after a busy month.
 *
 * Three rules govern how it is used, because a tourism page must never depend
 * on somebody else's server being awake:
 *
 *   1. Fetched on the server and cached to a file, so one request per hour
 *      serves every visitor rather than each of them calling out.
 *   2. A short timeout. If the API is slow, the page renders without weather
 *      rather than making the visitor wait for it.
 *   3. Stale cache beats no weather. If a refresh fails, yesterday's reading
 *      is shown with its age stated, which is more useful than an empty box.
 */
final class Weather
{
    private const ENDPOINT = 'https://api.open-meteo.com/v1/forecast';
    private const CACHE_MINUTES = 60;

    /** Total time allowed for a call, and for the connection alone. */
    private const TIMEOUT = 6;
    private const CONNECT_TIMEOUT = 4;

    /**
     * How long to stop calling out after a failure.
     *
     * Without this, an outage costs every visitor the full timeout on every
     * page load: cache stale, fetch attempted, fetch times out, repeat. The
     * whole site would crawl because a weather panel was unreachable. After a
     * failure the service waits before trying again and serves what it has.
     */
    private const RETRY_COOLDOWN_MINUTES = 10;

    /** Beyond this, a stale reading is too old to show at all. */
    private const MAX_STALE_HOURS = 24;

    /** WMO weather codes, mapped to something a visitor can act on. */
    private const CODES = [
        0  => ['Clear sky',            'fa-sun',                'clear'],
        1  => ['Mainly clear',         'fa-sun',                'clear'],
        2  => ['Partly cloudy',        'fa-cloud-sun',          'fair'],
        3  => ['Overcast',             'fa-cloud',              'fair'],
        45 => ['Fog',                  'fa-smog',               'caution'],
        48 => ['Freezing fog',         'fa-smog',               'caution'],
        51 => ['Light drizzle',        'fa-cloud-rain',         'wet'],
        53 => ['Drizzle',              'fa-cloud-rain',         'wet'],
        55 => ['Heavy drizzle',        'fa-cloud-rain',         'wet'],
        61 => ['Light rain',           'fa-cloud-showers-heavy','wet'],
        63 => ['Rain',                 'fa-cloud-showers-heavy','wet'],
        65 => ['Heavy rain',           'fa-cloud-showers-water','severe'],
        80 => ['Light showers',        'fa-cloud-sun-rain',     'wet'],
        81 => ['Showers',              'fa-cloud-showers-heavy','wet'],
        82 => ['Violent showers',      'fa-cloud-showers-water','severe'],
        95 => ['Thunderstorm',         'fa-cloud-bolt',         'severe'],
        96 => ['Thunderstorm, hail',   'fa-cloud-bolt',         'severe'],
        99 => ['Severe thunderstorm',  'fa-cloud-bolt',         'severe'],
    ];

    /**
     * Current conditions and a five-day outlook.
     *
     * @return array|null null when no reading is available at all
     */
    public static function forecast(?float $lat = null, ?float $lng = null): ?array
    {
        $lat = $lat ?? 6.4333;   // Tampakan municipal hall
        $lng = $lng ?? 124.9167;

        $cacheFile = self::cachePath($lat, $lng);
        $cached    = self::readCache($cacheFile);
        $now       = time();

        // Fresh enough — serve it and make no network call at all.
        // fetched_at is defaulted because a cache file may record only a
        // failed attempt, with no reading in it at all.
        if ($cached !== null
            && isset($cached['current'])
            && ($now - ($cached['fetched_at'] ?? 0)) < self::CACHE_MINUTES * 60) {
            return self::shape($cached, false);
        }

        // A recent failure means the service is having trouble. Serve what we
        // have rather than making this visitor wait for another timeout.
        $lastAttempt = $cached['last_attempt'] ?? 0;
        if (($now - $lastAttempt) < self::RETRY_COOLDOWN_MINUTES * 60) {
            return self::usable($cached);
        }

        $fresh = self::fetch($lat, $lng);

        if ($fresh !== null) {
            $fresh['fetched_at']   = $now;
            $fresh['last_attempt'] = $now;
            @file_put_contents($cacheFile, json_encode($fresh), LOCK_EX);
            return self::shape($fresh, false);
        }

        // Record the failed attempt so the next visitor is not made to wait
        // through the same timeout, then fall back to whatever we hold.
        if ($cached !== null) {
            $cached['last_attempt'] = $now;
            @file_put_contents($cacheFile, json_encode($cached), LOCK_EX);
        } else {
            // No reading has ever succeeded here. Remember the attempt anyway,
            // otherwise an unreachable API makes every page load slow.
            @file_put_contents($cacheFile, json_encode(['last_attempt' => $now]), LOCK_EX);
        }

        return self::usable($cached);
    }

    /**
     * A stale reading, if it is still recent enough to be worth showing.
     *
     * Yesterday's weather helps somebody deciding whether to travel. A reading
     * from last week does not, and presenting it as current would be worse
     * than showing nothing.
     */
    private static function usable(?array $cached): ?array
    {
        if ($cached === null || !isset($cached['current'], $cached['fetched_at'])) {
            return null;
        }

        if ((time() - $cached['fetched_at']) > self::MAX_STALE_HOURS * 3600) {
            return null;
        }

        return self::shape($cached, true);
    }

    private static function fetch(float $lat, float $lng): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'latitude'  => $lat,
            'longitude' => $lng,
            'current'   => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m',
            'daily'     => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'timezone'  => 'Asia/Manila',
            'forecast_days' => 5,
        ]);

        $body = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($result !== false && $status === 200) {
                $body = $result;
            }
        } else {
            // Fallback for hosts without cURL. allow_url_fopen may also be off,
            // in which case this returns false and the cache carries the page.
            $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT]]);
            $result = @file_get_contents($url, false, $context);
            if ($result !== false) {
                $body = $result;
            }
        }

        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        return isset($data['current'], $data['daily']) ? $data : null;
    }

    /** Turns the API response into exactly what the template needs. */
    private static function shape(array $raw, bool $stale): array
    {
        $current = $raw['current'] ?? [];
        $daily   = $raw['daily'] ?? [];

        $code = (int) ($current['weather_code'] ?? 0);
        [$label, $icon, $tone] = self::describe($code);

        $days = [];
        $count = min(5, count($daily['time'] ?? []));

        for ($i = 0; $i < $count; $i++) {
            [$dLabel, $dIcon, $dTone] = self::describe((int) $daily['weather_code'][$i]);
            $timestamp = strtotime($daily['time'][$i]);

            $days[] = [
                'date'      => $daily['time'][$i],
                'day'       => $i === 0 ? 'Today' : date('D', $timestamp),
                'full_day'  => date('l, F j', $timestamp),
                'high'      => (int) round((float) $daily['temperature_2m_max'][$i]),
                'low'       => (int) round((float) $daily['temperature_2m_min'][$i]),
                'rain'      => (int) ($daily['precipitation_probability_max'][$i] ?? 0),
                'label'     => $dLabel,
                'icon'      => $dIcon,
                'tone'      => $dTone,
            ];
        }

        return [
            'temperature' => (int) round((float) ($current['temperature_2m'] ?? 0)),
            'feels_like'  => (int) round((float) ($current['apparent_temperature'] ?? 0)),
            'humidity'    => (int) ($current['relative_humidity_2m'] ?? 0),
            'wind'        => round((float) ($current['wind_speed_10m'] ?? 0), 1),
            'label'       => $label,
            'icon'        => $icon,
            'tone'        => $tone,
            'days'        => $days,
            'advice'      => self::advice($code, $days[0]['rain'] ?? 0),
            'updated'     => date('g:i A', $raw['fetched_at'] ?? time()),
            'stale'       => $stale,
            'age_hours'   => (int) floor((time() - ($raw['fetched_at'] ?? time())) / 3600),
        ];
    }

    private static function describe(int $code): array
    {
        return self::CODES[$code] ?? ['Unsettled', 'fa-cloud', 'fair'];
    }

    /**
     * A sentence a visitor can act on.
     *
     * The point of putting weather on a tourism page is not the number — it is
     * whether to bring a jacket, or postpone the trek.
     */
    private static function advice(int $code, int $rainChance): string
    {
        if (in_array($code, [95, 96, 99], true)) {
            return 'Thunderstorms are expected. Mountain trails and river crossings are unsafe today — please postpone treks and check with the Tourism Office.';
        }

        if (in_array($code, [65, 82], true)) {
            return 'Heavy rain is expected. Trails will be slippery and streams may rise quickly. Consider rescheduling any hiking.';
        }

        if ($rainChance >= 70) {
            return 'Rain is very likely. Bring a rain jacket, pack your phone in something waterproof, and expect slippery trails.';
        }

        if (in_array($code, [45, 48], true)) {
            return 'Fog is expected. Viewpoints may be closed in, and driving on the upland roads needs extra care.';
        }

        if ($rainChance >= 40) {
            return 'Showers are possible. A light rain jacket is worth carrying.';
        }

        if (in_array($code, [0, 1], true)) {
            return 'Clear conditions — a good day for viewpoints and highland trails. Bring water and sun protection.';
        }

        return 'Generally fair. Mornings in the highlands are cool, so bring a light jacket if you are heading up early.';
    }

    private static function cachePath(float $lat, float $lng): string
    {
        $dir = dirname(APP_PATH) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'weather-' . md5($lat . ',' . $lng) . '.json';
    }

    private static function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        // A file holding only last_attempt is a recorded failure, not a
        // reading — still returned, so the cooldown above can see it.
        return is_array($data) ? $data : null;
    }
}
