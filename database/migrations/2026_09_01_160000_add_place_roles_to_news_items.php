<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the model's reasoning about place, not only its conclusion.
 *
 * Every place the article names, what role the model decided it plays, and the
 * words that put it there. Without this a wrong location can only be overruled;
 * with it, it can be argued with - you can see that the hospital was correctly
 * read as aftermath and the mistake was elsewhere.
 *
 * It is also the only way to tell a lucky right answer from a reasoned one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->jsonb('place_roles')->nullable()->after('main_place_text');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn('place_roles');
        });
    }
};
