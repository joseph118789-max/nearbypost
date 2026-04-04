<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->onDelete('cascade');
            $table->string('extracted_title', 500)->nullable();
            $table->text('extracted_summary')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->enum('extraction_status', ['pending', 'success', 'fallback_used', 'failed'])->default('pending');
            $table->string('extraction_method', 50)->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_jobs');
    }
};
