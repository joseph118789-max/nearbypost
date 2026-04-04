<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_ingest', function (Blueprint $table) {
            $table->string('source', 255)->after('id');
            $table->json('raw_json_payload')->after('source');
            $table->timestamp('received_at')->after('raw_json_payload');
            $table->enum('processing_status', ['pending', 'processed', 'failed'])->default('pending')->after('received_at');
            $table->text('error_message')->nullable()->after('processing_status');
            $table->unsignedBigInteger('news_item_id')->nullable()->after('error_message');
            $table->foreign('news_item_id')->references('id')->on('news_items')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('raw_ingest', function (Blueprint $table) {
            $table->dropForeign(['news_item_id']);
            $table->dropColumn(['source', 'raw_json_payload', 'received_at', 'processing_status', 'error_message', 'news_item_id']);
        });
    }
};
