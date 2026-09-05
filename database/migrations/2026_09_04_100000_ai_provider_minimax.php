<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MiniMax joins the AI panel (owner, 4 Sep 2026). OpenAI-compatible endpoint; key MINIMAX_API_KEY.
return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table("ai_providers")->where("key", "minimax")->exists()) {
            DB::table("ai_providers")->insert([
                "key" => "minimax", "name" => "MiniMax", "base_url" => "https://api.minimax.io/v1", "model" => "MiniMax-M2", "vision_model" => null,
                "key_name" => "minimax", "enabled" => false, "price_in" => 0.30, "price_out" => 1.20, "billing_url" => "https://platform.minimax.io",
                "notes" => "Strong Chinese and cheap. Needs MINIMAX_API_KEY in .env, then switch on. No balance call: enter what you topped up.",
                "created_at" => now(), "updated_at" => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table("ai_providers")->where("key", "minimax")->delete();
    }
};
