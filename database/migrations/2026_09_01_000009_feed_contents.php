<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The article text the feed already gave us.
 *
 * Four of the largest Malaysian publishers - New Straits Times, Harian Metro,
 * Berita Harian and Malay Mail - produced zero successful extractions between
 * them, and for two different reasons that both looked like the same failure.
 * NST and Harian Metro render their articles in the browser, so the page we
 * download has no prose in it at all. Malay Mail answers our crawler with 403.
 *
 * Both are moot: all four publish <content:encoded> in the RSS we were already
 * downloading, carrying the whole article. Malay Mail's feed hands over 9,574
 * characters of the very article whose page refuses us.
 *
 * So the text is kept here at fetch time, keyed by the address, and the
 * extraction step takes it instead of going back out to the web. No extra
 * request, no 403 to work around, no JavaScript to run - and nothing that asks
 * a publisher for anything they have not already published to us.
 *
 * Rows are disposable: they exist only until extraction has consumed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_contents', function (Blueprint $table) {
            $table->id();

            // The article address, which is what extraction knows to ask for.
            $table->string('url', 1000);
            $table->text('text');
            $table->string('source', 255)->nullable();

            $table->timestamps();

            // Postgres will not index 1000 characters of text; the hash is
            // what the lookup and the uniqueness are actually done on.
            $table->string('url_hash', 40)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_contents');
    }
};
