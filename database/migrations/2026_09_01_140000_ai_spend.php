<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Know what the model costs, and how much is left to spend.
 *
 * Two different questions, answered two different ways on purpose.
 *
 * What a story cost is worked out from its tokens, which lets spend be
 * attributed - this source, this day, this kind of work. It depends on a price
 * list, and a price list goes out of date.
 *
 * What was actually spent is the balance falling. It needs no assumptions and
 * cannot drift, but it says nothing about where the money went.
 *
 * Keeping both means the estimate can be checked against the truth, and a
 * disagreement between them is itself worth seeing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_processing_jobs', function (Blueprint $table) {
            // Cached input is billed at roughly a tenth of fresh input, so a
            // total token count now says almost nothing about cost. Since the
            // prompt was reordered, most input is cached.
            $table->unsignedInteger('cache_hit_tokens')->nullable();
            $table->unsignedInteger('cache_miss_tokens')->nullable();
        });

        Schema::create('ai_balance_log', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('deepseek');
            $table->decimal('balance', 12, 4);
            $table->string('currency', 8)->default('USD');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['provider', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_balance_log');

        Schema::table('ai_processing_jobs', function (Blueprint $table) {
            $table->dropColumn(['cache_hit_tokens', 'cache_miss_tokens']);
        });
    }
};
