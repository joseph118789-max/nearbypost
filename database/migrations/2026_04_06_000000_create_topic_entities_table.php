<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained()->onDelete('cascade');
            
            // Entity information
            $table->string('type'); // person, brand, organization, event, location, topic
            $table->string('name');
            $table->text('normalized_name')->nullable();
            $table->string('confidence')->nullable(); // high, medium, low
            
            // Topic clustering
            $table->string('topic_cluster')->nullable(); // e.g., "budget_2026", "flood_relief"
            $table->integer('topic_score')->nullable(); // 0-100
            
            // Position in article
            $table->integer('first_position')->nullable();
            $table->integer('last_position')->nullable();
            $table->integer('occurrence_count')->default(1);
            
            // AI processing
            $table->string('extraction_model')->nullable();
            $table->timestamp('extracted_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['news_item_id', 'type']);
            $table->index('topic_cluster');
            $table->index('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_entities');
    }
};