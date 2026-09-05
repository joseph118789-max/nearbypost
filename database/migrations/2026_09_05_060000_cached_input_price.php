<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The price of input the provider already had in its cache.
 *
 * ⛔⛔ WITHOUT THIS THE SPEND FIGURES OVERSTATE THE BILL SEVERAL TIMES OVER.
 *
 * `AiRouter::record()` priced every prompt token at `price_in`, the FRESH input
 * rate. DeepSeek charges $0.07 per million for input it has cached and $0.27
 * for input it has not - and this project deliberately reordered the prompt so
 * that most input IS cached, which was the whole point of that work. So nearly
 * four times the real price was being applied to the majority of tokens.
 *
 * Measured on Friday 4 Sep 2026: the call log said USD 26.29, the balance
 * actually fell by USD 5.95. The owner spotted it.
 *
 * ⛔ NULL means "this provider has no separate cached rate that we have
 * confirmed", and the code then falls back to price_in - exactly today's
 * behaviour. Only DeepSeek's figures are filled in here, because those are the
 * ones this project has actually checked (they match AiSpend::RATES, read on
 * 2026-09-01). Guessing the others would replace a known overstatement with an
 * unknown one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $t) {
            $t->decimal('price_cached', 8, 4)->nullable()->after('price_in');
        });

        // Confirmed rates only. Everything else stays null and keeps billing at
        // price_in, so no provider's figures move on a guess.
        DB::table('ai_providers')->where('key', 'deepseek')->update(['price_cached' => 0.07]);
        DB::table('ai_providers')->where('key', 'deepseek-reasoner')->update(['price_cached' => 0.14]);
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $t) {
            $t->dropColumn('price_cached');
        });
    }
};
