<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every item a connected publisher sends is read before it is shown.
 *
 * ⛔⛔ APPROVING A SOURCE IS NOT APPROVING ITS CONTENT, EVER AGAIN.
 *
 * A publisher is approved once, on the strength of what they were publishing
 * that week. Afterwards they can post anything - a run of advertisements, an
 * unrelated national story, a defamatory claim about a neighbour, a site that
 * changed hands. The judgement that let them in cannot cover work that did not
 * exist when it was made.
 *
 * ⛔ THE GATE FAILS CLOSED. An item from a connected publisher is 'pending'
 * until something says otherwise, and populate-feed will not serve a pending
 * one. If the vetting command stops running, connected publishers stop
 * appearing - which is the safe direction. The alternative, publishing while
 * unvetted, would make the whole check decorative the first time a queue backed
 * up.
 *
 * Ordinary scraped sources are untouched: they already pass through the
 * classifier, which discards what is not news. This is the extra gate for
 * content somebody asked us to carry, where the reputational claim is theirs
 * and the responsibility for showing it is ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $t) {
            // not_required | pending | passed | held | rejected
            //
            // 'not_required' is the default so that the millions of ordinary
            // scraped rows are unaffected and no backfill is needed; only items
            // from contributed sources are moved to 'pending'.
            $t->string('publisher_review_status', 16)->default('not_required');
            $t->string('publisher_review_reason', 300)->nullable();
            $t->timestamp('publisher_reviewed_at')->nullable();
            $t->string('publisher_review_model', 60)->nullable();
        });

        DB::statement("ALTER TABLE news_items
                       ADD CONSTRAINT news_publisher_review_status
                       CHECK (publisher_review_status IN
                              ('not_required','pending','passed','held','rejected'))");

        // ⛔ A refusal must say why. The publisher will ask, and "the model said
        // no" is not an answer anybody can act on.
        DB::statement("ALTER TABLE news_items
                       ADD CONSTRAINT news_publisher_refusal_has_a_reason
                       CHECK (publisher_review_status NOT IN ('held','rejected')
                              OR btrim(coalesce(publisher_review_reason,'')) <> '')");

        // The queue this creates: everything waiting, oldest first.
        DB::statement("CREATE INDEX news_items_publisher_queue
                       ON news_items (publisher_review_status, id)
                       WHERE publisher_review_status IN ('pending','held')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS news_items_publisher_queue');

        Schema::table('news_items', fn (Blueprint $t) => $t->dropColumn([
            'publisher_review_status', 'publisher_review_reason',
            'publisher_reviewed_at', 'publisher_review_model',
        ]));
    }
};
