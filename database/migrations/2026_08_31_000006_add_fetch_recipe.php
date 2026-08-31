<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What we have learned about reading each source.
 *
 * Every publisher misbehaves in its own way. Free Malaysia Today answers 301 and
 * is invisible to a fetcher that does not follow redirects. Harian Metro stamps
 * local time but labels it +0000, putting its stories eight hours in the future.
 * Some sites refuse a request without a browser user agent. Each of those cost a
 * debugging session to find, and each was then known only to whoever found it.
 *
 * fetch_recipe records that knowledge against the source it belongs to: how the
 * feed was found, what has to be done to read it, which quirks have been
 * observed, and what has failed before. The fetcher reads it, so a lesson learnt
 * once is applied automatically from then on.
 *
 * Shape:
 *   {
 *     "discovery":  {"method": "autodiscovery", "found_at": "..."},
 *     "transport":  {"follow_redirects": true, "browser_ua": false},
 *     "quirks":     ["published_at_local_labelled_utc"],
 *     "observed":   {"typical_items": 50, "encoding": "utf-8"},
 *     "failures":   [{"at": "...", "reason": "http_404"}]
 *   }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->json('fetch_recipe')->nullable()->after('probe_notes');

            // How many stories this source has actually contributed, and how
            // many were thrown away as duplicates of something we already had.
            // A source that only ever repeats other feeds is worth demoting.
            $table->unsignedBigInteger('items_contributed')->default(0)->after('fetch_recipe');
            $table->unsignedBigInteger('items_duplicate')->default(0)->after('items_contributed');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['fetch_recipe', 'items_contributed', 'items_duplicate']);
        });
    }
};
