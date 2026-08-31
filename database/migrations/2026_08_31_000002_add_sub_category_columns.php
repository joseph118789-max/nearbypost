<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-category storage (Category Setup v2.0 s10, s16).
 *
 * The 156-row weighted `subcategories` taxonomy has been in the database since
 * April but nothing ever wrote against it - every article was filed as
 * secondary_category='general'.
 *
 * sub_category is kept separate from secondary_category on purpose. In the spec
 * those are two different fields: `s` is a competing *primary* category, `sc` is
 * the sub-category within the chosen primary. Overloading one column would make
 * the remaining v2.0 work impossible to add cleanly later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('secondary_category');
        });

        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->string('sub_category')->nullable()->after('secondary_category');
            $table->index(['primary_category', 'sub_category'], 'fri_primary_sub_idx');
        });
    }

    public function down(): void
    {
        Schema::table('feed_ready_items', function (Blueprint $table) {
            $table->dropIndex('fri_primary_sub_idx');
            $table->dropColumn('sub_category');
        });

        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn('sub_category');
        });
    }
};
