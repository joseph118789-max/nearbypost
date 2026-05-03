<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplemental repair: recreate location_aliases table.
 *
 * The original D12 migration (2026_04_05_000001_create_location_aliases_table)
 * shows as [999] Ran in migrate:status, but the table does not exist in the
 * live database. This means the migration was recorded but the table was
 * dropped or never actually created.
 *
 * This repair creates the table only if it does not exist — fully idempotent,
 * no data mutation, no history rewriting.
 *
 * Schema mirrors the original D12 intent:
 *   id, alias_text, canonical_name, alias_type, is_active, timestamps
 *   unique index on alias_text
 *   composite index on (alias_type, is_active)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('location_aliases')) {
            Schema::create('location_aliases', function (Blueprint $table) {
                $table->id();
                $table->string('alias_text', 200)
                    ->comment('e.g. PJ, KL, Klang Valley');
                $table->string('canonical_name', 500)
                    ->comment('e.g. Petaling Jaya, Kuala Lumpur');
                $table->string('alias_type', 30)
                    ->comment('city, district, region, area');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // Exact lookup, case-insensitive
                $table->unique(['alias_text']);
                $table->index(['alias_type', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_aliases');
    }
};