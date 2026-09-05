<?php

namespace App\Services\Contribution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The newsroom's own rules, in a form a model can be given.
 *
 * Read on every review, so cached briefly - editorial policy changes on a human
 * timescale, and a minute of staleness after someone saves a rule is a fair
 * trade for not querying this on every submission.
 *
 * Cached under a version that changes whenever a rule is saved, so an edit
 * takes effect on the next submission rather than up to a minute later. The
 * newsroom should be able to add a rule and immediately test it.
 */
class ReviewRules
{
    private const TTL_SECONDS = 60;

    /**
     * The active rules for one kind of submission, as a numbered list.
     *
     * Returns an empty string when there are none, so the prompt simply does
     * not gain a rules section rather than gaining an empty one - an empty
     * heading reads to a model as "there are no rules", which is not the same
     * as saying nothing.
     */
    public function promptBlock(string $appliesTo, ?string $country = null): string
    {
        $rules = $this->active($appliesTo, $country);

        if ($rules === []) {
            return '';
        }

        $lines = [];

        foreach ($rules as $i => $rule) {
            $lines[] = ($i + 1) . '. ' . $rule;
        }

        return "HOUSE RULES - these are set by this newsroom and override your own\n"
             . "judgement about what is acceptable. Refuse anything that breaks one,\n"
             . "and say which rule it broke in your reason so the writer can fix it:\n\n"
             . implode("\n", $lines) . "\n";
    }

    /** @return list<string> */
    public function active(string $appliesTo, ?string $country = null): array
    {
        $country = $country === null ? null : strtoupper($country);
        $key = 'review_rules:' . $appliesTo . ':' . ($country ?? 'base') . ':' . $this->version();

        return Cache::remember($key, self::TTL_SECONDS, function () use ($appliesTo, $country) {
            // the base rules (no country) and, when a country is given, that country's own rules too
            return DB::table('review_rules')
                ->where('is_active', true)
                ->whereIn('applies_to', [$appliesTo, 'both'])
                ->where(fn ($q) => $country === null ? $q->whereNull('country') : $q->whereNull('country')->orWhere('country', $country))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('rule')
                ->map(fn ($r) => trim((string) $r))
                ->filter()
                ->values()
                ->all();
        });
    }

    /** Bumped whenever a rule is saved, so an edit is live immediately. */
    public function version(): string
    {
        return (string) Cache::get('review_rules:version', '1');
    }

    public function bumpVersion(): void
    {
        Cache::forever('review_rules:version', (string) (time()));
    }
}
