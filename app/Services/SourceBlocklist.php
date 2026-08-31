<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The addresses the crawler has been told to leave alone.
 *
 * Consulted on every fetch and every discovery run, so it is cached - and the
 * cache is versioned rather than timed, because an editor who blocks a junk
 * domain expects the very next run to honour it, not the one after the TTL
 * expires.
 */
class SourceBlocklist
{
    private const TTL_SECONDS = 300;

    /** Is this address one we have been told not to visit? */
    public function isBlocked(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        $rules = $this->rules();

        if ($rules === ['urls' => [], 'hosts' => []]) {
            return false;
        }

        if (isset($rules['urls'][$this->normalise($url)])) {
            return true;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        // A blocked host covers its subdomains: junk arrives by the domain.
        foreach (array_keys($rules['hosts']) as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when any of a source's addresses is blocked.
     *
     * A source carries up to three - the feed, the section page and the
     * homepage - and blocking any one of them should stop it being read.
     */
    public function blocksSource(object $source): bool
    {
        foreach (['rss_url', 'index_url', 'base_url'] as $field) {
            if ($this->isBlocked($source->{$field} ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function block(?string $url, string $scope, ?string $reason, ?int $adminId): void
    {
        $url = trim((string) $url);
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return;
        }

        DB::table('blocked_urls')->insert([
            'url'        => $scope === 'host' ? null : mb_substr($url, 0, 990),
            'host'       => $host,
            'scope'      => $scope === 'host' ? 'host' : 'url',
            'reason'     => $reason ? mb_substr($reason, 0, 300) : null,
            'blocked_by' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->forget();
    }

    public function unblock(int $id): void
    {
        DB::table('blocked_urls')->where('id', $id)->delete();

        $this->forget();
    }

    /** @return array{urls: array<string, true>, hosts: array<string, true>} */
    private function rules(): array
    {
        return Cache::remember('blocked_urls:' . $this->version(), self::TTL_SECONDS, function () {
            $urls = [];
            $hosts = [];

            foreach (DB::table('blocked_urls')->get() as $row) {
                if ($row->scope === 'host') {
                    $hosts[mb_strtolower($row->host)] = true;
                } elseif ($row->url) {
                    $urls[$this->normalise($row->url)] = true;
                }
            }

            return ['urls' => $urls, 'hosts' => $hosts];
        });
    }

    /** Ignore the trailing slash and the scheme: they are the same address. */
    private function normalise(string $url): string
    {
        return rtrim(mb_strtolower(preg_replace('#^https?://#i', '', trim($url)) ?? $url), '/');
    }

    private function version(): string
    {
        return (string) Cache::get('blocked_urls:version', '1');
    }

    private function forget(): void
    {
        Cache::forever('blocked_urls:version', (string) time());
    }
}
