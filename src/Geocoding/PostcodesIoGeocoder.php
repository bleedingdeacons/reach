<?php

declare(strict_types=1);

namespace Reach\Geocoding;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Distance\Haversine;

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
 * Strategy 3 also takes an optional **place bias**: a free-text area
 * (typically a postcode like "BS5") whose centroid is used to
 * disambiguate ambiguous place-name lookups. Many UK locality names
 * collide ("Kingswood" exists in Bristol, Surrey, Warwickshire and
 * elsewhere); without a bias, the geocoder takes whichever entry
 * postcodes.io happens to rank first, which is rarely the one the
 * intergroup wants. When a bias is configured, place lookups fetch
 * multiple candidates and return the one closest to the bias centre.
 * The bias is resolved lazily on first use and cached like any other
 * lookup. Postcode and outcode lookups ignore the bias — they're
 * unambiguous by construction.
 *
 * Each successfully resolved lookup is cached in a WordPress transient
 * keyed by a normalised form of the input *and* the active bias. Misses
 * are cached for a much shorter period to avoid hammering the API for a
 * typo while still letting the data heal once an admin fixes the bad
 * area string.
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

    /** Cached bias coordinates after first resolution. Null is ambiguous — see $biasResolved. */
    private ?Coordinates $resolvedBias = null;

    /** Has the bias string been resolved yet (possibly to null)? */
    private bool $biasResolved = false;

    /**
     * Recursion guard. When resolving the bias string itself we re-enter
     * geocode(), and that re-entry must not try to apply the (not-yet-
     * known) bias to its own resolution.
     */
    private bool $resolvingBias = false;

    /**
     * @param string $biasArea Free-text area whose centroid disambiguates
     *                         place-name lookups. Empty disables biasing.
     *                         See {@see Settings::getPlaceBias()}.
     */
    public function __construct(private readonly string $biasArea = '')
    {
    }

    public function geocode(string $area): ?Coordinates
    {
        $area = trim($area);
        if ($area === '') {
            return null;
        }

        // Bias must be part of the cache key so that toggling the admin
        // setting doesn't keep returning the now-wrong centroid for an
        // ambiguous place name. The biasFragment is empty when bias is
        // disabled, preserving cache compatibility with the no-bias
        // build. While resolving the bias string itself we deliberately
        // key without bias — the bias resolution is just a plain
        // geocode and must not depend on itself.
        $biasFragment = $this->resolvingBias ? '' : $this->biasCacheFragment();
        $cacheKey = self::CACHE_PREFIX . md5($biasFragment . '|' . strtolower($area));
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
        $bias = $this->resolvingBias ? null : $this->getBias();

        // Without a bias: postcodes.io's default ranking (closest match
        // by name) is fine — take the first hit and stop. With a bias:
        // fetch more candidates so a less-prominent match in the right
        // region beats a more-prominent match in the wrong one. The
        // limit of 20 is generous for any place-name collision we care
        // about; postcodes.io caps at 100 if it ever proves too low.
        $url = self::API_BASE . '/places?q=' . rawurlencode($area);
        if ($bias !== null) {
            $url .= '&limit=20';
        }

        $body = $this->fetchJson($url);
        if ($body === null || empty($body['result']) || !is_array($body['result'])) {
            return null;
        }

        if ($bias === null) {
            return $this->coordsFromResult($body['result'][0]);
        }

        // Re-rank by distance to the configured bias centre. We can't
        // push this into the API call — postcodes.io's /places endpoint
        // has no geographic filter — so it happens client-side over the
        // small candidate set.
        $best = null;
        $bestDistance = INF;
        foreach ($body['result'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = $this->coordsFromResult($row);
            if ($candidate === null) {
                continue;
            }
            $distance = Haversine::kilometres($bias, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Resolve the bias string (e.g. "BS5") to coordinates on first
     * use and cache for the life of the instance. A null result here
     * either means no bias is configured or the configured bias didn't
     * geocode — in both cases the geocoder falls back to unbiased
     * place ranking.
     */
    private function getBias(): ?Coordinates
    {
        if ($this->biasResolved) {
            return $this->resolvedBias;
        }
        if ($this->biasArea === '') {
            $this->biasResolved = true;
            return null;
        }

        // resolvingBias guards two things at once: the cache key
        // (so the bias's own lookup keys without a bias fragment) and
        // lookupPlace (so it doesn't try to apply a bias while
        // computing one).
        $this->resolvingBias = true;
        try {
            $this->resolvedBias = $this->geocode($this->biasArea);
        } finally {
            $this->resolvingBias = false;
            $this->biasResolved = true;
        }
        return $this->resolvedBias;
    }

    private function biasCacheFragment(): string
    {
        return $this->biasArea === '' ? '' : 'bias:' . strtolower($this->biasArea);
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
