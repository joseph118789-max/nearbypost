<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per fetch run, so a run that dies cannot look like a quiet news day.
 *
 * ⛔ 4 Sep 2026: the 08:00 run collected 574 stories and then threw "Undefined
 * array key _aggregator", losing every one of them. Nothing anywhere said so.
 * The fetch log held the stack trace, but the only thing a person looks at -
 * the day's ingested count - simply read low, which is exactly what a slow news
 * morning looks like. The owner spotted it by eye and asked "is the php
 * scrapper even working today?", which is the question this table answers.
 *
 * A run is recorded whether it finishes or not, so the failure is a ROW rather
 * than an absence. An absence is what hid it the first time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fetch_runs', function (Blueprint $t) {
            $t->id();
            $t->string('tier', 20)->nullable();          // primary, secondary, or a single source
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();    // null = still running, or died without unwinding
            $t->unsignedInteger('sources')->default(0);
            $t->unsignedInteger('collected')->default(0);
            $t->unsignedInteger('unique_items')->default(0);
            $t->unsignedInteger('created')->default(0);
            $t->unsignedInteger('duplicate')->default(0);

            // ok        - collected and stored
            // barren    - collected something and stored nothing (the 4 Sep failure)
            // empty     - the feeds genuinely had nothing new; not a fault
            // crashed   - threw before it could finish
            $t->string('status', 16)->default('ok');
            $t->string('error', 400)->nullable();

            $t->timestamps();
            $t->index(['started_at', 'status'], 'fetch_runs_started_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fetch_runs');
    }
};
