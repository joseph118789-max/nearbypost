<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third level: district, county, city - whatever a country calls the
 * area below its state. Malaysia's daerah (Petaling, Kinta), Singapore's
 * planning areas, China's districts and counties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->string('geo_city_code', 40)->nullable()->after('geo_state_code');
            $table->index(['geo_state_code', 'geo_city_code']);
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->string('geo_city_code', 40)->nullable()->after('geo_state_code');
        });

        // Level-2 codes from the source are its own ids, longer than ISO codes.
        Schema::table('boundaries', function (Blueprint $table) {
            $table->string('code', 40)->change();
            $table->string('parent_code', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', fn (Blueprint $t) => $t->dropColumn('geo_city_code'));
        Schema::table('news_items', function (Blueprint $t) {
            $t->dropIndex(['geo_state_code', 'geo_city_code']);
            $t->dropColumn('geo_city_code');
        });
    }
};
