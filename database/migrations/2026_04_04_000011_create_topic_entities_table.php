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
            $table->string('entity_type'); // person, brand, organization, event, location, topic
            $table->string('name'); // The entity name
            $table->string('normalized_name')->nullable(); // Normalized for grouping
            $table->text('context')->nullable(); // Surrounding text where entity appears
            $table->integer('mentions_count')->default(1);
            $table->decimal('confidence', 3, 2)->default(0.80);
            $table->json('metadata')->nullable(); // Additional data like roles, titles
            $table->timestamps();

            $table->index('entity_type');
            $table->index('normalized_name');
            $table->index(['news_item_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_entities');
    }
};