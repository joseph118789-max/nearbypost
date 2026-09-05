<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Send us your news" - a publisher offering their own site to NearbyPost.
 *
 * The owner, 5 Sep 2026: *"i want contributor to have option to share their
 * resources with us to post"*. Free exposure for them, more local news for the
 * reader, and no scraping of anyone who has not asked for it.
 *
 * ⛔ A REQUEST IS NOT A SOURCE. Nothing here switches anything on. A row lands,
 * the site is probed automatically, a person reads the result and decides. That
 * order matters: this form is a public endpoint, and a public endpoint that
 * could add a live source would be a way to make NearbyPost fetch anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_uuid')->unique();

            /* ------------------------------------------- what they told us */

            $t->string('website_url', 500);
            $t->string('site_name', 160)->nullable();
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_email', 160)->nullable();

            // Their own words about what they publish. The AI reads this
            // alongside what the probe actually found - a claim and evidence.
            $t->text('describes')->nullable();

            // Facebook, Instagram, TikTok, YouTube... stored as given.
            $t->jsonb('social_links')->nullable();

            // ⛔ They must state they may speak for the site. Not a checkbox we
            // can enforce, but a recorded answer, and the reason a takedown
            // request can be answered honestly.
            $t->boolean('speaks_for_site')->default(false);

            /* --------------------------------------- what the probe found */

            // rss | events_api | index | page_events | mall_listing | none
            $t->string('found_kind', 24)->nullable();
            $t->string('found_url', 500)->nullable();

            // Everything the probe saw, so a decision can be re-read later.
            $t->jsonb('probe')->nullable();
            $t->timestamp('probed_at')->nullable();

            // How often the site actually publishes, measured from its own
            // dates - not asked, and not guessed.
            $t->decimal('items_per_week', 8, 2)->nullable();

            /* ------------------------------------------ what we decided */

            // daily | weekly - default weekly, per the owner.
            $t->string('recommended_cadence', 10)->nullable();
            $t->text('ai_verdict')->nullable();
            $t->string('ai_decision', 24)->nullable();

            // new | probing | reviewed | approved | declined | duplicate
            $t->string('status', 16)->default('new');
            $t->text('decline_reason')->nullable();

            $t->unsignedBigInteger('created_source_id')->nullable();
            $t->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();

            // Rate limiting and abuse only; never shown, never joined to a person.
            $t->char('submitter_bucket', 64)->nullable();

            $t->timestamps();

            $t->index(['status', 'created_at'], 'source_request_queue');
            $t->index(['submitter_bucket', 'created_at'], 'source_request_bucket');
        });

        // ⛔ One live request per site. Somebody submitting three times because
        // nothing appeared to happen should not create three rows for a
        // moderator to read - and re-submitting after a decline should not
        // quietly reopen it either.
        DB::statement("CREATE UNIQUE INDEX source_request_one_open_per_site
                       ON source_requests (lower(website_url))
                       WHERE status IN ('new','probing','reviewed')");

        DB::statement("ALTER TABLE source_requests
                       ADD CONSTRAINT source_request_status
                       CHECK (status IN ('new','probing','reviewed','approved','declined','duplicate'))");

        DB::statement("ALTER TABLE source_requests
                       ADD CONSTRAINT source_request_cadence
                       CHECK (recommended_cadence IS NULL OR recommended_cadence IN ('daily','weekly'))");

        // ⛔ A decline must say why. "Declined by somebody, at some point, for
        // some reason" is a state nobody can answer a follow-up email about.
        DB::statement("ALTER TABLE source_requests
                       ADD CONSTRAINT source_request_decline_has_a_reason
                       CHECK (status <> 'declined' OR btrim(coalesce(decline_reason,'')) <> '')");
    }

    public function down(): void
    {
        Schema::dropIfExists('source_requests');
    }
};
