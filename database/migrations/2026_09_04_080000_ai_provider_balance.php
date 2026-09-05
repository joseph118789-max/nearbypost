<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Money left per provider (owner, 4 Sep 2026): a live balance where the provider offers one,
// otherwise a top-up amount the admin enters minus what our log has spent since.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $t) {
            $t->string('balance_kind', 16)->nullable();        // deepseek | moonshot | null (no balance API)
            $t->string('billing_url', 200)->nullable();
            $t->decimal('credit_usd', 10, 2)->nullable();       // what the admin says was topped up
            $t->timestamp('credit_set_at')->nullable();
            $t->decimal('low_balance_usd', 8, 2)->default(3);   // warn below this
        });

        $now = now();
        DB::table('ai_providers')->where('key', 'deepseek')->update(['balance_kind' => 'deepseek', 'billing_url' => 'https://platform.deepseek.com/top_up', 'updated_at' => $now]);
        DB::table('ai_providers')->where('key', 'deepseek-reasoner')->update(['balance_kind' => 'deepseek', 'billing_url' => 'https://platform.deepseek.com/top_up', 'updated_at' => $now]);
        DB::table('ai_providers')->where('key', 'kimi')->update(['balance_kind' => 'moonshot', 'billing_url' => 'https://platform.moonshot.ai/console/account', 'updated_at' => $now]);
        DB::table('ai_providers')->where('key', 'openai')->update(['billing_url' => 'https://platform.openai.com/settings/organization/billing/overview', 'updated_at' => $now]);
        DB::table('ai_providers')->where('key', 'gemini')->update(['billing_url' => 'https://console.cloud.google.com/billing', 'updated_at' => $now]);
        DB::table('ai_providers')->where('key', 'claude')->update(['billing_url' => 'https://console.anthropic.com/settings/billing', 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('ai_providers', fn (Blueprint $t) => $t->dropColumn(['balance_kind', 'billing_url', 'credit_usd', 'credit_set_at', 'low_balance_usd']));
    }
};
