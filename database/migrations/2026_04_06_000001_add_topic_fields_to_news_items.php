<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->string('topic_extraction_status')->nullable()->after('ai_processed_at');
            $table->timestamp('topic_extracted_at')->nullable()->after('topic_extraction_status');
            $table->string('topic_extraction_model')->nullable()->after('topic_extracted_at');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn(['topic_extraction_status', 'topic_extracted_at', 'topic_extraction_model']);
        });
    }
};