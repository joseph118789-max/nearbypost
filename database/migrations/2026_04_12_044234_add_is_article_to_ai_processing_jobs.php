<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_processing_jobs', function (Blueprint $table) {
            $table->boolean('is_article')->default(true)->after('validated_place');
        });
    }

    public function down(): void
    {
        Schema::table('ai_processing_jobs', function (Blueprint $table) {
            $table->dropColumn('is_article');
        });
    }
};
