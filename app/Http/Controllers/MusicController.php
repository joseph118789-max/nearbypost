<?php

namespace App\Http\Controllers;

use App\Console\Commands\SyncMusicCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The songs behind the sidebar player.
 *
 * Asked for only when a reader actually opens the player, so a page of news
 * costs nothing to load for the many people who never touch it. The catalogue
 * itself is a file copied from listingmine.com by `music:sync` - nothing here
 * calls another site while somebody waits.
 */
class MusicController extends Controller
{
    /** Enough to listen for an hour without asking again; small enough to send. */
    private const PLAYLIST_SIZE = 30;

    private const LANGUAGES = ['mandarin', 'english', 'malay', 'cantonese'];

    private const ROLES = ['agent', 'team-leader', 'boss', 'admin', 'student'];

    public function tracks(Request $request): JsonResponse
    {
        $language = $this->pick($request->query('lang'), self::LANGUAGES);
        $role     = $this->pick($request->query('role'), self::ROLES);

        $tracks = $this->catalogue();

        if ($language !== null) {
            $tracks = array_filter($tracks, fn ($t) => in_array($language, $t['lang'] ?? [], true));
        }

        if ($role !== null) {
            $tracks = array_filter($tracks, fn ($t) => in_array($role, $t['roles'] ?? [], true));
        }

        $tracks = array_values($tracks);

        // Shuffled, because the same list in the same order every time is a
        // playlist nobody finishes. A different thirty each visit is the point
        // of a station rather than an album.
        shuffle($tracks);

        return response()->json([
            'total'  => count($tracks),
            'tracks' => array_map(
                fn ($t) => [
                    'v'     => $t['v'],
                    'title' => $t['title'],
                    'album' => $t['album'],
                    'dur'   => $t['dur'] ?? null,
                ],
                array_slice($tracks, 0, self::PLAYLIST_SIZE)
            ),
        ]);
    }

    /**
     * The catalogue file, read once per process and cached briefly.
     *
     * An empty list when the file is missing rather than an error: the player
     * hides itself and the news is unaffected, which is the right failure for
     * something sponsored sitting beside something people came for.
     */
    private function catalogue(): array
    {
        return Cache::remember('music:catalogue', now()->addHour(), function () {
            if (!Storage::disk('local')->exists(SyncMusicCatalogue::FILE)) {
                return [];
            }

            $decoded = json_decode(Storage::disk('local')->get(SyncMusicCatalogue::FILE), true);

            return $decoded['tracks'] ?? [];
        });
    }

    private function pick(?string $value, array $allowed): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, $allowed, true) ? $value : null;
    }
}
