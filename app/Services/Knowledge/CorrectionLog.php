<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Every human correction, kept.
 *
 * The removals table records deletions and has never had a row in it, because
 * most corrections are not deletions. A story in the wrong town, filed under
 * the wrong heading, refused when it should have been kept - each of those is a
 * fact about how this job should be done, and each was previously applied once
 * and thrown away.
 *
 * This is the raw material for everything else in the resource centre. The
 * bench is built from confirmed corrections, case studies are drawn from the
 * interesting ones, and rule suggestions come from reading them in bulk. It is
 * the only module that gets more valuable the longer it runs, which is why
 * every day without it is material lost rather than merely delayed.
 */
class CorrectionLog
{
    /** What can be corrected. Each maps to a dimension the bench scores. */
    /**
     * What can be corrected, in the order the form offers it.
     *
     * ⛔ THE ORDER IS THE POINT. "Should not have been published" was first,
     * which made it the default, and five corrections about a wrong location
     * were recorded as requests to delete the story. Location is what people
     * actually correct; deletion is rare and destructive, so it goes last.
     */
    public const FIELDS = [
        'place'    => 'The location is wrong — it happened somewhere else',
        'nowhere'  => 'It should have no location at all',
        'category' => 'The category is wrong',
        'sub'      => 'The sub-category is wrong',
        'refused'  => 'It was refused, but should have been published',
        'keep'     => 'It should not have been published at all',
    ];

    /**
     * Record one correction.
     *
     * The AI's answer is captured as it stands now, before anything is
     * changed - after the fix it is gone, and a correction without the wrong
     * answer beside the right one teaches nothing.
     */
    public function record(
        ?int $newsItemId,
        string $field,
        ?string $aiAnswer,
        ?string $correctAnswer,
        ?string $reason = null,
    ): void {
        if (!isset(self::FIELDS[$field])) {
            return;
        }

        $story = $newsItemId
            ? DB::table('news_items')->where('id', $newsItemId)->first(['title', 'spec_version'])
            : null;

        DB::table('corrections')->insert([
            'news_item_id'   => $newsItemId,
            'title'          => $story ? mb_substr((string) $story->title, 0, 500) : null,
            'field'          => $field,
            'ai_answer'      => $aiAnswer !== null ? mb_substr($aiAnswer, 0, 2000) : null,
            'correct_answer' => $correctAnswer !== null ? mb_substr($correctAnswer, 0, 2000) : null,
            'reason'         => $reason !== null ? mb_substr($reason, 0, 2000) : null,
            'prompt_version' => $story->spec_version ?? null,
            'corrected_by'   => Auth::guard('admin')->id(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /**
     * What has been corrected most, so the next rule is written about the
     * thing that actually goes wrong rather than the thing last noticed.
     *
     * @return array<string, int>
     */
    public function tally(int $days = 90): array
    {
        return DB::table('corrections')
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('field, count(*) AS n')
            ->groupBy('field')
            ->pluck('n', 'field')
            ->all();
    }

    /**
     * Turn a correction into a bench item, so the same mistake is measured
     * from now on rather than merely fixed.
     *
     * Returns false when the story is already on the bench: a second
     * correction to the same story updates the answer rather than adding a
     * duplicate row that would be scored twice.
     */
    public function promoteToBench(int $correctionId): bool
    {
        $correction = DB::table('corrections')->where('id', $correctionId)->first();

        if (!$correction || !$correction->news_item_id) {
            return false;
        }

        $story = DB::table('news_items')->where('id', $correction->news_item_id)->first();

        if (!$story) {
            return false;
        }

        $values = [
            'title'          => mb_substr((string) $story->title, 0, 500),
            'note'           => $correction->reason,
            'confirmed_by'   => $correction->corrected_by,
            'updated_at'     => now(),
        ];

        // Only the dimension actually corrected is asserted. Guessing the
        // others from the current record would bake today's answers into the
        // bench as though a person had confirmed them.
        switch ($correction->field) {
            case 'keep':     $values['expect_keep'] = false; break;
            case 'refused':  $values['expect_keep'] = true; break;
            case 'nowhere':  $values['expect_nowhere'] = true; $values['expect_place'] = null; break;
            case 'place':    $values['expect_place'] = $correction->correct_answer; $values['expect_nowhere'] = false; break;
            case 'category': $values['expect_category'] = $correction->correct_answer; break;
            case 'sub':      $values['expect_sub'] = $correction->correct_answer; break;
        }

        DB::table('bench_items')->updateOrInsert(
            ['news_item_id' => $correction->news_item_id],
            $values + ['created_at' => now()]
        );

        DB::table('corrections')->where('id', $correctionId)->update([
            'used_in_bench' => true,
            'updated_at'    => now(),
        ]);

        return true;
    }
}
