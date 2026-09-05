<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every city in the world with its population, so "is this place too big to be
 * near?" can be answered anywhere, not only in Malaysia.
 *
 * Owner, 4 Sep 2026: "anywhere we can download a table listing all the cities
 * above 500,000 population so system can query when needed? group them by
 * country so AI dont have to read unrelated countries to speed up searching."
 *
 * Source: GeoNames cities15000 (CC BY 4.0) - 34,135 places of 15,000 people or
 * more, of which 1,206 are above half a million, across 137 countries. Held as
 * a TABLE, not in the prompt: the model never reads it, the code queries it in
 * a millisecond, and grouping by country is what the index does.
 *
 * The whole file is loaded, not only the cities above the line, because the
 * same table answers the other question worth asking - how big is this town? -
 * and 34,000 rows costs a few megabytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_cities', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('geoname_id')->unique();
            $t->string('name', 200);
            $t->string('ascii_name', 200)->nullable();
            $t->string('name_key', 250);            // GazetteerSearch::key(), so it joins the way everything else does
            $t->string('country_code', 2);          // ISO-3166 alpha-2: the grouping the owner asked for
            $t->string('admin1_code', 20)->nullable();
            $t->unsignedBigInteger('population')->default(0);
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->string('feature_code', 10)->nullable();
            $t->timestamps();

            // by country first: every lookup already knows which country it is in
            $t->index(['country_code', 'population'], 'world_cities_country_pop_idx');
            $t->index(['name_key', 'country_code'], 'world_cities_name_country_idx');
            $t->index(['lat', 'lng'], 'world_cities_latlng_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('world_cities');
    }
};
