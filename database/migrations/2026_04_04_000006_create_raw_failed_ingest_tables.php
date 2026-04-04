<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw incoming payload storage
        Schema::create('raw_ingests', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // e.g., 'bbc_news', 'reuters'
            $table->json('raw_json_payload');
            $table->timestamp('received_at');
            $table->string('processing_status')->default('pending'); // pending, processed, failed
            $table->string('error_message')->nullable();
            $table->timestamps();
            
            $table->index('source');
            $table->index('processing_status');
            $table->index('received_at');
        });
        
        // Failed ingestions for dead-letter bucket
        Schema::create('failed_ingestions', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('url')->nullable();
            $table->string('title')->nullable();
            $table->json('raw_payload');
            $table->text('failure_reason');
            $table->integer('retry_count')->default(0);
            $table->timestamp('failed_at');
            $table->timestamps();
            
            $table->index('source');
            $table->index('failed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_ingestions');
        Schema::dropIfExists('raw_ingests');
    }
};