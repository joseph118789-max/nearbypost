<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember that we already asked.
 *
 * The fingerprint proposes pairs and a model settles them, and most of what it
 * settles is "no". Without a record of that, every run re-asks the same
 * questions: one pass proposed 322 pairs and the model rejected 116 of them,
 * and those 116 would have been bought again half an hour later, and every half
 * hour after that, for as long as both stories stayed in the two-day window.
 *
 * A "no" is as much an answer as a "yes" and costs the same to obtain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dedupe_verdicts', function (Blueprint $table) {
            $table->id();

            // Always stored low id first, so a pair has one row however it is
            // encountered.
            $table->unsignedBigInteger('story_a');
            $table->unsignedBigInteger('story_b');
            $table->boolean('same');
            $table->unsignedSmallInteger('shared_tokens')->nullable();
            $table->timestamps();

            $table->unique(['story_a', 'story_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dedupe_verdicts');
    }
};
