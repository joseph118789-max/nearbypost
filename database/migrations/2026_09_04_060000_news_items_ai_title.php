<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Our own headline for a gathered story (owner, 4 Sep 2026): the publisher's title stays in
// `title` for matching and for the link; readers see ai_title wherever it exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $t) {
            $t->string('ai_title', 220)->nullable()->after('ai_summary');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', fn (Blueprint $t) => $t->dropColumn('ai_title'));
    }
};
