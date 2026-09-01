<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let the application see the reader, not the Cloudflare data centre in front
 * of them.
 *
 * Requests arrive Cloudflare → traefik → nginx → PHP, and traefik overwrites
 * X-Forwarded-For with the address it is talking to, which is a Cloudflare
 * edge. Everything downstream that asks "who is this" therefore got an edge
 * address shared by thousands of unrelated people:
 *
 *   CF-Connecting-IP : 2001:e68:5425:...    the reader
 *   X-Forwarded-For  : 162.158.88.107       a Cloudflare machine in Malaysia
 *   REMOTE_ADDR      : 127.0.0.1            traefik
 *
 * Two things were quietly wrong because of it. The admin login's rate limiter
 * buckets attempts per address, so everyone behind one edge shared a bucket and
 * could lock each other out. And the first-visit location lookup was
 * geolocating Cloudflare's points of presence rather than readers - a Malaysian
 * reader routed through Cloudflare's Singapore edge was told the news near
 * Singapore.
 *
 * ⛔ CF-Connecting-IP is only trusted when the request actually came from
 * Cloudflare. It is an ordinary header: anyone who can reach this origin
 * directly can set it to whatever they like, and taking it on faith would let
 * them pick their own rate-limit bucket and their own apparent location. So the
 * hop we can see - the address traefik recorded - has to be inside Cloudflare's
 * published ranges before the header means anything.
 *
 * ⚠ Those ranges change occasionally. They are published at
 * https://www.cloudflare.com/ips/ and were taken from there on 2026-09-01. If
 * real addresses ever stop appearing, check that list first.
 */
class TrustCloudflare
{
    /** https://www.cloudflare.com/ips-v4 — read 2026-09-01. */
    private const V4 = [
        '173.245.48.0/20',  '103.21.244.0/22', '103.22.200.0/22',
        '103.31.4.0/22',    '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20',  '188.114.96.0/20', '197.234.240.0/22',
        '198.41.128.0/17',  '162.158.0.0/15',  '104.16.0.0/13',
        '104.24.0.0/14',    '172.64.0.0/13',   '131.0.72.0/22',
    ];

    /** https://www.cloudflare.com/ips-v6 — read 2026-09-01. */
    private const V6 = [
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
        '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->headers->get('CF-Connecting-IP');

        if ($client === null || filter_var($client, FILTER_VALIDATE_IP) === false) {
            return $next($request);
        }

        if (!$this->cameFromCloudflare($request)) {
            return $next($request);
        }

        // Rewritten rather than read directly, so that everything already
        // asking $request->ip() - the rate limiter, the location lookup, the
        // session record - gets the right answer without knowing any of this
        // exists.
        $request->headers->set('X-Forwarded-For', $client);
        $request->server->set('HTTP_X_FORWARDED_FOR', $client);

        return $next($request);
    }

    /**
     * Is the hop we can actually see a Cloudflare machine?
     *
     * REMOTE_ADDR is traefik on this host, so the useful evidence is whatever
     * traefik recorded as its own peer.
     */
    private function cameFromCloudflare(Request $request): bool
    {
        $forwarded = (string) $request->server->get('HTTP_X_FORWARDED_FOR', '');
        $candidates = array_filter(array_map('trim', explode(',', $forwarded)));

        $realIp = $request->headers->get('X-Real-IP');

        if ($realIp) {
            $candidates[] = trim($realIp);
        }

        foreach ($candidates as $hop) {
            if ($this->isCloudflare($hop)) {
                return true;
            }
        }

        return false;
    }

    private function isCloudflare(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::V4 as $range) {
                if ($this->inRange($ip, $range, 32)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            foreach (self::V6 as $range) {
                if ($this->inRange($ip, $range, 128)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compare the leading bits of two packed addresses.
     *
     * Done on the binary form rather than by arithmetic so that one routine
     * handles both IPv4 and IPv6 - Cloudflare serves plenty of both, and
     * dropping v6 would silently exclude every reader on a modern mobile
     * network.
     */
    private function inRange(string $ip, string $cidr, int $maxBits): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, (string) $maxBits);
        $bits = (int) $bits;

        $packedIp = @inet_pton($ip);
        $packedSubnet = @inet_pton($subnet);

        if ($packedIp === false || $packedSubnet === false
            || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $spareBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($packedIp, $packedSubnet, $wholeBytes) !== 0) {
            return false;
        }

        if ($spareBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $spareBits)) - 1) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedSubnet[$wholeBytes]) & $mask);
    }
}
