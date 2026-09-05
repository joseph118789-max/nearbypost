<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('briefing_terms', function (Blueprint $t) {
            $t->string('country', 2)->nullable()->index();   // ISO2; null = told to the model for every country
        });
        // everything written before countries existed was Malaysian
        DB::table('briefing_terms')->whereNull('country')->update(['country' => 'MY']);
    }

    public function down(): void
    {
        Schema::table('briefing_terms', fn (Blueprint $t) => $t->dropColumn('country'));
    }
};
