<?php

namespace App\Services\Classification;

/**
 * Spec 2.4 and 2.9 - keep top confidence rare.
 *
 * The specification says relevance 1.0 should apply to at most a tenth of any
 * batch of fifty, and is explicit that this is the backend's job: a model
 * answering one article at a time cannot know what it said about the previous
 * forty-nine.
 *
 * This pipeline enriches one item per call rather than in batches of fifty, so
 * the window is a rolling one held in the cache. The effect is the same - top
 * confidence stays scarce - without pretending to a batch structure the
 * pipeline does not have.
 *
 * The counter is advisory by design. If the cache is cold the slots are simply
 * full, which errs towards allowing a confident answer rather than suppressing
 * a correct one.
 */
class BatchSlots
{
    private const KEY = 'classify:batch_slots';
    private const WINDOW = 50;

    /** Spec 2.4: at most 10% of a window may be relevance 1.0. */
    private const ONE_RATIO = 0.10;

    /** And a further fifth may sit in the 0.8-0.9 band. */
    private const HIGH_RATIO = 0.20;

    /** What is still available in the current window. */
    public function remaining(): array
    {
        $state = $this->state();

        return [
            'relevance_1_slots'  => max(0, (int) floor(self::WINDOW * self::ONE_RATIO) - $state['ones']),
            'relevance_08_slots' => max(0, (int) floor(self::WINDOW * self::HIGH_RATIO) - $state['high']),
        ];
    }

    /** Record what a classification actually used. */
    public function consume(float $primaryRelevance): void
    {
        $state = $this->state();
        $state['count']++;

        if ($primaryRelevance >= 1.0) {
            $state['ones']++;
        } elseif ($primaryRelevance >= 0.8) {
            $state['high']++;
        }

        // A window that has run its length starts again, which is what the
        // spec means by resetting for the next batch of fifty.
        if ($state['count'] >= self::WINDOW) {
            $state = ['count' => 0, 'ones' => 0, 'high' => 0];
        }

        cache()->put(self::KEY, $state, now()->addHours(6));
    }

    private function state(): array
    {
        $state = cache()->get(self::KEY);

        if (!is_array($state) || !isset($state['count'], $state['ones'], $state['high'])) {
            return ['count' => 0, 'ones' => 0, 'high' => 0];
        }

        return $state;
    }
}
