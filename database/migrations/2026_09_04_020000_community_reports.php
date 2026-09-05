<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Community Reports (owner's guide, 3 Sep 2026). Additive only. A community
 * report IS a reader story in news_items (origin = 'user'); these tables
 * carry what the guide adds: the immutable original, the pin's provenance,
 * trust and moderation state, reactions, reports, and the reputation ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('username', 40)->nullable()->after('name');
            $t->string('display_name', 120)->nullable()->after('username');
            $t->string('bio', 500)->nullable();
            $t->string('area', 120)->nullable();
            $t->string('avatar_path')->nullable();
            $t->integer('points')->default(0);
            $t->integer('credibility')->default(50);
        });
        if (DB::getDriverName() === 'pgsql') { DB::statement('create unique index users_username_lower_unique on users (lower(username)) where username is not null'); }

        Schema::create('community_post_meta', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->unique();
            $t->text('original_title');
            $t->text('original_body');
            $t->text('published_title')->nullable();
            $t->text('published_body')->nullable();
            $t->string('trust_status', 24)->default('unverified');      // unverified | confirmed | disputed | corrected | expired | removed
            $t->string('moderation_status', 24)->default('published');  // published | needs_revision | held | rejected | pending_ai
            $t->string('seo_eligibility', 16)->default('normal');       // news | normal | noindex | none
            $t->boolean('breaking')->default(false);
            $t->smallInteger('newsworthiness')->nullable();
            $t->smallInteger('quality')->nullable();
            $t->smallInteger('safety')->nullable();
            $t->smallInteger('location_confidence')->nullable();
            $t->string('location_type', 24)->nullable();
            $t->decimal('gps_lat_private', 10, 7)->nullable();
            $t->decimal('gps_lng_private', 10, 7)->nullable();
            $t->unsignedInteger('gps_accuracy_m')->nullable();
            $t->decimal('selected_lat', 10, 7)->nullable();
            $t->decimal('selected_lng', 10, 7)->nullable();
            $t->boolean('pin_was_adjusted')->default(false);
            $t->unsignedInteger('pin_adjustment_m')->nullable();
            $t->string('location_source', 16)->nullable();   // gps | manual | denied | timeout | unavailable
            $t->string('place_name', 200)->nullable();
            $t->json('address_json')->nullable();
            $t->decimal('saw_weight', 8, 2)->default(0);
            $t->decimal('helpful_weight', 8, 2)->default(0);
            $t->decimal('wrong_weight', 8, 2)->default(0);
            $t->decimal('report_weight', 8, 2)->default(0);
            $t->unsignedInteger('report_count')->default(0);
            $t->timestamp('last_review_at')->nullable();
            $t->timestamp('last_materially_updated_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->index(['trust_status', 'breaking']);
        });

        Schema::create('community_post_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->string('actor_type', 16);    // author | ai | moderator | system
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('title');
            $t->text('body');
            $t->string('reason', 300)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('community_moderation_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->string('trigger_type', 16);  // submission | edit | report | threshold | appeal
            $t->string('provider', 32);
            $t->string('model', 64)->nullable();
            $t->string('schema_version', 8);
            $t->string('decision', 24);
            $t->json('scores_json')->nullable();
            $t->json('flags_json')->nullable();
            $t->json('facts_json')->nullable();
            $t->json('added_claims_json')->nullable();
            $t->string('public_reason', 400)->nullable();
            $t->text('response_redacted')->nullable();
            $t->unsignedInteger('latency_ms')->nullable();
            $t->unsignedSmallInteger('attempt')->default(1);
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('community_reactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('device_token', 64)->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->string('type', 12);          // saw | helpful | wrong
            $t->decimal('weight', 5, 2);
            $t->string('scoring_version', 8)->default('1');
            $t->timestamps();
            $t->unique(['news_item_id', 'user_id', 'type']);
            $t->index(['news_item_id', 'device_token', 'type']);
        });

        Schema::create('community_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('device_token', 64)->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->string('reason', 32);
            $t->string('explanation', 1000)->nullable();
            $t->string('evidence_url', 500)->nullable();
            $t->decimal('weight', 5, 2);
            $t->string('status', 16)->default('open');   // open | upheld | rejected
            $t->timestamps();
        });

        Schema::create('community_reputation_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('news_item_id')->nullable()->index();
            $t->string('event', 32);
            $t->integer('points_delta')->default(0);
            $t->integer('credibility_delta')->default(0);
            $t->string('rules_version', 8)->default('1');
            $t->string('note', 300)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'news_item_id', 'event']);
        });

        Schema::create('community_status_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('news_item_id')->index();
            $t->string('field', 24);
            $t->string('from', 24)->nullable();
            $t->string('to', 24);
            $t->string('actor_type', 16);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('reason', 400)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('user_follows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('follower_id');
            $t->unsignedBigInteger('followed_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['follower_id', 'followed_id']);
            $t->index('followed_id');
        });
    }

    public function down(): void
    {
        foreach (['user_follows', 'community_status_history', 'community_reputation_events', 'community_reports', 'community_reactions',
                  'community_moderation_checks', 'community_post_versions', 'community_post_meta'] as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') { DB::statement('drop index if exists users_username_lower_unique'); }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['username', 'display_name', 'bio', 'area', 'avatar_path', 'points', 'credibility']));
    }
};
