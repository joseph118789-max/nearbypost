<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geocode cache.
 *
 * Manual V6 §26.6 calls for an aggressive geocode cache ("caching reduces 30%-70%").
 * Without it every article triggers its own provider call: ~5,700 requests for ~420
 * distinct places. Nominatim's usage policy is 1 request/second, so an uncached
 * backfill would both take hours and risk being blocked.
 *
 * Negative results are cached too (status='not_found'), otherwise unresolvable place
 * text is re-queried on every scheduled run for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geocode_cache', function (Blueprint $table) {
            $table->id();
            $table->string('place_key')->unique();
            $table->string('place_text');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('label')->nullable();
            $table->decimal('confidence', 8, 4)->nullable();
            $table->string('provider')->default('nominatim');
            $table->string('status')->default('success');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocode_cache');
    }
};
