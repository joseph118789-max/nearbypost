<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_code')->nullable()->unique()->after('id');
            $table->string('mobile')->nullable()->after('user_code');
            $table->string('wa_group')->nullable()->after('mobile');
            $table->string('interest_sub_cat')->nullable()->after('wa_group');
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending')->after('interest_sub_cat');
            $table->string('location_name')->nullable()->after('status');
            $table->timestamp('join_date')->nullable()->after('location_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['user_code', 'mobile', 'wa_group', 'interest_sub_cat', 'status', 'location_name', 'join_date']);
        });
    }
};
