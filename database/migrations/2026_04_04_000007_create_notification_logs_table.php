<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscriber_id');
            $table->unsignedBigInteger('news_item_id')->nullable();
            $table->string('channel'); // email, webhook, in_app
            $table->string('status'); // pending, sent, failed, rate_limited
            $table->text('recipient')->nullable(); // email address or webhook URL
            $table->text('payload')->nullable(); // JSON payload sent
            $table->text('response')->nullable(); // API response if any
            $table->string('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('subscriber_id')->references('id')->on('subscribers')->onDelete('cascade');
            $table->foreign('news_item_id')->references('id')->on('news_items')->onDelete('set null');
            
            $table->index(['subscriber_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};