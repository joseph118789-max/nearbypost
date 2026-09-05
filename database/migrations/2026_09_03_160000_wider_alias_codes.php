<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The same 23-character source ids, on the alias table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boundary_aliases', fn (Blueprint $t) => $t->string('code', 40)->change());
    }

    public function down(): void {}
};
