<?php

namespace App\Services\Classification;

use Illuminate\Support\Facades\DB;

/**
 * Spec v2.0 sections 6, 9, 10, 13 and 15 - the deterministic part.
 *
 * The model supplies relevance; this class supplies the arithmetic. That split
 * is the whole point of the specification. If the model were asked to pick a
 * category directly - which is what happened before - the weights in section 15
 * would be decoration, the ordering of property above religion would mean
 * nothing, and no decision could be explained afterwards beyond "the model said
 * so". Here the choice is Score = Weight x Relevance, computed in code, with the
 * inputs recorded.
 *
 * Everything the spec asks for lands somewhere concrete:
 *   section 6   relevance distribution rules, applied as a ceiling
 *   section 9   primary selection, tie-breaking, secondary rules
 *   section 10  sub-category scoring and the ID range check
 *   section 13  post-processing validation, which decides whether to retry
 *   section 15  the weights, read from the categories table
 */
class CategoryScorer
{
    /** Spec 2.5: top two within this fraction of each other means ambiguous. */
    private const AMBIGUITY_MARGIN = 0.15;

    /** Spec 9: a secondary must sit at least this far below the primary. */
    private const SECONDARY_GAP = 0.2;

    /** Spec 9: and must reach at least this relevance to qualify at all. */
    private const SECONDARY_MIN = 0.4;

    /** Spec 10: below this, the sub-category is "Others". */
    private const SUBCATEGORY_MIN = 0.5;

    private static ?array $categories = null;
    private static ?array $subcategories = null;

    /** id => ['name' => ..., 'weight' => ..., 'gps' => ...] */
    public function categories(): array
    {
        if (self::$categories === null) {
            self::$categories = DB::table('categories')
                ->orderBy('id')
                ->get()
                ->keyBy('id')
                ->map(fn ($row) => [
                    'name'   => $row->name,
                    'weight' => (float) $row->weight,
                    'gps'    => $row->gps,
                ])
                ->all();
        }

        return self::$categories;
    }

    /** id => ['primary' => ..., 'name' => ..., 'weight' => ..., 'gps' => ...] */
    public function subcategories(): array
    {
        if (self::$subcategories === null) {
            self::$subcategories = DB::table('subcategories')
                ->orderBy('id')
                ->get()
                ->keyBy('id')
                ->map(fn ($row) => [
                    'primary' => $row->primary_category,
                    'name'    => $row->sub_category,
                    'weight'  => (float) $row->weight,
                    'gps'     => $row->gps,
                ])
                ->all();
        }

        return self::$subcategories;
    }

    /**
     * The category list as the prompt sees it: id first, because the model
     * answers with ids and never with names. Names in the answer would have to
     * be matched back, and a near-miss would silently become a different
     * category.
     */
    public function promptCategories(): string
    {
        $lines = [];

        foreach ($this->categories() as $id => $row) {
            $lines[] = $id . ': ' . $row['name'];
        }

        return implode("\n", $lines);
    }

    /**
     * Turn the model's relevance judgements into a decision.
     *
     * @param array $relevance   category id => relevance (0..1)
     * @param array $subRelevance subcategory id => relevance (0..1)
     * @param array $context     ['gps' => bool, 'cap' => float, 'slots' => array]
     *
     * @return array the spec's production output plus the names behind it
     */
    public function score(array $relevance, array $subRelevance, array $context = []): array
    {
        $categories = $this->categories();
        $gps        = (bool) ($context['gps'] ?? false);
        $cap        = (float) ($context['cap'] ?? 1.0);

        $relevance = $this->applyCeilings($relevance, $gps, $cap, $context);

        // Spec 9 step 11: Score = Weight x Relevance, rounded to 2 decimals.
        $scored = [];

        foreach ($relevance as $id => $value) {
            if (!isset($categories[$id]) || $value <= 0) {
                continue;
            }

            $scored[$id] = round($categories[$id]['weight'] * $value, 2);
        }

        if ($scored === []) {
            return ['valid' => false, 'reason' => 'no_scoring_categories'];
        }

        $ranked  = $this->rank($scored, $relevance);
        $primary = $ranked[0];

        // Spec 2.5 / 9 step 13: two categories too close to separate.
        $ambiguous = false;

        if (isset($ranked[1]) && $primary['score'] > 0) {
            $margin = ($primary['score'] - $ranked[1]['score']) / $primary['score'];

            if ($margin <= self::AMBIGUITY_MARGIN) {
                $ambiguous = true;
                $relevance[$primary['id']] = min($relevance[$primary['id']], 0.7);
                $primary['relevance'] = $relevance[$primary['id']];
                $primary['score'] = round($categories[$primary['id']]['weight'] * $primary['relevance'], 2);
            }
        }

        $secondary = $this->selectSecondary($ranked, $primary);
        $sub       = $this->selectSubCategory($subRelevance, $categories[$primary['id']]['name'], $gps);

        return [
            'valid'     => true,
            'ambiguous' => $ambiguous,
            'primary'   => $primary,
            'secondary' => $secondary,
            'sub'       => $sub,
            'scores'    => $scored,
        ];
    }

