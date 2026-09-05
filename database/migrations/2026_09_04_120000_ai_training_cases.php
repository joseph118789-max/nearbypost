<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Training cases for every AI task, not only the story pipeline (owner, 4 Sep 2026).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create("ai_training_cases", function (Blueprint $t) {
            $t->id();
            $t->string("task_key", 40)->index();
            $t->string("name", 160);          // what this case is testing, in plain words
            $t->jsonb("input");
            $t->jsonb("expect");
            $t->string("source", 20)->default("written");   // written | labelled (taken from real confirmed data)
            $t->boolean("active")->default(true);
            $t->timestamps();
        });

        Schema::table("ai_training_runs", function (Blueprint $t) {
            $t->string("task_key", 40)->default("enrich")->index();
        });
        // one row per round/set/model/TASK
        DB::statement("alter table ai_training_runs drop constraint if exists ai_training_runs_label_set_provider_key_unique");
        DB::statement("create unique index if not exists ai_training_runs_round_idx on ai_training_runs (label, set, provider_key, task_key)");
    }

    public function down(): void
    {
        Schema::dropIfExists("ai_training_cases");
        Schema::table("ai_training_runs", fn (Blueprint $t) => $t->dropColumn("task_key"));
    }
};
