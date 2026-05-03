<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('news_items', 'enrichment_failure_reason')) {
            Schema::table('news_items', function (Blueprint $table) {
                $table->string('enrichment_failure_reason', 255)->nullable()->after('ai_status');
            });
        }
        if (!Schema::hasColumn('news_items', 'enrichment_failure_count')) {
            Schema::table('news_items', function (Blueprint $table) {
                $table->unsignedTinyInteger('enrichment_failure_count')->default(0)->after('enrichment_failure_reason');
            });
        }
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn(['enrichment_failure_reason', 'enrichment_failure_count']);
        });
    }
};
