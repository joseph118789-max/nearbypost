<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Training (owner, 4 Sep 2026): every round of the bake-off, so the panel can show whether the
// prompt work is actually making the models better - and on stories they were never tuned against.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_training_runs', function (Blueprint $t) {
            $t->id();
            $t->string('label', 40);                    // r0, r1 ...
            $t->string('set', 12);                      // train | holdout
            $t->string('provider_key', 32);
            $t->string('model', 80)->nullable();
            $t->unsignedSmallInteger('items')->default(0);
            $t->unsignedSmallInteger('keep_pct')->nullable();
            $t->unsignedSmallInteger('category_pct')->nullable();
            $t->unsignedSmallInteger('place_pct')->nullable();
            $t->unsignedSmallInteger('national_pct')->nullable();
            $t->unsignedSmallInteger('copies')->default(0);
            $t->unsignedSmallInteger('errors')->default(0);
            $t->unsignedInteger('note_chars')->default(0);
            $t->string('changed', 300)->nullable();     // what was changed before this round
            $t->timestamp('created_at')->nullable();
            $t->unique(['label', 'set', 'provider_key']);
            $t->index(['set', 'provider_key', 'id']);
        });

        Schema::create('ai_training_misses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('run_id')->index();
            $t->unsignedBigInteger('news_item_id');
            $t->string('title', 200);
            $t->string('problem', 400);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_training_misses');
        Schema::dropIfExists('ai_training_runs');
    }
};