    /**
     * Spec 6 and 7: the ceilings that stop a confident label being attached to
     * thin content, and the GPS influence on which categories may lead.
     */
    private function applyCeilings(array $relevance, bool $gps, float $cap, array $context): array
    {
        $categories = $this->categories();
        $out = [];

        foreach ($relevance as $id => $value) {
            $id    = (int) $id;
            $value = max(0.0, min(1.0, (float) $value));

            if (!isset($categories[$id])) {
                continue;
            }

            // Spec 3.6 / 2.7: content-derived ceiling.
            $value = min($value, $cap);

            // Spec 7: a category that depends on a place cannot lead when there
            // is no usable place, and vice versa.
            $categoryGps = $categories[$id]['gps'];

            if (!$gps && $categoryGps === 'YES') {
                $value = min($value, 0.6);
            }

            if ($gps && $categoryGps === 'NO') {
                $value = min($value, 0.6);
            }

            $out[$id] = round($value, 2);
        }

        // Spec 2.9: the batch may have run out of room at this relevance.
        $slots = $context['slots'] ?? null;

        if (is_array($slots)) {
            $out = $this->applyBatchSlots($out, $slots);
        }

        return $out;
    }

    /**
     * Spec 2.9: relevance 1.0 is meant to be rare - at most a tenth of a batch.
     * The model cannot track that across calls, so the backend holds the count
     * and lowers anything that would exceed it.
     */
    private function applyBatchSlots(array $relevance, array $slots): array
    {
        $onesLeft = (int) ($slots['relevance_1_slots'] ?? 99);
        $highLeft = (int) ($slots['relevance_08_slots'] ?? 99);

        arsort($relevance);

        foreach ($relevance as $id => $value) {
            if ($value >= 1.0) {
                if ($onesLeft > 0) {
                    $onesLeft--;
                } else {
                    $relevance[$id] = 0.9;
                    $value = 0.9;
                }
            }

            if ($value >= 0.8 && $value < 1.0) {
                if ($highLeft > 0) {
                    $highLeft--;
                } else {
                    $relevance[$id] = 0.7;
                }
            }
        }

        return $relevance;
    }

    /**
     * Spec 9: highest score wins. Ties break on weight, then relevance, then
     * position in the weight table - which is why the id is the final key.
     */
    private function rank(array $scored, array $relevance): array
    {
        $categories = $this->categories();
        $rows = [];

        foreach ($scored as $id => $score) {
            $rows[] = [
                'id'        => (int) $id,
                'name'      => $categories[$id]['name'],
                'relevance' => (float) ($relevance[$id] ?? 0),
                'score'     => $score,
                'weight'    => $categories[$id]['weight'],
            ];
        }

        usort($rows, function ($a, $b) {
            return [$b['score'], $b['weight'], $b['relevance'], -$a['id']]
               <=> [$a['score'], $a['weight'], $a['relevance'], -$b['id']];
        });

        return $rows;
    }

