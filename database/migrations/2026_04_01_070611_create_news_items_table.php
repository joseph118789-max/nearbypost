<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('source')->nullable();
            $table->string('url')->unique();
            $table->timestamp('published_at')->nullable();
            $table->string('primary_category')->nullable();
            $table->string('secondary_category')->nullable();
            $table->string('status')->default('active');
            $table->string('main_place_text')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('precision_type')->nullable();
            $table->decimal('geo_confidence', 5, 2)->nullable();
            $table->string('relevance_mode')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
