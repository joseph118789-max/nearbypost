<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            // Topic extraction results
            $table->string('topic_extraction_status')->default('pending')->after('ai_estimated_cost');
            $table->timestamp('topic_extracted_at')->nullable()->after('topic_extraction_status');
            $table->json('topics_extracted')->nullable()->after('topic_extracted_at');

            $table->index('topic_extraction_status');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn([
                'topic_extraction_status',
                'topic_extracted_at',
                'topics_extracted',
            ]);
        });
    }
};