<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who added this sub-category.
 *
 * The classifier is capped at a handful of new sub-categories a day, which is
 * the guard against a taxonomy quietly filling with near-duplicates. But the
 * cap was counting every row created that day, so seeding eighteen sports by
 * hand exhausted the model's budget and it then refused a legitimate one.
 *
 * A limit on the model should count the model. This column is also what the
 * panel needs to show a person what was added without being asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            $table->string('created_by', 20)->default('admin')->after('gps');
        });
    }

    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};
