<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The world, as polygons, so a pin can be checked against the place it claims.
 *
 * A Moto3 win at Brno went live 1.7 km from KLCC because the geocoder, unable
 * to find the circuit, walked up to "Republik Czech" and found the Czech
 * embassy in Kuala Lumpur. Every guard that caught such things before worked
 * on NAMES - a list of country spellings, a list of Malaysian states - and a
 * name-based guard fails the first time it meets a spelling it has not seen.
 *
 * This is the structural version. Every country in the world at level 0,
 * every state or province at level 1 where the source has them, each carrying
 * its ISO 3166 code and its polygon. A pin that claims to be in Czechia and
 * lands in Kuala Lumpur is refused because the point is not inside the
 * polygon - no spelling involved.
 *
 * Source: geoBoundaries (gbOpen), ODbL. Loaded per country on demand, so the
 * table starts with Malaysia and its neighbours and grows the first time a
 * story names somewhere new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boundaries', function (Blueprint $table) {
            $table->id();
            $table->char('iso3', 3);                       // country this belongs to
            $table->char('iso2', 2)->nullable();
            $table->unsignedTinyInteger('level');          // 0 country, 1 state/province, 2 district
            $table->string('code', 16);                    // ISO3 at level 0; ISO 3166-2 ("MY-10") at level 1
            $table->string('parent_code', 16)->nullable(); // the code one level up
            $table->string('name', 160);                   // as the source names it (English)
            $table->string('name_key', 160);               // lowercased, for matching
            $table->jsonb('geometry');                     // GeoJSON geometry, simplified
            $table->decimal('min_lat', 9, 6);
            $table->decimal('max_lat', 9, 6);
            $table->decimal('min_lng', 9, 6);
            $table->decimal('max_lng', 9, 6);
            $table->unsignedInteger('vertices')->default(0);
            $table->string('source', 40)->default('geoboundaries');
            $table->string('source_release', 40)->nullable();
            $table->string('licence', 80)->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamps();

            $table->unique(['iso3', 'level', 'code']);
            $table->index(['level', 'min_lat', 'max_lat', 'min_lng', 'max_lng'], 'boundaries_bbox');
            $table->index(['iso3', 'level', 'name_key']);
        });

        // Other names for the same area: "Melaka" for Malacca, "Pulau Pinang"
        // for Penang, "KL" for Kuala Lumpur. Per country, so any country can
        // be taught its local names without touching code.
        Schema::create('boundary_aliases', function (Blueprint $table) {
            $table->id();
            $table->char('iso3', 3);
            $table->unsignedTinyInteger('level');
            $table->string('code', 16);
            $table->string('alias', 160);
            $table->string('alias_key', 160);
            $table->string('language', 8)->nullable();
            $table->timestamps();

            $table->unique(['iso3', 'level', 'alias_key']);
        });

        // Every placed story now knows, structurally, where its pin is. Not
        // what the text said - where the point actually falls. This is what a
        // state filter, a per-state count, or a "wrong state" alarm reads.
        Schema::table('news_items', function (Blueprint $table) {
            $table->char('geo_country_code', 3)->nullable()->after('geocode_provider');
            $table->string('geo_state_code', 16)->nullable()->after('geo_country_code');
            $table->index(['geo_country_code', 'geo_state_code']);
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->char('geo_country_code', 3)->nullable();
            $table->string('geo_state_code', 16)->nullable();
            $table->index(['is_active', 'geo_state_code']);
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'geo_state_code']);
            $table->dropColumn(['geo_country_code', 'geo_state_code']);
        });
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['geo_country_code', 'geo_state_code']);
            $table->dropColumn(['geo_country_code', 'geo_state_code']);
        });
        Schema::dropIfExists('boundary_aliases');
        Schema::dropIfExists('boundaries');
    }
};
