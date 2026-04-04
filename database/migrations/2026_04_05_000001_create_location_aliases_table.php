<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias_text', 200)->comment('e.g. PJ, KL, Klang Valley');
            $table->string('canonical_name', 500)->comment('e.g. Petaling Jaya, Kuala Lumpur');
            $table->string('alias_type', 30)->comment('city, district, region, area');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Exact lookup, case-insensitive
            $table->unique(['alias_text']);
            $table->index(['alias_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_aliases');
    }
};
