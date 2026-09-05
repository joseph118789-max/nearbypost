<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-model notes, per country (owner, 4 Sep 2026: "why when I change to UK is the training the
 * same?"). Same shape as playbook_sections and review_rules: a row with country null is the base,
 * told to the model whatever the publisher's country; a row with a country is added on top for
 * that country's stories only.
 *
 * Everything written so far becomes base, so nothing changes today. The Malaysian wording in it
 * is worth splitting out before a second country's sources are switched on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_task_prompts', function (Blueprint $t) {
            $t->string('country', 2)->nullable()->index();
        });

        DB::statement('alter table ai_task_prompts drop constraint if exists ai_task_prompts_task_key_provider_key_unique');
        DB::statement("create unique index if not exists ai_task_prompts_scope_idx on ai_task_prompts (task_key, provider_key, coalesce(country, ''))");
    }

    public function down(): void
    {
        DB::statement('drop index if exists ai_task_prompts_scope_idx');
        Schema::table('ai_task_prompts', fn (Blueprint $t) => $t->dropColumn('country'));
    }
};
