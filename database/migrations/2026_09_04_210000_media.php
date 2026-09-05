<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded file, for everything Marketplace stores. Spec §13.3.
 *
 * ⛔ TWO KINDS OF FILE, AND THEY MUST NOT SHARE A DISK.
 *
 * A listing photograph is meant to be seen. An identity document or a
 * professional certificate is evidence: it exists so a moderator can check one
 * fact, and it must never be reachable by guessing a URL (§13.3, §19.4). So
 * visibility is a column, the private disk is a different filesystem root, and
 * a private row has no public path at all - not an unlisted one, none.
 *
 * ⛔ EXIF GPS IS KEPT, PRIVATELY, AND STRIPPED FROM WHAT IS SERVED. A phone
 * photograph of a home kitchen carries the kitchen's coordinates. The public
 * copy is re-encoded so those coordinates are gone; the original values are
 * recorded here because they are useful evidence when a listing's claimed
 * location is disputed. Publishing them would hand out a home address.
 *
 * ⛔ DELIBERATELY NOT MERGED WITH community_post_media, YET.
 *
 * That table already does the hard parts - sha256, perceptual hash, private
 * EXIF, duplicate detection, vision review - but it is bound to news_item_id
 * and is written by live news code (ImageChecks, ReviewCommunityPhoto). Folding
 * it into this table during Phase 1 would put the news photo pipeline at risk
 * for no Marketplace benefit. The consolidation is its own migration, later,
 * when both shapes have settled. Until then: news photos there, everything
 * else here, and nothing reads both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $t) {
            $t->id();

            // ⛔ The only identifier that ever appears in a URL or an API.
            $t->uuid('public_uuid')->unique();

            // Who uploaded it, and is answerable for having the right to.
            $t->unsignedBigInteger('owner_user_id')->nullable();

            // public  - served from the public disk, EXIF stripped
            // private - evidence, served only through an authorised, expiring link
            $t->string('visibility', 8)->default('private');

            // avatar | provider_primary | provider_cover | listing_image
            //        | credential_document | identity_document | report_evidence
            $t->string('purpose', 32);

            // Which filesystem disk the file is on, so a later move to S3 does
            // not require guessing where old files live.
            $t->string('disk', 20)->default('local');
            $t->string('path', 400);

            $t->string('original_name', 255)->nullable();

            // ⛔ The mime SNIFFED FROM THE CONTENT, never the browser's claim.
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();

            $t->string('sha256', 64)->nullable();
            $t->string('perceptual_hash', 32)->nullable();

            // ⛔ Read from the original, stripped from what is served, kept here.
            $t->timestamp('exif_captured_at')->nullable();
            $t->decimal('exif_lat_private', 10, 7)->nullable();
            $t->decimal('exif_lng_private', 10, 7)->nullable();

            // pending | accepted | rejected | not_required
            $t->string('moderation_status', 16)->default('pending');
            $t->string('moderation_reason', 200)->nullable();
            $t->timestamp('moderated_at')->nullable();

            // Spec §19.2: the uploader must confirm they own or may use it.
            $t->boolean('rights_declared')->default(false);

            $t->timestamps();
            $t->softDeletes();

            $t->index(['owner_user_id', 'purpose'], 'media_owner_purpose_idx');
            $t->index(['visibility', 'moderation_status'], 'media_visibility_moderation_idx');
            $t->index('sha256', 'media_sha_idx');
        });

        // ⛔ A PRIVATE FILE MAY NEVER SIT ON THE PUBLIC DISK. The rule is worth
        // a constraint rather than a code review, because the failure is
        // invisible: the row looks right, the moderator sees the document, and
        // so does anyone who guesses the path.
        DB::statement("ALTER TABLE media
                       ADD CONSTRAINT media_private_never_public_disk
                       CHECK (visibility <> 'private' OR disk <> 'public')");

        // And the reverse, so a listing photo is not quietly written somewhere
        // the web server cannot serve it.
        DB::statement("ALTER TABLE media
                       ADD CONSTRAINT media_public_on_public_disk
                       CHECK (visibility <> 'public' OR disk = 'public')");

        // Documents are private by definition. Naming them here means a new
        // purpose has to think about which side it belongs on.
        DB::statement("ALTER TABLE media
                       ADD CONSTRAINT media_documents_are_private
                       CHECK (purpose NOT IN ('credential_document','identity_document')
                              OR visibility = 'private')");

        // listing_media was created earlier and can now point at real rows.
        Schema::table('listing_media', function (Blueprint $t) {
            $t->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
        });

        Schema::table('provider_profiles', function (Blueprint $t) {
            $t->foreign('primary_media_id')->references('id')->on('media')->nullOnDelete();
            $t->foreign('cover_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $t) {
            $t->dropForeign(['primary_media_id']);
            $t->dropForeign(['cover_media_id']);
        });
        Schema::table('listing_media', fn (Blueprint $t) => $t->dropForeign(['media_id']));
        Schema::dropIfExists('media');
    }
};
