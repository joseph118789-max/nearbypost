<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each publisher permits, what they expose, and what that costs us.
 *
 * Sources were being added on whether a feed returned items, which answers only
 * the easiest question. It missed that The Star prohibits automated extraction
 * outright, that BusinessToday blocks article pages while publishing an open
 * API, and that Malay Mail's feed is the only copy of its articles that exists.
 * Each of those was found by accident, weeks apart, after the source was
 * already running.
 *
 * These columns hold the answer to three questions per source, so a decision
 * about a publisher can be made from one row instead of an investigation:
 *
 *   May we?     what their robots.txt and terms say
 *   Can we?     which routes actually return an article
 *   How much?   what fraction of what they publish we end up holding
 *
 * ⭐ A limitation with no route recorded beside it is half an answer. The
 * workaround column is not optional documentation - it is the point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // ── May we? ────────────────────────────────────────────────────
            // permitted        - nothing in their robots.txt stands against us
            // ai_restricted    - they block named AI crawlers by user agent
            // prohibited       - they forbid automated extraction in prose
            // unknown          - not yet checked
            $table->string('robots_policy', 20)->nullable();
            $table->text('robots_note')->nullable();
            $table->timestamp('robots_checked_at')->nullable();
            $table->string('licence_contact', 190)->nullable();

            // ── Can we? ────────────────────────────────────────────────────
            // Each route tested and its result kept, because the useful fact is
            // usually which one works rather than that something does.
            $table->json('routes')->nullable();
            $table->string('best_route', 20)->nullable();

            // ── How much? ──────────────────────────────────────────────────
            $table->unsignedSmallInteger('sections_published')->nullable();
            $table->unsignedSmallInteger('sections_reachable')->nullable();
            $table->unsignedSmallInteger('coverage_pct')->nullable();

            // ── The two that matter to a person ────────────────────────────
            $table->text('constraint_note')->nullable();
            $table->text('workaround_note')->nullable();
            $table->timestamp('audited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn([
                'robots_policy', 'robots_note', 'robots_checked_at', 'licence_contact',
                'routes', 'best_route',
                'sections_published', 'sections_reachable', 'coverage_pct',
                'constraint_note', 'workaround_note', 'audited_at',
            ]);
        });
    }
};
