<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_usage_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 64)->nullable()->index();
            $table->string('endpoint', 200)->comment('e.g. /api/feed/default');
            $table->string('method', 10)->default('GET');
            $table->integer('response_code')->comment('200, 404, 429, 500...');
            $table->integer('response_time_ms')->comment('timing in ms');
            $table->integer('items_returned')->default(0);
            $table->string('caller_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamps();

            $table->index(['api_key', 'requested_at']);
            $table->index(['endpoint', 'requested_at']);
        });

        // Rate limit tracking
        Schema::create('api_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('api_key', 64)->nullable()->index();
            $table->string('endpoint', 200);
            $table->integer('window_start_minute')->comment('minute-of-hour (0-59) for sliding window');
            $table->integer('request_count')->default(1);
            $table->timestamps();

            $table->unique(['api_key', 'endpoint', 'window_start_minute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage_analytics');
        Schema::dropIfExists('api_rate_limits');
    }
};
