<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House rules, in the newsroom's own words, that the reviewer must apply.
 *
 * The rules the reviewer works to have been written into a PHP prompt, which
 * means changing one is a deployment and reading them requires opening a source
 * file. That is the wrong place for editorial policy: policy belongs to the
 * people who run the site, and they should be able to add "no jokes at other
 * people's expense" on a Tuesday afternoon without anyone touching code.
 *
 * The rules are stored as sentences rather than as flags because they are given
 * to a model, and a model applies "no personal opinion dressed up as reporting"
 * better than it applies `opinion = false`. It also lets a rule carry its own
 * exception, which no flag can.
 *
 * `applies_to` matters: a contributor writing in good faith and a scraped
 * newspaper article fail in different ways, and a rule aimed at one is noise in
 * the other's prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_rules', function (Blueprint $table) {
            $table->id();

            $table->text('rule');

            // contributor | scraper | both
            $table->string('applies_to', 20)->default('both');

            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'applies_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_rules');
    }
};
