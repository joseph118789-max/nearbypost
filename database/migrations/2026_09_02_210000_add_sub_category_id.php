<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point at the sub-category instead of copying its name.
 *
 * Renaming "Motorsports" to "Racing" meant updating four tables by hand, because
 * every one of them held the word rather than a reference. Nothing in the schema
 * said they had to agree, and the one most easily missed was the bench - which
 * would then have marked the model wrong for giving the new answer.
 *
 * The name stays. It is read by the public pages, the API and the feed filters,
 * and replacing all of that on a live site would be a large change for a small
 * gain. Instead the id becomes the truth and the name becomes a copy of it:
 * `taxonomy:rename` changes the shelf once and refreshes every copy by id.
 *
 * So the name may still be read anywhere. It just is not what anything means.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['news_items' => 'sub_category', 'feed_ready_items' => 'sub_category'] as $table => $after) {
            Schema::table($table, function (Blueprint $t) use ($after) {
                $t->unsignedBigInteger('sub_category_id')->nullable()->after($after);
                $t->index('sub_category_id');
            });
        }

        Schema::table('bench_items', function (Blueprint $t) {
            $t->unsignedBigInteger('expect_sub_id')->nullable()->after('expect_sub');
            $t->index('expect_sub_id');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', fn (Blueprint $t) => $t->dropColumn('sub_category_id'));
        Schema::table('feed_ready_items', fn (Blueprint $t) => $t->dropColumn('sub_category_id'));
        Schema::table('bench_items', fn (Blueprint $t) => $t->dropColumn('expect_sub_id'));
    }
};
