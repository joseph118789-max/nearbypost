<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a model trained somewhere else will not know about Malaysia.
 *
 * This is the piece that decides whether the site can change AI brand. The
 * current model reads a great deal of Southeast Asian text and arrives already
 * knowing that a Menteri Besar heads a state government. A model trained mostly
 * on American English may not - and it will not say so. It will file a Kedah
 * story under the dateline city and move on, and nothing downstream will
 * notice.
 *
 * ⭐ THE IMPLICATION IS THE POINT. "MB = Menteri Besar" is trivia. "A story
 * about the MB of Kedah is a Kedah story whatever its dateline says" is the
 * classification. A term with no implication is not worth the tokens it costs
 * on every article the site ever reads, so those are left out of the prompt.
 */
class Briefing
{
    private const TTL_SECONDS = 300;

    /** Kinds, so the panel can group them and a reader can scan by type. */
    public const KINDS = [
        'place'  => 'Places and geography',
        'body'   => 'Government and agencies',
        'scheme' => 'Schemes and programmes',
        'media'  => 'Media and sources',
        'title'  => 'Titles and roles',
        'term'   => 'General',
    ];

    /**
     * The briefing, as a prompt block, or an empty string when there is none.
     */
    public function promptBlock(?string $country = null): string
    {
        $terms = $this->active($country);

        if ($terms === []) {
            return '';
        }

        $lines = [];

        foreach ($terms as $term) {
            $line = '- ' . $term['term'] . ': ' . $term['expansion'];

            if (trim((string) $term['implication']) !== '') {
                $line .= ' ' . trim($term['implication']);
            }

            $lines[] = $line;
        }

        return "LOCAL BRIEFING FOR " . strtoupper($country ? \App\Support\BrainCountry::name($country) : 'this country')
             . " - local knowledge you may not have. Where one of\n"
             . "these appears, the note tells you what it means for the answer:\n\n"
             . implode("\n", $lines) . "\n";
    }

    /**
     * Terms worth sending: active, and carrying an implication.
     *
     * Plain arrays, for the same reason the playbook uses them: a serialised
     * row object can come back from the cache as an incomplete class and throw
     * on the first property read.
     *
     * @return list<array<string, mixed>>
     */
    public function active(?string $country = null): array
    {
        $country = $country ? strtoupper($country) : null;

        return Cache::remember('briefing:' . ($country ?? 'base') . ':' . $this->version(), self::TTL_SECONDS, function () use ($country) {
            return DB::table('briefing_terms')
                ->where(function ($q) use ($country) { $q->whereNull('country'); if ($country) { $q->orWhere('country', $country); } })
                ->where('is_active', true)
                ->whereNotNull('implication')
                ->where('implication', '!=', '')
                ->orderBy('kind')
                ->orderBy('term')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        });
    }

    public function version(): string
    {
        return (string) Cache::get('briefing:version', '1');
    }

    /**
     * Same fault as the playbook had: time() has one-second resolution, so two
     * saves in the same second reuse the cache key and the second is invisible.
     */
    public function bumpVersion(): void
    {
        static $counter = 0;

        Cache::forever('briefing:version', sprintf('%s-%d', microtime(true), ++$counter));
    }
}
