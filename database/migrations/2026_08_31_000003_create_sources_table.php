<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source registry (Manual V6 s6.2, s6.3).
 *
 * Ingestion used to carry its feed list hard-coded inside a shell script, so
 * adding or disabling a source meant editing code on the production server.
 * Worse, the script fetched with simplexml_load_file(), which follows no
 * redirects and sends no user agent: two of its three feeds had been failing
 * silently for months and every article in the fortnight before this migration
 * came from the one remaining source.
 *
 * Sources live in the database so they can be enabled, disabled and added
 * without a deploy, and so a fetch failure is recorded against the source that
 * caused it instead of disappearing.
 *
 * priority_tier follows s6.3: primary polls every cycle, secondary less often,
 * parked is registered but not fetched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url')->nullable();
            $table->string('rss_url')->nullable()->unique();
            $table->string('language', 20)->default('English');
            $table->string('source_type', 40)->default('mainstream');
            $table->boolean('direct_rss_supported')->default(true);
            $table->boolean('google_news_supported')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('priority_tier', 20)->default('primary');
            $table->unsignedBigInteger('publisher_id')->nullable();

            // Fetch health, so a silently dying feed is visible.
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_status', 40)->nullable();
            $table->unsignedInteger('last_item_count')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'priority_tier']);
        });

        $now = now();

        // Verified against the live feeds on 2026-08-31: each of these returned
        // a parseable channel with items. Bernama, The Edge and the Chinese and
        // Tamil titles from the manual's inventory are registered as parked -
        // their advertised feed URLs 404 or block automated clients, and they
        // can be enabled from the admin panel once a working URL is found.
        $rows = [
            ['Free Malaysia Today', 'https://www.freemalaysiatoday.com', 'https://www.freemalaysiatoday.com/feed/', 'English', 'online', true, true, true, 'primary'],
            ['Malay Mail', 'https://www.malaymail.com', 'https://www.malaymail.com/feed/rss/malaysia', 'English', 'online', true, true, true, 'primary'],
            ['New Straits Times', 'https://www.nst.com.my', 'https://www.nst.com.my/feed', 'English', 'mainstream', true, true, true, 'primary'],
            ['The Sun', 'https://www.thesun.my', 'https://www.thesun.my/rss', 'English', 'mainstream', true, true, true, 'primary'],
            ['Malaysiakini', 'https://www.malaysiakini.com', 'https://www.malaysiakini.com/rss/en/news.rss', 'English', 'online', true, true, true, 'primary'],
            ['Berita Harian', 'https://www.bharian.com.my', 'https://www.bharian.com.my/feed', 'Malay', 'mainstream', true, true, true, 'primary'],
            ['Harian Metro', 'https://www.hmetro.com.my', 'https://www.hmetro.com.my/feed', 'Malay', 'tabloid', true, true, true, 'primary'],
            ['Utusan', 'https://www.utusan.com.my', 'https://www.utusan.com.my/feed/', 'Malay', 'mainstream', true, true, true, 'secondary'],
            ['Google News Malaysia', 'https://news.google.com', 'https://news.google.com/rss/search?q=Malaysia&hl=en-MY&gl=MY&ceid=MY:en', 'Multi', 'aggregator', false, true, true, 'primary'],

            ['Bernama', 'https://www.bernama.com', 'https://www.bernama.com/en/rss.php?id=news', 'Malay/Eng', 'agency', true, true, false, 'parked'],
            ['The Edge', 'https://www.theedgemalaysia.com', 'https://www.theedgemalaysia.com/rss', 'English', 'business', false, true, false, 'parked'],
            ['The Star', 'https://www.thestar.com.my', 'https://www.thestar.com.my/rss/news', 'English', 'mainstream', true, true, false, 'parked'],
            ['Sin Chew Daily', 'https://www.sinchew.com.my', 'https://www.sinchew.com.my/feed', 'Chinese', 'mainstream', true, true, false, 'parked'],
            ['China Press', 'https://www.chinapress.com.my', 'https://www.chinapress.com.my/feed', 'Chinese', 'mainstream', true, true, false, 'parked'],
            ['Oriental Daily', 'https://www.orientaldaily.com.my', null, 'Chinese', 'mainstream', false, true, false, 'parked'],
            ['The Vibes', 'https://www.thevibes.com', null, 'English', 'online', false, true, false, 'parked'],
            ['Focus Malaysia', 'https://focusmalaysia.my', null, 'English', 'business', false, true, false, 'parked'],
            ['Daily Express Sabah', 'https://www.dailyexpress.com.my', null, 'English', 'state', false, true, false, 'parked'],
            ['Sarawak Tribune', 'https://www.newsarawaktribune.com.my', null, 'English', 'state', false, true, false, 'parked'],
            ['paultan.org', 'https://paultan.org', 'https://paultan.org/feed/', 'English', 'niche', true, true, false, 'parked'],
            ['Lowyat', 'https://www.lowyat.net', 'https://www.lowyat.net/feed/', 'English', 'niche', true, true, false, 'parked'],
        ];

        $insert = [];
        foreach ($rows as $r) {
            $insert[] = [
                'name'                  => $r[0],
                'base_url'              => $r[1],
                'rss_url'               => $r[2],
                'language'              => $r[3],
                'source_type'           => $r[4],
                'direct_rss_supported'  => $r[5],
                'google_news_supported' => $r[6],
                'is_active'             => $r[7],
                'priority_tier'         => $r[8],
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        DB::table('sources')->insert($insert);
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
