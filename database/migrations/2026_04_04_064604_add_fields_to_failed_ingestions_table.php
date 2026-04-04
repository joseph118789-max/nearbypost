<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failed_ingestions', function (Blueprint $table) {
            $table->string('source', 255)->after('id');
            $table->string('url', 2048)->nullable()->after('source');
            $table->string('title', 500)->nullable()->after('url');
            $table->json('raw_payload')->after('title');
            $table->text('failure_reason')->after('raw_payload');
            $table->integer('retry_count')->default(0)->after('failure_reason');
            $table->timestamp('failed_at')->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('failed_ingestions', function (Blueprint $table) {
            $table->dropColumn(['source', 'url', 'title', 'raw_payload', 'failure_reason', 'retry_count', 'failed_at']);
        });
    }
};
