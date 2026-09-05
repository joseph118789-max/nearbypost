<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Not every state has an ISO 3166-2 code in the source. China's provinces
 * carry the source's own 23-character ids, and the first Chinese pin to be
 * stamped died on a varchar(16). Forty covers every id the source uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', fn (Blueprint $t) => $t->string('geo_state_code', 40)->nullable()->change());
        Schema::table('feed_ready_items', fn (Blueprint $t) => $t->string('geo_state_code', 40)->nullable()->change());
    }

    public function down(): void
    {
        // Left as is: narrowing would truncate stored codes.
    }
};
