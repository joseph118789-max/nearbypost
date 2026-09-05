<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings per country. Owner, 3 Sep 2026: "each country need different
 * processing prompt. Even published corrections may need different rules.
 * Imagine we are using mandarin source or tamil source. Grammar are all
 * different. Rules will be different too."
 *
 * A playbook section or a house rule with country = null is the base, used
 * for every country. A row with a country is that country's own version: a
 * playbook section overrides the base section of the same key; a rule is
 * added to the base rules for that country only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playbook_sections', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('key');
        });
        Schema::table('review_rules', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('applies_to');
        });

        // the key alone was unique; now the key per country is
        if (DB::getDriverName() === 'pgsql') { DB::statement('alter table playbook_sections drop constraint if exists playbook_sections_key_unique'); }
        if (DB::getDriverName() === 'pgsql') { DB::statement('drop index if exists playbook_sections_key_unique'); }
        if (DB::getDriverName() === 'pgsql') { DB::statement('create unique index playbook_sections_key_country_unique on playbook_sections (key, coalesce(country, \'\'))'); }
        if (DB::getDriverName() === 'pgsql') { DB::statement('create index review_rules_country_index on review_rules (country)'); }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') { DB::statement('drop index if exists playbook_sections_key_country_unique'); }
        if (DB::getDriverName() === 'pgsql') { DB::statement('drop index if exists review_rules_country_index'); }
        Schema::table('playbook_sections', fn (Blueprint $t) => $t->dropColumn('country'));
        Schema::table('review_rules', fn (Blueprint $t) => $t->dropColumn('country'));
        if (DB::getDriverName() === 'pgsql') { DB::statement('create unique index playbook_sections_key_unique on playbook_sections (key)'); }
    }
};
