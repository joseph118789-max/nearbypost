<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subscribers', 'preferences')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->jsonb('preferences')->nullable()->after('status');
            });
        }
        if (!Schema::hasColumn('subscribers', 'preferred_categories')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->jsonb('preferred_categories')->nullable()->after('preferences');
            });
        }
        if (!Schema::hasColumn('subscribers', 'alert_radius_km')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->float('alert_radius_km')->nullable()->after('preferred_categories');
            });
        }
        if (!Schema::hasColumn('subscribers', 'location_lat')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->float('location_lat')->nullable()->after('alert_radius_km');
            });
        }
        if (!Schema::hasColumn('subscribers', 'location_lng')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->float('location_lng')->nullable()->after('location_lat');
            });
        }
        if (!Schema::hasColumn('subscribers', 'notify_email')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->boolean('notify_email')->default(true)->after('location_lng');
            });
        }
        if (!Schema::hasColumn('subscribers', 'notify_webhook')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->boolean('notify_webhook')->default(false)->after('notify_email');
            });
        }
        if (!Schema::hasColumn('subscribers', 'notify_in_app')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->boolean('notify_in_app')->default(true)->after('notify_webhook');
            });
        }
        if (!Schema::hasColumn('subscribers', 'max_notifications_per_hour')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->integer('max_notifications_per_hour')->default(10)->after('notify_in_app');
            });
        }
        if (!Schema::hasColumn('news_items', 'location_label')) {
            Schema::table('news_items', function (Blueprint $table) {
                $table->string('location_label', 255)->nullable()->after('main_place_text');
            });
        }
    }

    public function down(): void
    {
        $subCols = ['preferences','preferred_categories','alert_radius_km',
                    'location_lat','location_lng','notify_email','notify_webhook',
                    'notify_in_app','max_notifications_per_hour'];
        foreach ($subCols as $col) {
            if (Schema::hasColumn('subscribers', $col)) {
                Schema::table('subscribers', fn(Blueprint $t) => $t->dropColumn($col));
            }
        }
        if (Schema::hasColumn('news_items', 'location_label')) {
            Schema::table('news_items', fn(Blueprint $t) => $t->dropColumn('location_label'));
        }
    }
};
