<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credibility = a base a person set (50 by default; 80 for a vouched-for
 * contributor) plus the ledger. The ledger used to recompute from 50 and
 * wipe a base set by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->integer('credibility_base')->default(50));
        DB::table('users')->update(['credibility_base' => DB::raw('credibility')]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('credibility_base'));
    }
};
