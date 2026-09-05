<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ratings and replies. Spec §16, §20.15, §20.16.
 *
 * ⛔ THE CONTEXT IS PART OF THE KEY, AND THAT IS THE WHOLE DESIGN.
 *
 * Spec §3.3 forbids merging a community reporter's credibility, a provider's
 * rating, a seller's rating, a car-pool driver's rating and a passenger's
 * rating into one number. The way to make that impossible rather than merely
 * discouraged is to give every review a `context` and never provide a query
 * that sums across contexts. There is no `users.rating` column here and there
 * must never be one: the same person may be a careful driver and a slow cook,
 * and a single figure would tell a reader neither thing.
 *
 * ⛔ AND A REVIEW IS NOT A PURCHASE RECORD. Spec §16.4: the label is "community
 * ratings", not "verified purchases", because NearbyPost never sees the
 * transaction - it happens on WhatsApp between two people. Any wording that
 * implies otherwise is a claim the site cannot support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();
            $t->unsignedBigInteger('reviewer_user_id');

            // provider | listing | professional | carpool_driver | carpool_passenger | individual_seller
            $t->string('target_type', 24);
            $t->unsignedBigInteger('target_id');

            // ⛔ Which relationship is being rated. Two people may meet twice -
            // once as buyer and seller, once as driver and passenger - and each
            // deserves its own rating rather than an average of the two.
            $t->string('context', 24);

            $t->unsignedTinyInteger('overall_rating');          // 1..5

            // communication, advertisement accuracy, service, value - and
            // whatever a category configures instead (§16.3).
            $t->jsonb('dimension_ratings')->nullable();

            $t->text('body')->nullable();

            // published | hidden | removed
            $t->string('status', 12)->default('published');

            // not_checked | ai_accepted | ai_rejected | manual_review | manual_accepted | manual_rejected
            $t->string('moderation_status', 20)->default('not_checked');

            // ⛔ The reviewer may edit; the history is kept internally so an
            // accusation cannot be quietly rewritten after a provider replies
            // to it (§16.1).
            $t->jsonb('revisions')->nullable();
            $t->timestamp('edited_at')->nullable();

            // One abuse signal among several. Spec §16.1 is explicit that
            // one-IP-one-rating must NOT be the rule: households and offices
            // share an address and an attacker simply changes theirs.
            $t->string('ip_hash', 64)->nullable();
            $t->string('device_token', 64)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['target_type', 'target_id', 'context', 'status'], 'reviews_target_idx');
            $t->index(['reviewer_user_id', 'created_at'], 'reviews_reviewer_idx');
        });

        // ⛔ ONE LIVE REVIEW PER PERSON PER THING PER CONTEXT. Spec §16.1.
        // Partial, so a removed review does not block the reviewer from writing
        // a fresh one after a genuine second experience.
        DB::statement("CREATE UNIQUE INDEX reviews_one_live_per_target
                       ON reviews (reviewer_user_id, target_type, target_id, context)
                       WHERE status = 'published' AND deleted_at IS NULL");

        DB::statement('ALTER TABLE reviews
                       ADD CONSTRAINT reviews_rating_in_range
                       CHECK (overall_rating BETWEEN 1 AND 5)');

        /**
         * A provider's reply. Spec §16.1, §20.16.
         *
         * ⛔ A REPLY IS A SEPARATE ROW, NEVER AN EDIT. "Merchants may reply but
         * cannot edit or remove user reviews." Storing the reply anywhere
         * inside the review would put a provider's words in the same record as
         * their customer's, and one day something would overwrite the other.
         */
        Schema::create('review_replies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('review_id');
            $t->unsignedBigInteger('author_user_id');
            $t->unsignedBigInteger('provider_profile_id')->nullable();
            $t->text('body');
            $t->string('status', 12)->default('published');
            $t->timestamps();

            // One reply per review. A provider who wants to say more edits
            // theirs; a thread would turn a rating into an argument.
            $t->unique('review_id', 'review_replies_one_per_review');

            $t->foreign('review_id')->references('id')->on('reviews')->cascadeOnDelete();
        });

        /**
         * The published summary, recomputed rather than counted on every page.
         *
         * ⛔ IT STORES THE COUNT BESIDE THE AVERAGE, ALWAYS. Spec §16.4: "4.8
         * from 38 community ratings". An average with no count is the thing
         * that lets one five-star review outrank a hundred good ones, and §15.3
         * forbids sorting by it.
         */
        Schema::create('rating_summaries', function (Blueprint $t) {
            $t->id();
            $t->string('target_type', 24);
            $t->unsignedBigInteger('target_id');
            $t->string('context', 24);

            $t->unsignedInteger('review_count')->default(0);
            $t->decimal('average_rating', 3, 2)->nullable();

            // The count-aware figure ranking uses. Never shown to a reader:
            // it is a sorting key, not a score anybody earned.
            $t->decimal('weighted_rating', 4, 3)->nullable();

            $t->jsonb('dimension_averages')->nullable();
            $t->timestamp('recalculated_at')->nullable();
            $t->timestamps();

            $t->unique(['target_type', 'target_id', 'context'], 'rating_summary_unique');
            $t->index(['target_type', 'weighted_rating'], 'rating_summary_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_summaries');
        Schema::dropIfExists('review_replies');
        Schema::dropIfExists('reviews');
    }
};
