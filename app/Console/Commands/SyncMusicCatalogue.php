<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Copy the ListingMine Music catalogue here, once, as a file.
 *
 * The player in the sidebar needs to know which songs are Malay, which are for
 * admins, and which YouTube video each one is. That lives on listingmine.com,
 * in two arrays inside the page: `albums`, which carries the language and the
 * audience, and `ALL`, which carries every song and the album it belongs to.
 *
 * Copied rather than called. A reader loading a Nearbypost page should never
 * wait on another site, and a music page being slow, moved or down must not
 * cost anybody their news - the same reason the ERP is not allowed to depend on
 * music infrastructure. The catalogue changes when an album is published, which
 * is rarely, so a file refreshed by hand or by cron is the right shape.
 *
 * Run: php artisan music:sync
 */
class SyncMusicCatalogue extends Command
{
    protected $signature = 'music:sync {--dry-run : Report what would be written and write nothing}';

    protected $description = 'Copy the ListingMine Music catalogue into storage, for the sidebar player';

    private const SOURCE = 'https://www.listingmine.com/music';

    public const FILE = 'music-catalogue.json';

    /**
     * The audiences the page uses, folded into the words a reader here would
     * pick. "Boss" is what an agency owner is called out loud.
     */
    private const ROLES = [
        'agent'        => ['agent'],
        'team-leader'  => ['team leader', 'leader'],
        'boss'         => ['agency owner', 'agency', 'entrepreneur'],
        'admin'        => ['admin'],
        'student'      => ['student'],
    ];

    public function handle(): int
    {
        $this->info('Reading ' . self::SOURCE);

        try {
            $response = Http::withHeaders(['User-Agent' => 'Nearbypost/1.0 (+https://nearbypost.com)'])
                ->timeout(30)
                ->get(self::SOURCE);
        } catch (\Throwable $e) {
            $this->error('Could not reach the music page: ' . $e->getMessage());

            return 1;
        }

        if (!$response->successful()) {
            $this->error('The music page answered HTTP ' . $response->status());

            return 1;
        }

        $html = $response->body();

        $albums = $this->arrayNamed($html, 'albums');
        $songs  = $this->arrayNamed($html, 'ALL');

        if ($albums === null || $songs === null) {
            // Said plainly rather than written as an empty catalogue: a player
            // with no songs looks like a broken player, and the cause would be
            // a page whose markup changed, which is worth knowing about.
            $this->error('Could not find the albums or the songs in the page. Its markup has changed.');

            return 1;
        }

        $byTitle = [];

        foreach ($albums as $album) {
            $byTitle[$album['title']] = [
                'languages' => array_map('mb_strtolower', $album['languages'] ?? []),
                'roles'     => $this->rolesFor($album['audience'] ?? []),
            ];
        }

        $tracks = [];
        $orphans = 0;

        foreach ($songs as $song) {
            $album = $byTitle[$song['album']] ?? null;

            if ($album === null || empty($song['videoId'])) {
                $orphans++;

                continue;
            }

            $tracks[] = [
                'v'     => $song['videoId'],
                'title' => $this->tidy($song['title']),
                'album' => $song['album'],
                'dur'   => $song['dur'] ?? null,
                'lang'  => $album['languages'],
                'roles' => $album['roles'],
            ];
        }

        $this->line(sprintf('  %d albums, %d tracks%s',
            count($albums),
            count($tracks),
            $orphans > 0 ? ", {$orphans} skipped (no album or no video)" : ''
        ));

        foreach (self::ROLES as $role => $_) {
            $n = count(array_filter($tracks, fn ($t) => in_array($role, $t['roles'], true)));
            $this->line(sprintf('    %-12s %d tracks', $role, $n));
        }

        foreach (['mandarin', 'english', 'malay', 'cantonese'] as $language) {
            $n = count(array_filter($tracks, fn ($t) => in_array($language, $t['lang'], true)));
            $this->line(sprintf('    %-12s %d tracks', $language, $n));
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run - nothing written.');

            return 0;
        }

        $json = json_encode([
            'synced_at' => now()->toAtomString(),
            'source'    => self::SOURCE,
            'tracks'    => $tracks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Checked, not assumed. json_encode returns false on malformed UTF-8,
        // and writing false leaves an empty file where the catalogue should be:
        // a player with no songs, and a command that said it worked.
        if ($json === false) {
            $this->error('Could not encode the catalogue: ' . json_last_error_msg());

            return 1;
        }

        Storage::disk('local')->put(self::FILE, $json);

        $written = Storage::disk('local')->size(self::FILE);

        if ($written < 1000) {
            $this->error(sprintf('Wrote only %d bytes - that is not a catalogue.', $written));

            return 1;
        }

        $this->info(sprintf('Written to %s (%s KB)', self::FILE, number_format($written / 1024, 1)));

        return 0;
    }

    /**
     * Pull one JavaScript array literal out of the page by the name it is
     * assigned to, counting brackets so a nested array inside a song does not
     * end the match early.
     */
    private function arrayNamed(string $html, string $name): ?array
    {
        if (!preg_match('/(?:const|let|var)\s+' . preg_quote($name, '/') . '\s*=\s*\[/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = strpos($html, '[', $m[0][1]);
        $depth = 0;
        $length = strlen($html);

        for ($i = $start; $i < $length; $i++) {
            if ($html[$i] === '[') {
                $depth++;
            } elseif ($html[$i] === ']') {
                $depth--;

                if ($depth === 0) {
                    $decoded = json_decode(substr($html, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function rolesFor(array $audience): array
    {
        $roles = [];

        foreach ($audience as $who) {
            $who = mb_strtolower(trim($who));

            foreach (self::ROLES as $role => $words) {
                if (in_array($who, $words, true)) {
                    $roles[$role] = true;
                }
            }
        }

        return array_keys($roles);
    }

    /**
     * The catalogue numbers its tracks for its own album pages - "1. 《我定江山》｜
     * 千兵万裂｜..." - which beside a news feed is three facts nobody asked for.
     * The song's own name is what fits in a sidebar.
     */
    private function tidy(string $title): string
    {
        $title = preg_replace('/^\s*\d+[.\s]\s*/u', '', $title);
        $title = preg_split('/[｜|]/u', $title)[0] ?? $title;

        // preg, not trim. trim() strips BYTES, and 《 》 are three bytes each -
        // so trimming them cut UTF-8 characters in half, json_encode refused the
        // result, and the catalogue was written as an EMPTY FILE that the
        // command reported as a success.
        // The album is already shown under the title, so "[Manual Hidup Admin]"
        // on the end of every one of its tracks is the same word twice in a
        // 290px column. Matched with the closing bracket optional, because the
        // catalogue does not always close it.
        $title = preg_replace('/\s*\[[^\]]*\]?\s*$/u', '', $title);

        return preg_replace('/^[\s《【]+|[\s》】]+$/u', '', $title);
    }
}
