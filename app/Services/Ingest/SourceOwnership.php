<?php

namespace App\Services\Ingest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Proving that whoever filled in the form controls the site.
 *
 * ⛔⛔ THE CHECKBOX IS NOT EVIDENCE. "I run this site, or I can speak for
 * whoever does" is a recorded answer and worth having - it is what a takedown
 * request can be answered against - but anybody can tick it about anybody
 * else's website. Impersonating a newspaper takes one form submission.
 *
 * All three methods below prove the same single thing: control of the domain.
 * That is the only claim about a website that can actually be checked from
 * outside, and it is enough - somebody who can put a file on a newspaper's
 * server or change its DNS is, for our purposes, the newspaper.
 *
 * ⛔ VERIFICATION IS NOT APPROVAL. It says the submitter is who they say they
 * are. Whether the site belongs in the feed is still a person's decision.
 */
class SourceOwnership
{
    private const UA = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    /** The name looked for in a meta tag and a DNS TXT record. */
    public const KEY = 'nearbypost-site-verification';

    /** Where the file method expects to find the token. */
    public const FILE_PATH = '/.well-known/nearbypost-verification.txt';

    /** Enough entropy that a token cannot be guessed for somebody else's site. */
    public static function tokenFor(int $requestId): string
    {
        $token = DB::table('source_requests')->where('id', $requestId)->value('verify_token');

        if ($token) {
            return $token;
        }

        $token = 'nbp-' . Str::lower(Str::random(32));

        DB::table('source_requests')->where('id', $requestId)
            ->update(['verify_token' => $token, 'updated_at' => now()]);

        return $token;
    }

    /**
     * Look for the token, three ways, and stop at the first that answers.
     *
     * @return array{verified: bool, method: ?string, why: string}
     */
    public static function check(int $requestId): array
    {
        $row = DB::table('source_requests')->where('id', $requestId)
            ->first(['id', 'website_url', 'verify_token', 'verify_attempts']);

        if ($row === null) {
            return ['verified' => false, 'method' => null, 'why' => 'No such request.'];
        }

        // ⛔ Rate limited. Each attempt is a request to somebody else's server,
        // and an unbounded "check again" button is a way to use NearbyPost to
        // hammer a third party.
        if ((int) $row->verify_attempts >= 30) {
            return ['verified' => false, 'method' => null,
                    'why' => 'Checked too many times. Ask an administrator to look.'];
        }

        $token = self::tokenFor($requestId);
        $host  = parse_url($row->website_url, PHP_URL_HOST);

        if ($host === null) {
            return ['verified' => false, 'method' => null, 'why' => 'That address has no host.'];
        }

        // ⛔ THE SAME PRIVATE-NETWORK REFUSAL THE PROBE USES. A verification
        // check is another server-side fetch of a user-supplied address, so it
        // is exactly as exploitable if left unguarded - and this one runs on a
        // button anybody can press.
        $refusal = (new SourceProbe())->refuseIfInternal($host);

        if ($refusal !== null) {
            return ['verified' => false, 'method' => null, 'why' => $refusal];
        }

        DB::table('source_requests')->where('id', $requestId)
            ->update(['verify_attempts' => (int) $row->verify_attempts + 1, 'updated_at' => now()]);

        foreach (['dns' => 'dns', 'meta' => 'meta', 'file' => 'file'] as $method) {
            if (self::found($method, $host, $token)) {
                DB::table('source_requests')->where('id', $requestId)->update([
                    'verify_method' => $method,
                    'verified_at'   => now(),
                    'verify_error'  => null,
                    'updated_at'    => now(),
                ]);

                return ['verified' => true, 'method' => $method,
                        'why' => 'Confirmed by ' . self::methodName($method) . '.'];
            }
        }

        $why = 'The token was not found by DNS, in a meta tag, or at ' . self::FILE_PATH . '.';

        DB::table('source_requests')->where('id', $requestId)
            ->update(['verify_error' => $why, 'updated_at' => now()]);

        return ['verified' => false, 'method' => null, 'why' => $why];
    }

    /** What the publisher is asked to do, in their words. */
    public static function instructions(string $token, string $host): array
    {
        return [
            'dns' => [
                'name'  => 'A DNS record',
                'what'  => 'Add a TXT record on ' . $host . ' with this value.',
                'value' => self::KEY . '=' . $token,
                'note'  => 'Best if you have someone who manages your domain. It can take an hour to appear.',
            ],
            'meta' => [
                'name'  => 'A tag on your home page',
                'what'  => 'Put this in the <head> of https://' . $host . '/',
                'value' => '<meta name="' . self::KEY . '" content="' . $token . '">',
                'note'  => 'Most site builders have a place for this, often called "header code" or "SEO verification".',
            ],
            'file' => [
                'name'  => 'A file on your site',
                'what'  => 'Put a plain text file at https://' . $host . self::FILE_PATH . ' containing only this.',
                'value' => $token,
                'note'  => 'Easiest if you can upload files to your site.',
            ],
        ];
    }

    private static function methodName(string $method): string
    {
        return match ($method) {
            'dns'  => 'a DNS record',
            'meta' => 'a tag on the home page',
            'file' => 'a file on the site',
            default => $method,
        };
    }

    private static function found(string $method, string $host, string $token): bool
    {
        try {
            return match ($method) {
                'dns'  => self::inDns($host, $token),
                'meta' => self::inMeta($host, $token),
                'file' => self::inFile($host, $token),
                default => false,
            };
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function inDns(string $host, string $token): bool
    {
        foreach (@dns_get_record($host, DNS_TXT) ?: [] as $record) {
            $text = (string) ($record['txt'] ?? '');

            if (str_contains($text, self::KEY . '=' . $token) || trim($text) === $token) {
                return true;
            }
        }

        return false;
    }

    private static function inMeta(string $host, string $token): bool
    {
        $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(15)->get('https://' . $host . '/');

        if (!$r->successful()) {
            return false;
        }

        // The token must sit in a meta tag with our name on it - not merely
        // appear somewhere on the page, which anybody could arrange by posting
        // a comment containing it.
        return (bool) preg_match(
            '#<meta[^>]+name=["\']' . preg_quote(self::KEY, '#') . '["\'][^>]+content=["\']'
            . preg_quote($token, '#') . '["\']#i',
            $r->body()
        );
    }

    private static function inFile(string $host, string $token): bool
    {
        $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(15)
            ->get('https://' . $host . self::FILE_PATH);

        // ⛔ Many sites answer 200 with their own "not found" page, so the body
        // has to BE the token, not merely contain it.
        return $r->successful() && trim($r->body()) === $token;
    }
}
