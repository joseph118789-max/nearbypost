<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the Countries report needs.
 *
 * geo_country_code says where a PIN is. Most stories have no pin - national
 * ones by design, discarded foreign ones because they were never geocoded -
 * and still belong to a country: "Chicago, United States" is a United States
 * story whether or not it was kept. geo_claim_country is that country, read
 * from the place text and the publisher's own country, so every story can be
 * counted somewhere.
 *
 * And sources.country must allow null: an international outlet - a sports
 * federation, a wire - has no home, and forcing one onto it turns the prior
 * into a lie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->char('geo_claim_country', 3)->nullable()->after('geo_state_code');
            $table->index(['geo_claim_country', 'published_at']);
        });

        DB::statement('ALTER TABLE sources ALTER COLUMN country DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['geo_claim_country', 'published_at']);
            $table->dropColumn('geo_claim_country');
        });
    }
};
