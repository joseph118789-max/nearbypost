<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses the crawler must not visit again.
 *
 * Deleting a junk source is not enough, because discovery puts it back. The
 * crawler learns new sources from what the aggregator cites and from feeds
 * declared on publishers' pages, so anything removed by hand reappears on the
 * next discovery run - and an editor who deletes the same dead feed three weeks
 * running is being ignored by the software.
 *
 * A blocked address is therefore a standing instruction rather than a deletion:
 * the fetcher skips it, discovery will not re-add it, and the reason is kept so
 * whoever wonders why a source is missing can find out.
 *
 * Blocking a host blocks everything under it. Junk usually arrives by the
 * domain rather than by the page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blocked_urls', function (Blueprint $table) {
            $table->id();

            // The exact address, or empty when the whole host is blocked.
            $table->string('url', 1000)->nullable();

            // Always set: matching by host is what stops a junk domain coming
            // back as a slightly different path.
            $table->string('host', 255);

            // 'url' blocks one address, 'host' blocks everything on it.
            $table->string('scope', 10)->default('url');

            $table->string('reason', 300)->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();

            $table->timestamps();

            $table->index('host');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_urls');
    }
};
