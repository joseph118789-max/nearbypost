<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The five things worth taking from the publisher-connections spec.
 *
 * ⛔ A CHECKBOX IS NOT PROOF OF OWNERSHIP. The form asks somebody to confirm
 * they speak for a site, and that is a recorded answer, not evidence. Anyone
 * can tick it about anyone else's website. Verification proves control of the
 * domain, which is the only claim we can actually check.
 *
 * ⛔ AND CONSENT WITHOUT SCOPE IS NOT CONSENT. "You may show my content" says
 * nothing about whether an image may be used or only a headline. Recording what
 * was agreed, and when, is also what makes revocation mean something: without a
 * scope there is nothing to withdraw.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------ 1. verification */

        Schema::table('source_requests', function (Blueprint $t) {
            // Given to the publisher; they put it on their site, we look for it.
            $t->string('verify_token', 48)->nullable();

            // meta | dns | file
            $t->string('verify_method', 10)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verify_error', 200)->nullable();
            $t->unsignedSmallInteger('verify_attempts')->default(0);

            /* --------------------------------- 2. what they agreed we may show */

            $t->boolean('allow_headline')->default(true);
            $t->boolean('allow_excerpt')->default(true);
            $t->boolean('allow_ai_summary')->default(true);
            $t->boolean('allow_image')->default(false);
            $t->string('consent_version', 12)->nullable();
            $t->timestamp('consented_at')->nullable();
        });

        // The same scope, carried onto the source it becomes, because that is
        // what the serving code has in front of it.
        Schema::table('sources', function (Blueprint $t) {
            $t->boolean('allow_headline')->default(true);
            $t->boolean('allow_excerpt')->default(true);
            $t->boolean('allow_ai_summary')->default(true);
            $t->boolean('allow_image')->default(false);
            $t->string('consent_version', 12)->nullable();
            $t->timestamp('consented_at')->nullable();

            // ⛔ Set when a publisher withdraws. Nothing is fetched after this,
            // and what is already served is taken down - see SourcePermissions.
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason', 300)->nullable();

            // A token the publisher can use to withdraw without asking us.
            $t->string('revoke_token', 48)->nullable();

            /* ---------------------------------------- 5. quiet-source decay */

            // Fetches in a row that brought back nothing new.
            $t->unsignedSmallInteger('consecutive_empty')->default(0);
            $t->timestamp('downgraded_at')->nullable();
        });

        DB::statement("ALTER TABLE source_requests
                       ADD CONSTRAINT source_request_verify_method
                       CHECK (verify_method IS NULL OR verify_method IN ('meta','dns','file'))");

        // ⛔ A verified row must say HOW. "Verified somehow" cannot be audited
        // and cannot be re-checked when a domain changes hands.
        DB::statement('ALTER TABLE source_requests
                       ADD CONSTRAINT source_request_verified_has_a_method
                       CHECK (verified_at IS NULL OR verify_method IS NOT NULL)');

        DB::statement("ALTER TABLE sources
                       ADD CONSTRAINT source_revoked_has_a_reason
                       CHECK (revoked_at IS NULL OR btrim(coalesce(revoked_reason,'')) <> '')");

        /* --------------------------------- 4. the publisher's own item id */

        Schema::table('news_items', function (Blueprint $t) {
            // A feed's <guid>, an API's id. Stable when a URL is not.
            $t->string('external_id', 200)->nullable();
        });

        /**
         * ⛔ ONE ITEM PER PUBLISHER, BY THEIR ID - NOT BY URL.
         *
         * URL de-duplication is right until a publisher changes a slug, appends
         * a campaign parameter, or serves the same story from two paths - and
         * then the same story arrives twice under two addresses. Their own id
         * does not move. Partial, because most stories have no external id and
         * NULLs must not collide.
         *
         * Scoped by source name because news_items has no source id: two
         * publishers may legitimately use id "12345".
         */
        DB::statement('CREATE UNIQUE INDEX news_items_one_per_publisher_item
                       ON news_items (source, external_id)
                       WHERE external_id IS NOT NULL AND source IS NOT NULL');

        /* ----------------------------------------------------- 3. badges */

        // origin already carries scraper | user | editorial. 'publisher' is the
        // fourth: a site that asked to be here, which a reader should be able
        // to tell apart from both a newsroom and a neighbour.
        DB::statement("COMMENT ON COLUMN news_items.origin IS
                       'scraper (we found it) | publisher (they asked us to carry it) | user (a reader saw it) | editorial (us)'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS news_items_one_per_publisher_item');

        Schema::table('news_items', fn (Blueprint $t) => $t->dropColumn('external_id'));

        Schema::table('sources', fn (Blueprint $t) => $t->dropColumn([
            'allow_headline', 'allow_excerpt', 'allow_ai_summary', 'allow_image',
            'consent_version', 'consented_at', 'revoked_at', 'revoked_reason',
            'revoke_token', 'consecutive_empty', 'downgraded_at',
        ]));

        Schema::table('source_requests', fn (Blueprint $t) => $t->dropColumn([
            'verify_token', 'verify_method', 'verified_at', 'verify_error', 'verify_attempts',
            'allow_headline', 'allow_excerpt', 'allow_ai_summary', 'allow_image',
            'consent_version', 'consented_at',
        ]));
    }
};
