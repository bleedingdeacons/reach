<?php

declare(strict_types=1);

namespace Reach\Geocoding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Geocoder backed by the free postcodes.io API.
 *
 * Three resolution strategies are tried, in order, based on the *shape* of
 * the input rather than the actual API response — we don't make a request
 * we know will 404. The strategies are:
 *
 *   1. Full UK postcode (e.g. "BS1 4ST", "bs14st") — looked up via
 *      /postcodes/{postcode}, which returns the postcode centroid.
 *
 *   2. Outcode only (e.g. "BS3", "BS14") — looked up via
 *      /outcodes/{outcode}, which returns the centroid of the outcode
 *      district.
 *
 *   3. Anything else (e.g. "Bedminster", "Clifton") — passed to
 *      /places?q={...}; the first hit's coordinates are returned.
 *
 * Each successfully resolved lookup is cached in a WordPress transient
 * keyed by a normalised form of the input. Misses are cached for a much
 * shorter period to avoid hammering the API for a typo while still
 * letting the data heal once an admin fixes the bad area string.
 *
 * Network failures are logged and returned as null — never throw — so a
 * single bad postcode does not abort a search across hundreds of members.
 */
final class PostcodesIoGeocoder implements Geocoder
{
    use \Reach\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    private const API_BASE = 'https://api.postcodes.io';
    private const CACHE_PREFIX = 'reach_geo_';

    /** A resolved hit is stable for a week; postcode centroids effectively never move. */
    private const HIT_TTL = WEEK_IN_SECONDS;

    /** A miss is cached briefly so typos don't keep hitting the API, but heal quickly when fixed. */
    private const MISS_TTL = HOUR_IN_SECONDS;

    /** Sentinel stored in the transient to distinguish a cached miss from "not cached yet". */
    private const MISS_SENTINEL = '__reach_miss__';

    private const HTTP_TIMEOUT = 5;

    public function geocode(string $area): ?Coordinates
    {
        $area = trim($area);
        if ($area === '') {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . md5(strtolower($area));
        $cached = get_transient($cacheKey);

        if ($cached === self::MISS_SENTINEL) {
            return null;
        }

        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            try {
                return new Coordinates((float) $cached['lat'], (float) $cached['lng']);
            } catch (\Throwable $e) {
                // Corrupt cache entry — fall through to re-resolve.
                delete_transient($cacheKey);
            }
        }

        $coords = $this->resolve($area);

        if ($coords === null) {
            set_transient($cacheKey, self::MISS_SENTINEL, self::MISS_TTL);
            return null;
        }

        set_transient(
            $cacheKey,
            ['lat' => $coords->latitude, 'lng' => $coords->longitude],
            self::HIT_TTL
        );

        return $coords;
    }

    /**
     * Pick a resolution strategy by the shape of the input.
     */
    private function resolve(string $area): ?Coordinates
    {
        $normalised = strtoupper(preg_replace('/\s+/', '', $area) ?? '');

        if ($this->looksLikeFullPostcode($normalised)) {
            $coords = $this->lookupPostcode($normalised);
            if ($coords !== null) {
                return $coords;
            }
            // Some "full" inputs are actually a valid outcode glued to junk
            // (e.g. "BS3OO"). Fall through to outcode lookup as a courtesy.
        }

        if ($this->looksLikeOutcode($normalised)) {
            $coords = $this->lookupOutcode($normalised);
            if ($coords !== null) {
                return $coords;
            }
        }

        return $this->lookupPlace($area);
    }

    /**
     * UK full postcode: 1-2 letters, 1-2 digits, optional letter, then a
     * digit and two letters. Validated by shape only; postcodes.io will
     * confirm the actual existence.
     */
    private function looksLikeFullPostcode(string $upper): bool
    {
        return (bool) preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $upper);
    }

    /**
     * UK outcode: the part before the space. 1-2 letters, 1-2 digits, optional letter.
     */
    private function looksLikeOutcode(string $upper): bool
    {
        return (bool) preg_match('/^[A-Z]{1,2}\d[A-Z\d]?$/', $upper);
    }

    private function lookupPostcode(string $postcode): ?Coordinates
    {
        $url = self::API_BASE . '/postcodes/' . rawurlencode($postcode);
        $body = $this->fetchJson($url);
        if ($body === null || !isset($body['result']) || !is_array($body['result'])) {
            return null;
        }
        return $this->coordsFromResult($body['result']);
    }

    private function lookupOutcode(string $outcode): ?Coordinates
    {
        $url = self::API_BASE . '/outcodes/' . rawurlencode($outcode);
        $body = $this->fetchJson($url);
        if ($body === null || !isset($body['result']) || !is_array($body['result'])) {
            return null;
        }
        return $this->coordsFromResult($body['result']);
    }

    private function lookupPlace(string $area): ?Coordinates
    {
        $url = self::API_BASE . '/places?q=' . rawurlencode($area);
        $body = $this->fetchJson($url);
        if ($body === null || empty($body['result']) || !is_array($body['result'])) {
            return null;
        }
        // First result is the closest match by postcodes.io's own ranking.
        return $this->coordsFromResult($body['result'][0]);
    }

    /**
     * Pull lat/lng out of a postcodes.io result row. The field names are
     * identical across the postcode, outcode, and place endpoints
     * (`latitude` / `longitude`), so one helper covers all three.
     */
    private function coordsFromResult(array $result): ?Coordinates
    {
        if (!isset($result['latitude'], $result['longitude'])) {
            return null;
        }
        // postcodes.io returns null for a handful of legitimately unmapped
        // postcodes (e.g. some BFPO codes). Treat those as misses.
        if ($result['latitude'] === null || $result['longitude'] === null) {
            return null;
        }
        try {
            return new Coordinates((float) $result['latitude'], (float) $result['longitude']);
        } catch (\Throwable $e) {
            self::logWarning('Geocoder: invalid coordinates from postcodes.io', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Do an HTTP GET and decode JSON. Returns null on any error — network,
     * non-2xx, or invalid JSON — and logs at debug level so a routine
     * "postcode not found" 404 does not spam the log.
     */
    private function fetchJson(string $url): ?array
    {
        $response = wp_remote_get($url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            self::logWarning('Geocoder: HTTP error', [
                'url'   => $url,
                'error' => $response->get_error_message(),
            ]);
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            // 404 for an unknown postcode is the common case — debug, not warn.
            self::logDebug('Geocoder: non-2xx response', [
                'url'    => $url,
                'status' => $code,
            ]);
            return null;
        }

        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::logWarning('Geocoder: invalid JSON', ['url' => $url]);
            return null;
        }

        return $decoded;
    }
}
