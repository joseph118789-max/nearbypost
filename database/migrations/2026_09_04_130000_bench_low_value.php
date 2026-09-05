<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Owner, 4 Sep 2026: "all these have little value to nearbypost ... any suspected category is
// acceptable ... even discarding them is correct." A story where every answer is defensible is
// not a test of anything, so it is kept for the record but never scored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table("bench_items", fn (Blueprint $t) => $t->boolean("low_value")->default(false)->index());
    }

    public function down(): void
    {
        Schema::table("bench_items", fn (Blueprint $t) => $t->dropColumn("low_value"));
    }
};
