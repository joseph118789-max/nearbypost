<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->onDelete('cascade');
            $table->text('ai_summary')->nullable();
            $table->string('ai_category', 100)->nullable();
            $table->string('main_place_text', 500)->nullable();
            $table->enum('relevance_mode', ['local', 'national', 'global'])->default('national');
            $table->enum('ai_status', ['pending', 'processing', 'success', 'failed'])->default('pending');
            $table->string('model_used', 50)->nullable();
            $table->string('prompt_version', 20)->default('v1');
            $table->integer('tokens_in')->nullable();
            $table->integer('tokens_out')->nullable();
            $table->decimal('estimated_cost', 10, 6)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_processing_jobs');
    }
};
