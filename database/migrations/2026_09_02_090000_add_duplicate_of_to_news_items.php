<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which story this one repeats.
 *
 * A wire report reaches us five times: Bernama in English, Bernama in Malay,
 * Berita Harian, The Edge and The Malaysian Reserve, all of the same Sarawak
 * haze reading at 5pm. The reader should see it once.
 *
 * Recorded as a pointer rather than by deleting the row, because the duplicate
 * is evidence: it says which publishers carry which wire, and a merge that
 * turns out to be wrong can be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->unsignedBigInteger('duplicate_of')->nullable()->after('discarded');
            $table->string('duplicate_reason', 40)->nullable()->after('duplicate_of');
            $table->index('duplicate_of');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropIndex(['duplicate_of']);
            $table->dropColumn(['duplicate_of', 'duplicate_reason']);
        });
    }
};
