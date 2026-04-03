<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_ready_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('source')->nullable();
            $table->string('url');
            $table->timestamp('published_at')->nullable();
            $table->string('primary_category')->nullable();
            $table->string('secondary_category')->nullable();
            $table->string('location_label')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('precision_type')->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->string('relevance_mode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_ready_items');
    }
};
