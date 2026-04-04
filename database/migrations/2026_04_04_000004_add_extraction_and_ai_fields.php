<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            // Extraction results
            $table->string('extracted_title')->nullable()->after('summary');
            $table->text('extracted_summary')->nullable()->after('extracted_title');
            $table->longText('extracted_text')->nullable()->after('extracted_summary');
            $table->string('extraction_status')->default('pending')->after('extracted_text'); // pending, success, fallback_used, failed
            $table->string('extraction_method')->nullable()->after('extraction_status'); // trafilatura, readability, feed_fallback, raw_summary
            $table->timestamp('extracted_at')->nullable()->after('extraction_method');
            
            // AI enrichment results
            $table->text('ai_summary')->nullable()->after('extracted_at');
            $table->string('ai_category')->nullable()->after('ai_summary');
            $table->string('main_place_text')->nullable()->after('ai_category');
            $table->string('relevance_mode')->nullable()->after('main_place_text'); // location_only, category_only, hybrid
            $table->string('ai_status')->default('pending')->after('relevance_mode'); // pending, processing, success, failed
            $table->timestamp('ai_processed_at')->nullable()->after('ai_status');
            
            // Token/cost tracking
            $table->string('ai_model')->nullable()->after('ai_processed_at');
            $table->string('ai_prompt_version')->nullable()->after('ai_model');
            $table->integer('ai_tokens_in')->nullable()->after('ai_prompt_version');
            $table->integer('ai_tokens_out')->nullable()->after('ai_tokens_in');
            $table->decimal('ai_estimated_cost', 10, 6)->nullable()->after('ai_tokens_out');
            
            $table->index('extraction_status');
            $table->index('ai_status');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn([
                'extracted_title', 'extracted_summary', 'extracted_text',
                'extraction_status', 'extraction_method', 'extracted_at',
                'ai_summary', 'ai_category', 'main_place_text', 'relevance_mode',
                'ai_status', 'ai_processed_at', 'ai_model', 'ai_prompt_version',
                'ai_tokens_in', 'ai_tokens_out', 'ai_estimated_cost',
            ]);
        });
    }
};