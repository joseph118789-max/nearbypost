<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The state a story belongs to when it has no pin: from its text, from the
 * places the model listed, or from the story it duplicates. 'MULTI' when the
 * places span several states.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $t) {
            $t->string('geo_claim_state', 40)->nullable()->after('geo_claim_country');
            $t->index(['geo_claim_country', 'geo_claim_state']);
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $t) {
            $t->dropIndex(['geo_claim_country', 'geo_claim_state']);
            $t->dropColumn('geo_claim_state');
        });
    }
};
