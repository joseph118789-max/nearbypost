<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the article was, so a re-fetch can be checked against it.
 *
 * Measured on this pipeline: one day after publication, only two thirds of
 * articles come back unchanged. Two publishers answer 403 to a direct re-read
 * while their feeds work fine, and three returned a consent stub of exactly 814
 * characters under an HTTP 200 - not an error, just the wrong text.
 *
 * That last case is why a length and an opening are kept when the body goes. A
 * re-fetch that arrives at a quarter of the original size, sharing nothing with
 * the first paragraph we recorded, is a paywall rather than the news, and has
 * to be refused rather than judged. Without these two columns there is nothing
 * to refuse it against.
 *
 * About 220 bytes against the 1,837 the body cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extraction_jobs', function (Blueprint $table) {
            $table->integer('original_length')->nullable()->after('extracted_text');
            $table->string('body_opening', 300)->nullable()->after('original_length');
        });
    }

    public function down(): void
    {
        Schema::table('extraction_jobs', function (Blueprint $table) {
            $table->dropColumn(['original_length', 'body_opening']);
        });
    }
};
