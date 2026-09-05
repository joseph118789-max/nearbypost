<?php

namespace App\Console\Commands;

use App\Models\NewsItem;
use App\Services\Geo\MalaysianStates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * How precisely do we know where this happened, and can we serve it by distance?
 *
 * The previous version answered this with two signals and both were wrong for
 * the job.
 *
 * It required `alias_match_type`, which is only set when a place appears in the
 * location_aliases table - a list of cities and districts. A bridge, a square or
 * a municipal stadium is never in it, so the most specific answers the model
 * produces arrived with no alias type and fell through every branch to "broad".
 *
 * And it used the geocoder's `importance`, which measures FAME, not precision.
 * Kuala Lumpur is famous and scores high; Victoria Bridge is obscure and scores
 * 0.37. So the better the answer, the worse it scored.
 *
 * Together they inverted the whole stage. Measured on live data before this
 * rewrite: "Arthur Ashe Stadium, New York", "Lebuh China, George Town, Penang",
 * "Dataran Putrajaya" and "Stadium Sultan Ibrahim, Iskandar Puteri" were all
 * marked too-vague to serve, while bare "George Town" and "Petaling Jaya" were
 * nearby-eligible. Every rule written to make the model more specific was being
 * reversed one stage later.
 *
 * WHAT IT USES NOW, in order of authority:
 *
 *   WHO FOUND IT. A coordinate a person typed is exact. One from Wikidata was
 *   typed by a person too, for a named venue, and can be corrected by another
 *   person - that is a different kind of thing from a gazetteer's best guess.
 *   A parent-landmark match is deliberately weaker: it is the building the
 *   venue sits in, not the venue.
 *
 *   THE SHAPE OF THE ANSWER. The model is asked to write narrow-first with the
 *   wider place after it, so "Victoria Bridge, Enggor, Kuala Kangsar" has three
 *   parts because it names three levels. Counting them measures exactly what
 *   the specificity ladder asks for, and costs nothing.
 *
 *   Fame is not consulted at all.
 */
class InterpretLocationPrecision extends Command
{
    protected $signature = 'ingest:interpret-precision
        {--news_item_id= : Process a specific item}
        {--limit=100    : Max items per run}
        {--all          : Re-judge everything, not only what is unjudged}';

    protected $description = 'Decide how precisely a story is placed, and whether it can be served by distance';

    private const PRECISION_EXACT   = 'exact_area';
    private const PRECISION_APPROX  = 'approximate_area';
    private const PRECISION_REGION  = 'region';
    private const PRECISION_UNKNOWN = 'unknown';

    private const COVERAGE_NEARBY    = 'nearby-eligible';
    private const COVERAGE_BROADER   = 'broader-only';
    private const COVERAGE_TOO_VAGUE = 'too-vague';
    private const COVERAGE_SKIPPED   = 'skipped';

    public function handle(): int
    {
        $query = NewsItem::query()
            ->where(function ($q) {
                $q->where('geocode_status', 'success')
                  ->orWhere(fn ($q2) => $q2->whereNull('geocode_status')
                      ->whereNotNull('latitude')->whereNotNull('longitude'));
            });

        if ($id = $this->option('news_item_id')) {
            $query->where('id', $id);
        } elseif (!$this->option('all')) {
            $query->where(fn ($q) => $q->whereNull('coverage_status')
                ->orWhereNotIn('coverage_status', [self::COVERAGE_NEARBY, self::COVERAGE_BROADER]));
        }

        // Newest first: a stage that cannot clear its backlog should spend its
        // limit on today's news rather than on the same old stories.
        $items = $query->orderByDesc('published_at')->limit((int) $this->option('limit'))->get();

        $this->info("Judging precision for {$items->count()} stories.");

        $tally = [];

        foreach ($items as $item) {
            $coverage = $this->interpret($item);
            $tally[$coverage] = ($tally[$coverage] ?? 0) + 1;
        }

        foreach ($tally as $coverage => $n) {
            $this->line(sprintf('  %-16s %d', $coverage, $n));
        }

        return 0;
    }

    private function interpret(NewsItem $item): string
    {
        if ($item->relevance_mode === 'category_only') {
            $item->update([
                'precision_type'       => null,
                'coverage_type'        => null,
                'geo_confidence_score' => null,
                'coverage_status'      => self::COVERAGE_SKIPPED,
                'geo_processed_at'     => now(),
            ]);

            return self::COVERAGE_SKIPPED;
        }

        $precision = $this->precisionOf($item);
        $coverage  = match ($precision) {
            self::PRECISION_EXACT, self::PRECISION_APPROX => self::COVERAGE_NEARBY,
            self::PRECISION_REGION                        => self::COVERAGE_BROADER,
            default                                       => self::COVERAGE_TOO_VAGUE,
        };

        $item->update([
            'precision_type'       => $precision,
            'coverage_type'        => $coverage,
            'geo_confidence_score' => $this->score($precision),
            'coverage_status'      => $coverage,
            'geo_processed_at'     => now(),
        ]);

        Log::info('Precision judged', [
            'news_item_id' => $item->id,
            'place'        => $item->main_place_text,
            'precision'    => $precision,
            'coverage'     => $coverage,
        ]);

        return $coverage;
    }

    /**
     * How precisely do we know this place?
     *
     * Never by how well known it is. A story about somewhere obscure is not a
     * worse-placed story.
     */
    private function precisionOf(NewsItem $item): string
    {
        $label = trim((string) ($item->canonical_place_name ?: $item->main_place_text));

        if ($label === '' || $item->latitude === null) {
            return self::PRECISION_UNKNOWN;
        }

        // A whole state is not somewhere a reader can be near. This should no
        // longer arrive - the classifier refuses one - but a story judged under
        // an older prompt still can, and it must not be served by distance.
        if (MalaysianStates::isBareState($label)) {
            return self::PRECISION_REGION;
        }

        // Who found it, in descending order of how much it is worth trusting.
        $provider = (string) $item->geocode_provider;

        if ($provider === 'human') {
            return self::PRECISION_EXACT;
        }

        if ($provider === 'wikidata') {
            // A named venue whose coordinate a person entered and another can
            // correct. Different in kind from a gazetteer's best guess.
            return self::PRECISION_EXACT;
        }

        // How many levels the answer names. The model is asked to write
        // narrow-first with the wider place after it, so this counts precisely
        // what the specificity ladder asks for.
        $parts = count(array_filter(array_map('trim', explode(',', $label))));

        if ($provider === 'nominatim_parent') {
            // The building the venue stands in, rather than the venue. Real,
            // and one level coarser than it looks.
            return $parts >= 3 ? self::PRECISION_APPROX : self::PRECISION_APPROX;
        }

        return match (true) {
            $parts >= 3 => self::PRECISION_EXACT,   // venue, town, state
            $parts === 2 => self::PRECISION_APPROX, // town, state
            default      => self::PRECISION_APPROX, // a town named alone
        };
    }

    /**
     * A number for ranking, derived from precision alone.
     *
     * It used to take the higher of this and the geocoder's importance, which
     * meant a famous place outranked a precise one.
     */
    private function score(string $precision): float
    {
        return match ($precision) {
            self::PRECISION_EXACT   => 0.95,
            self::PRECISION_APPROX  => 0.80,
            self::PRECISION_REGION  => 0.40,
            default                 => 0.10,
        };
    }
}
