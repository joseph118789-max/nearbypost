<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('news_item_id')->nullable();
            $table->string('reason'); // spam, inaccurate, inappropriate, other
            $table->text('note')->nullable();
            $table->string('status')->default('pending'); // pending, reviewed, dismissed, resolved
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            
            $table->index('news_item_id');
            $table->index('status');
            $table->index('created_at');
            
            // Foreign key (optional - allow null if news_item deleted)
            $table->foreign('news_item_id')->references('id')->on('news_items')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};