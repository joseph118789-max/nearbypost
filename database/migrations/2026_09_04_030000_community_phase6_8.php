<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Community Reports phases 6-8: conversation, corrections, appeals, following, badges, image checks, moderators. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('notify_mode', 12)->default('daily');      // immediate | daily | none
            $t->string('community_role', 16)->nullable();          // null | moderator
            $t->timestamp('role_granted_at')->nullable();
            $t->unsignedBigInteger('role_granted_by')->nullable();
        });

        Schema::create('community_comments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('parent_id')->nullable()->index();
            $t->text('body');
            $t->string('kind', 16)->default('comment');           // comment | correction (staff) | pinned
            $t->string('status', 16)->default('published');       // published | hidden | removed
            $t->string('moderation_note', 300)->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('community_comment_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('comment_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('device_token', 64)->nullable();
            $t->string('reason', 32);
            $t->string('explanation', 500)->nullable();
            $t->string('status', 16)->default('open');
            $t->timestamps();
        });

        Schema::create('community_corrections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('field', 16);                              // title | body | location | time | event_status | duplicate
            $t->text('proposed_value');
            $t->string('explanation', 1000)->nullable();
            $t->string('evidence_url', 500)->nullable();
            $t->decimal('proposer_weight', 5, 2)->default(1);
            $t->string('status', 16)->default('open');            // open | accepted | rejected
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolved_by_type', 16)->nullable();       // author | moderator | staff | ai
            $t->string('resolution_note', 400)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('community_appeals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('against', 24);                            // rejected | removed | disputed | held
            $t->text('text');
            $t->string('status', 16)->default('open');            // open | upheld | denied
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolution', 400)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('deadline_at')->nullable();
            $t->timestamps();
        });

        Schema::create('area_follows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('label', 120);
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->decimal('radius_km', 5, 1)->default(5);
            $t->string('category', 80)->nullable();               // area + topic
            $t->boolean('paused')->default(false);
            $t->timestamps();
        });

        Schema::create('topic_follows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('category', 80);
            $t->boolean('paused')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'category']);
        });

        Schema::create('community_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('type', 32);
            $t->unsignedBigInteger('news_item_id')->nullable();
            $t->unsignedBigInteger('comment_id')->nullable();
            $t->string('title', 200);
            $t->string('body', 500)->nullable();
            $t->string('url', 300)->nullable();
            $t->string('dedupe_key', 120)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'dedupe_key']);
        });

        Schema::create('user_community_badges', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('badge', 40);
            $t->string('detail', 120)->nullable();
            $t->timestamp('awarded_at')->useCurrent();
            $t->timestamp('revoked_at')->nullable();
            $t->unique(['user_id', 'badge', 'detail']);
        });

        Schema::create('user_blocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('blocker_id');
            $t->unsignedBigInteger('blocked_id');
            $t->string('kind', 8)->default('block');              // block | mute
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['blocker_id', 'blocked_id', 'kind']);
        });

        Schema::create('community_moderator_actions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('news_item_id')->nullable()->index();
            $t->unsignedBigInteger('comment_id')->nullable();
            $t->string('action', 32);
            $t->string('reason_code', 32);
            $t->string('reason', 400);
            $t->boolean('reversed')->default(false);
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('community_post_media', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->unique();
            $t->string('public_path')->nullable();
            $t->string('mime_type', 40)->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('size_bytes')->nullable();
            $t->string('sha256', 64)->nullable()->index();
            $t->string('perceptual_hash', 16)->nullable()->index();
            $t->timestamp('exif_captured_at')->nullable();
            $t->decimal('exif_lat_private', 10, 7)->nullable();
            $t->decimal('exif_lng_private', 10, 7)->nullable();
            $t->string('exif_consistency', 16)->nullable();       // consistent | far | no_exif
            $t->unsignedInteger('exif_distance_m')->nullable();
            $t->unsignedBigInteger('duplicate_of')->nullable();
            $t->string('duplicate_kind', 12)->nullable();         // exact | near
            $t->timestamp('vision_reviewed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['community_post_media', 'community_moderator_actions', 'user_blocks', 'user_community_badges', 'community_notifications',
                  'topic_follows', 'area_follows', 'community_appeals', 'community_corrections', 'community_comment_reports', 'community_comments'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['notify_mode', 'community_role', 'role_granted_at', 'role_granted_by']));
    }
};
