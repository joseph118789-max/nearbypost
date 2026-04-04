<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('group_id');
            // Notification preferences
            $table->boolean('notify_email')->default(true)->after('preferences');
            $table->boolean('notify_webhook')->default(false)->after('notify_email');
            $table->boolean('notify_in_app')->default(true)->after('notify_webhook');
            $table->string('email')->nullable()->unique()->after('notify_in_app');
            $table->string('webhook_url')->nullable()->after('email');
            // Rate limit settings
            $table->integer('max_notifications_per_hour')->default(10)->after('webhook_url');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn([
                'preferences',
                'notify_email',
                'notify_webhook',
                'notify_in_app',
                'email',
                'webhook_url',
                'max_notifications_per_hour',
            ]);
        });
    }
};