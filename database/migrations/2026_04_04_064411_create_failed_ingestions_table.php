<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_ingestions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 255);
            $table->string('url', 2048)->nullable();
            $table->string('title', 500)->nullable();
            $table->json('raw_payload');
            $table->text('failure_reason');
            $table->integer('retry_count')->default(0);
            $table->timestamp('failed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_ingestions');
    }
};
