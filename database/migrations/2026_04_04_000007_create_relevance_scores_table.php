<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('relevance_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('news_item_id');
            $table->decimal('score', 10, 4)->default(0);
            $table->json('score_breakdown')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'news_item_id']);
            $table->index('news_item_id');
            $table->index('score');

            // Foreign keys (if tables exist)
            // $table->foreign('user_id')->references('id')->on('subscribers')->onDelete('cascade');
            // $table->foreign('news_item_id')->references('id')->on('news_items')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('relevance_scores');
    }
};