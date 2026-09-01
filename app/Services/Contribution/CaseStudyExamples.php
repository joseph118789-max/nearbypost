<?php

namespace App\Services\Contribution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The case studies an editor has published, in a form a model can learn from.
 *
 * A house rule tells the model what to do in general. A case study shows it a
 * decision this newsroom actually made and stood behind: this story got ten
 * places, that one got none. Worked examples carry the kinds of judgement that
 * are painful to write as rules - where the line falls between a chain's own
 * branches and a whole class of business, or between a policy announced in
 * Putrajaya and a policy that happens in Putrajaya.
 *
 * ⛔ ONLY PUBLISHED CASES ARE TAUGHT. A draft is a proposal the editor has not
 * accepted, and half of what the model proposes is wrong - that is the reason
 * the queue exists. Teaching from drafts would feed the model its own
 * unreviewed guesses back as precedent, which is how a small error becomes
 * house style.
 *
 * Cached under a version bumped whenever a case is published or taken down, so
 * an editor can publish a case and see it change the next story's handling
 * rather than waiting out a TTL.
 */
class CaseStudyExamples
{
    private const TTL_SECONDS = 60;

    /**
     * Enough to show the pattern, few enough to leave room for the story.
     *
     * These sit in every classification prompt, so they are charged for on
     * every story the site ever reads. Eight examples is a page; eighty would
     * be a textbook the model skims.
     */
    private const MAX_EXAMPLES = 8;

    /** Places named in full before the rest are counted rather than listed. */
    private const MAX_PLACES_SHOWN = 6;

    /**
     * Worked examples for the classifier, or an empty string when there are
     * none.
     *
     * Empty rather than an empty heading: a heading with nothing under it reads
     * to a model as "this newsroom has decided nothing", which is a different
     * claim from saying nothing at all.
     */
    public function promptBlock(): string
    {
        $cases = $this->published();

        if ($cases === []) {
            return '';
        }

        $lines = [];

        foreach ($cases as $i => $case) {
            $lines[] = ($i + 1) . '. "' . $case['title'] . '"';
            $lines[] = '   ' . $case['verdict'];
        }

        return "WORKED EXAMPLES - decisions this newsroom has already published.\n"
             . "Where a story resembles one of these, answer the way the editor did:\n\n"
             . implode("\n", $lines) . "\n";
    }

    /**
     * @return list<array{title: string, verdict: string}>
     */
    public function published(): array
    {
        return Cache::remember('case_examples:' . $this->version(), self::TTL_SECONDS, function () {
            $cases = DB::table('news_items')
                ->where('origin', 'editorial')
                ->where('status', 'active')
                ->where('review_status', 'published')
                ->orderByDesc('published_at')
                ->limit(self::MAX_EXAMPLES)
                ->get(['id', 'title', 'outlet_scale']);

            if ($cases->isEmpty()) {
                return [];
            }

            $places = DB::table('story_locations')
                ->whereIn('news_item_id', $cases->pluck('id'))
                ->whereNotNull('lat')
                ->orderBy('id')
                ->get(['news_item_id', 'label'])
                ->groupBy('news_item_id');

            $out = [];

            foreach ($cases as $case) {
                $labels = ($places[$case->id] ?? collect())->pluck('label')->all();

                $out[] = [
                    'title'   => mb_substr((string) $case->title, 0, 180),
                    'verdict' => $this->verdict($case->outlet_scale, $labels),
                ];
            }

            return $out;
        });
    }

    /**
     * What the editor decided, said in the terms the model answers in.
     *
     * The reason matters more than the outcome. "No location" alone teaches a
     * model to be timid about locations; "no location, because it applies to
     * everyone in the country" teaches it the test to apply next time.
     */
    private function verdict(?string $scale, array $labels): string
    {
        if ($labels === []) {
            return $scale === 'national'
                ? 'NO LOCATION. It applies to everyone in the country, so it belongs to no '
                    . 'particular place and readers find it under its topic.'
                : 'NO LOCATION.';
        }

        $shown = array_slice($labels, 0, self::MAX_PLACES_SHOWN);
        $rest = count($labels) - count($shown);

        return count($labels) . ' PLACES: ' . implode('; ', $shown)
             . ($rest > 0 ? '; and ' . $rest . ' more' : '')
             . '. One named organisation, its own premises, countable.';
    }

    /** Bumped on publish and on takedown, so an editor's decision is live at once. */
    public function version(): string
    {
        return (string) Cache::get('case_examples:version', '1');
    }

    public function bumpVersion(): void
    {
        Cache::forever('case_examples:version', (string) time());
    }
}
