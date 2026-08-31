<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category Classification Spec v2.0 - policy layer.
 *
 * Two things the pipeline could not previously do: refuse an item, and say how
 * sure it was. Everything that arrived was classified, so opinion columns,
 * speculation and clickbait were filed as news alongside reported facts, and a
 * confident classification was indistinguishable from a guess.
 *
 * That gap matters more now than it did last week. Sources are discovered
 * automatically, so publishers reach the feed without a person ever looking at
 * them; spec section 4 is precisely the guard for that case.
 *
 * The primary category weights from spec section 15 are stored here too. They
 * have been sitting in the specification unused - nothing computed
 * Score = Weight x Relevance, so the deliberate ordering of property above
 * religion had no effect on anything. The scoring that uses them follows in the
 * next change; this migration puts the numbers where code can reach them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Primary categories, with the weights from spec section 15 ────
        Schema::create('categories', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('name');
            $table->decimal('weight', 4, 1);
            $table->string('gps', 10);
            $table->timestamps();
        });

        $now = now();

        // [id, name, weight, gps]
        $rows = [
            [1,  'Property & Real Estate', 10.0, 'YES'],
            [2,  'Food & Lifestyle', 9.6, 'YES'],
            [3,  'Infrastructure', 9.3, 'YES'],
            [4,  'Transport & Mobility', 9.1, 'YES'],
            [5,  'Crime & Safety', 8.9, 'YES'],
            [6,  'Environment', 8.7, 'YES'],
            [7,  'Education', 8.5, 'YES'],
            [8,  'Health', 8.1, 'BOTH'],
            [9,  'Travel', 8.0, 'BOTH'],
            [10, 'Entertainment / Arts & Culture', 7.9, 'BOTH'],
            [11, 'Charity & Nonprofits', 7.8, 'BOTH'],
            [12, 'Weather', 7.7, 'BOTH'],
            [13, 'Defense & Military', 7.5, 'BOTH'],
            [14, 'Markets & Finance', 7.3, 'NO'],
            [15, 'Business & Corporate', 7.1, 'NO'],
            [16, 'Technology & Digital', 6.9, 'NO'],
            [17, 'Automotive', 6.7, 'NO'],
            [18, 'Government & Policy', 6.5, 'NO'],
            [19, 'Science', 6.3, 'NO'],
            [20, 'Sports', 6.1, 'NO'],
            [21, 'Religion', 5.9, 'NO'],
        ];

        $insert = [];

        foreach ($rows as [$id, $name, $weight, $gps]) {
            $insert[] = [
                'id'         => $id,
                'name'       => $name,
                'weight'     => $weight,
                'gps'        => $gps,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('categories')->insert($insert);

        // ── Per-item classification outcome ──────────────────────────────
        Schema::table('news_items', function (Blueprint $table) {
            // Spec section 8: an item can now be refused rather than filed.
            $table->boolean('discarded')->default(false)->after('sub_category');
            $table->string('error_code', 40)->nullable()->after('discarded');

            // Spec section 11: how sure the system is of its own answer, which
            // is a different question from how relevant the content is.
            $table->decimal('meta_confidence', 3, 2)->nullable()->after('error_code');

            // Spec section 2.8 / 3 / 2.5: the flags behind that confidence.
            $table->boolean('gps_flag')->default(false)->after('meta_confidence');
            $table->boolean('url_used')->default(false)->after('gps_flag');
            $table->boolean('ambiguous')->default(false)->after('url_used');

            // The full spec output, kept whole so a decision can be re-read
            // later rather than inferred from the columns it happened to fill.
            $table->json('classification')->nullable()->after('ambiguous');
            $table->string('spec_version', 12)->nullable()->after('classification');

            $table->index(['discarded', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['discarded', 'published_at']);
            $table->dropColumn([
                'discarded', 'error_code', 'meta_confidence',
                'gps_flag', 'url_used', 'ambiguous', 'classification', 'spec_version',
            ]);
        });

        Schema::dropIfExists('categories');
    }
};
