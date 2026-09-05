<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the state-bounded search saw but could not accept, kept with the
 * name so the person reviewing it starts from "is it one of these" rather
 * than from nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_reviews', fn (Blueprint $t) => $t->jsonb('suggestions')->nullable()->after('attempts'));
    }

    public function down(): void
    {
        Schema::table('place_reviews', fn (Blueprint $t) => $t->dropColumn('suggestions'));
    }
};
