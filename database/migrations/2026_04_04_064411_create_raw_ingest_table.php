<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_ingest', function (Blueprint $table) {
            $table->id();
            $table->string('source', 255);
            $table->json('raw_json_payload');
            $table->timestamp('received_at');
            $table->enum('processing_status', ['pending', 'processed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('news_item_id')->nullable();
            $table->foreign('news_item_id')->references('id')->on('news_items')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_ingest');
    }
};
