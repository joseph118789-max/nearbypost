<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the state and country beside the coordinates.
 *
 * The cache stored where a place is and forgot what it was part of, so a cached
 * hit came back without the state and the name could not be completed from it.
 * Since most lookups hit the cache, the completion would have applied to almost
 * nothing.
 *
 * Existing rows keep NULL and are completed the next time they are looked up
 * fresh; nothing is invalidated, because the coordinates in them are still
 * right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geocode_cache', function (Blueprint $table) {
            $table->string('state', 120)->nullable()->after('label');
            $table->string('country', 120)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('geocode_cache', function (Blueprint $table) {
            $table->dropColumn(['state', 'country']);
        });
    }
};