    /**
     * Spec 9: a secondary category is the strongest competitor, but only if it
     * is genuinely close behind and genuinely distinct.
     */
    private function selectSecondary(array $ranked, array $primary): ?array
    {
        foreach ($ranked as $row) {
            if ($row['id'] === $primary['id']) {
                continue;
            }

            if ($row['relevance'] < self::SECONDARY_MIN) {
                continue;
            }

            if (($primary['relevance'] - $row['relevance']) < self::SECONDARY_GAP) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * Spec 10: score the sub-categories, keep only those belonging to the
     * chosen primary, and fall back to that primary's "Others".
     */
    private function selectSubCategory(array $subRelevance, string $primaryName, bool $gps): array
    {
        $subcategories = $this->subcategories();
        $best = null;

        foreach ($subRelevance as $rawId => $value) {
            // Ids arrive as S124. A bare number is still accepted, since an
            // answer that gets it right the old way should not be discarded.
            $id = (int) ltrim((string) $rawId, 'Ss');

            if (!isset($subcategories[$id])) {
                continue;
            }

            $row = $subcategories[$id];

            // Spec 10: out of range for this primary is discarded, not coerced.
            if (mb_strtolower($row['primary']) !== mb_strtolower($primaryName)) {
                continue;
            }

            $value = max(0.0, min(1.0, (float) $value));

            // Spec 10: GPS influence on sub-categories.
            if (!$gps && $row['gps'] === 'YES') {
                $value = min($value, 0.6);
            }

            if ($value < self::SUBCATEGORY_MIN) {
                continue;
            }

            $score = round($row['weight'] * $value, 2);

            if ($best === null || $score > $best['score']
                || ($score === $best['score'] && $row['weight'] > $best['weight'])) {
                $best = [
                    'id'        => $id,
                    'name'      => $row['name'],
                    'relevance' => round($value, 2),
                    'score'     => $score,
                    'weight'    => $row['weight'],
                ];
            }
        }

        if ($best !== null) {
            return $best;
        }

        return $this->othersFor($primaryName);
    }

    /** Every primary has an "Others"; Religion has a single member instead. */
    private function othersFor(string $primaryName): array
    {
        $fallback = null;

        foreach ($this->subcategories() as $id => $row) {
            if (mb_strtolower($row['primary']) !== mb_strtolower($primaryName)) {
                continue;
            }

            $fallback ??= ['id' => $id, 'name' => $row['name'], 'weight' => $row['weight']];

            if (mb_strtolower($row['name']) === 'others') {
                $fallback = ['id' => $id, 'name' => $row['name'], 'weight' => $row['weight']];
                break;
            }
        }

        if ($fallback === null) {
            return ['id' => null, 'name' => null, 'relevance' => 0.0, 'score' => 0.0];
        }

        return [
            'id'        => $fallback['id'],
            'name'      => $fallback['name'],
            'relevance' => 0.0,
            'score'     => 0.0,
            'weight'    => $fallback['weight'],
        ];
    }

    /**
     * Spec 13: the checks that decide whether an answer is usable.
     * Returns a list of failures; empty means valid.
     */
    public function validate(array $output): array
    {
        $errors = [];

        foreach (['g', 'd', 'a', 'b', 'u', 'c', 'e', 'p', 's', 'sc'] as $field) {
            if (!array_key_exists($field, $output)) {
                $errors[] = "missing_field:{$field}";
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($output['d'] === 1) {
            foreach (['p', 's', 'sc'] as $field) {
                if ($output[$field] !== null) {
                    $errors[] = "discard_requires_null:{$field}";
                }
            }

            if ((float) $output['c'] !== 0.0) {
                $errors[] = 'discard_requires_zero_confidence';
            }

            return $errors;
        }

        if (!is_array($output['p'])) {
            $errors[] = 'missing_primary';
            return $errors;
        }

        [$primaryId, $primaryRelevance, $primaryScore] = $output['p'];

        if ($output['a'] === 1 && $primaryRelevance > 0.7) {
            $errors[] = 'ambiguous_relevance_too_high';
        }

        if ($output['u'] === 0 && $primaryRelevance > 0.7) {
            $errors[] = 'title_only_relevance_too_high';
        }

        if ((float) $output['c'] < 0.3 || (float) $output['c'] > 1.0) {
            $errors[] = 'confidence_out_of_range';
        }

        $categories = $this->categories();

        if (!isset($categories[$primaryId])) {
            $errors[] = 'unknown_primary';
        } else {
            $expected = round($categories[$primaryId]['weight'] * $primaryRelevance, 2);

            if (abs($expected - $primaryScore) > 0.01) {
                $errors[] = 'primary_score_mismatch';
            }
        }

        if (is_array($output['sc']) && $output['sc'][0] !== null) {
            $subcategories = $this->subcategories();
            $subId = $output['sc'][0];

            if (!isset($subcategories[$subId])) {
                $errors[] = 'unknown_subcategory';
            } elseif (isset($categories[$primaryId])
                && mb_strtolower($subcategories[$subId]['primary']) !== mb_strtolower($categories[$primaryId]['name'])) {
                $errors[] = 'subcategory_out_of_range';
            }
        }

        if (is_array($output['s']) && $output['s'][0] === $primaryId) {
            $errors[] = 'secondary_equals_primary';
        }

        return $errors;
    }
}
