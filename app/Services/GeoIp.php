<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Where a first-time reader probably is, from their IP address.
 *
 * Every feed used to open in Kuala Lumpur regardless of who was reading it, so
 * a reader in Penang was shown Kuala Lumpur's news and had to know to type
 * their own town before the site did anything local. On a site whose whole
 * premise is distance, that is the wrong first impression.
 *
 * This only ever supplies the *starting* place. The moment a reader types one
 * it is remembered in their cookie and this is never consulted again, so an IP
 * that resolves badly is one keystroke from being corrected rather than
 * something to fight.
 *
 * Three things it deliberately does not do:
 *
 * It does not answer for crawlers. Googlebot reaches us from the United States,
 * and letting an IP place the homepage would hand the canonical page a foreign
 * city and index it that way.
 *
 * It does not delay the page. One second of timeout, and any failure at all -
 * unreachable service, malformed answer, private address - returns null so the
 * caller falls back to the default. A missing city is a mild disappointment; a
 * page that hangs on a third party is an outage.
 *
 * It does not keep IP addresses. The cache key is a hash of the network, not
 * the address, and nothing is written to the database.
 *
 * ⚠ The lookup does send the reader's IP to the configured provider. That is
 * worth a line in the privacy policy.
 */
class GeoIp
{
    private const CACHE_HOURS = 24;
    private const TIMEOUT_SECONDS = 1;

    /**
     * Bots that must always see the site's default place.
     *
     * Matched case-insensitively as substrings, which is enough: this is a
     * courtesy to crawlers, not a security boundary, and an unlisted crawler
     * costs one wrong city rather than anything worse.
     */
    private const CRAWLERS = [
        'bot', 'crawler', 'spider', 'slurp', 'facebookexternalhit',
        'embedly', 'quora link preview', 'whatsapp', 'telegram',
        'preview', 'fetcher', 'monitor', 'headless', 'python-requests', 'curl', 'wget',
    ];

    /**
     * The reader's town or city, or null when it cannot be established.
     *
     * $country, where given, is Cloudflare's own two-letter answer, which
     * arrives on every request for free. A reader it has already placed outside
     * Malaysia needs no lookup at all: this site only ever wanted a Malaysian
     * town, and skipping the call spares an external request and one fewer IP
     * address handed to a third party.
     */
    public function place(?string $ip, ?string $userAgent = null, ?string $country = null): ?string
    {
        if (!config('services.geoip.enabled')) {
            return null;
        }

        if ($country !== null && mb_strtoupper(trim($country)) !== 'MY') {
            return null;
        }

        if ($this->looksLikeCrawler($userAgent)) {
            return null;
        }

        if (!$this->isPublic($ip)) {
            return null;
        }

        // Keyed by the /24, not the address: neighbouring readers share an
        // answer, the cache stays small, and no address is stored anywhere.
        $key = 'geoip:' . substr(hash('sha256', $this->network($ip)), 0, 32);

        $place = Cache::remember($key, now()->addHours(self::CACHE_HOURS), function () use ($ip) {
            return $this->lookup($ip) ?? '';
        });

        return $place !== '' ? $place : null;
    }

    /**
     * Ask the configured provider, and treat every disappointment the same way.
     */
    private function lookup(string $ip): ?string
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(str_replace('{ip}', urlencode($ip), (string) config('services.geoip.url')));

            if (!$response->successful()) {
                return null;
            }

            $body = $response->json();

            if (!is_array($body)) {
                return null;
            }

            // Only place a reader who is somewhere this site has news. Every
            // story here is Malaysian, so pinning a reader in Singapore to
            // Singapore is an accurate answer that produces an empty feed - and
            // Malaysians on a VPN or an oddly-routed carrier land there often.
            if (!$this->inMalaysia($body)) {
                return null;
            }

            // Field names differ between providers; take the first that looks
            // like a place, most specific first.
            foreach (['city', 'region', 'state_prov'] as $field) {
                $value = $body[$field] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return mb_substr(trim($value), 0, 120);
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::info('GeoIP lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Providers disagree on the field name, so check whichever they sent and
     * refuse when none of them says Malaysia.
     */
    private function inMalaysia(array $body): bool
    {
        foreach (['country_code', 'countryCode', 'country_code2'] as $field) {
            if (isset($body[$field]) && is_string($body[$field])) {
                return mb_strtoupper($body[$field]) === 'MY';
            }
        }

        if (isset($body['country']) && is_string($body['country'])) {
            return mb_strtolower(trim($body['country'])) === 'malaysia';
        }

        return false;
    }

    private function looksLikeCrawler(?string $userAgent): bool
    {
        $agent = mb_strtolower(trim((string) $userAgent));

        if ($agent === '') {
            // No user agent at all is far more often a script than a reader.
            return true;
        }

        foreach (self::CRAWLERS as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A routable address we can sensibly ask about.
     */
    private function isPublic(?string $ip): bool
    {
        if (!is_string($ip) || $ip === '') {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** The /24 for IPv4, the /48 for IPv6. */
    private function network(string $ip): string
    {
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
        }

        $parts = explode('.', $ip);

        return implode('.', array_slice($parts, 0, 3)) . '.0';
    }
}
