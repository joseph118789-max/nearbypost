<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep a reviewed answer apart from a proposed one.
 *
 * The bench is worth having because a person confirmed each answer. If a
 * machine's opinion can be written into it directly, the bench stops measuring
 * the model against human judgement and starts measuring it against itself -
 * which would always score well and mean nothing.
 *
 * So proposals are stored, shown, and excluded from every run until someone
 * accepts them. Accepting is one click; the point is that it happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bench_items', function (Blueprint $table) {
            $table->boolean('proposed')->default(false);
            // Why this answer was proposed, in terms the person accepting it
            // can check against the story without opening it.
            $table->text('proposed_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bench_items', function (Blueprint $table) {
            $table->dropColumn(['proposed', 'proposed_reason']);
        });
    }
};
