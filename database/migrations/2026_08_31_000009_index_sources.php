<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crawl section pages, not only feeds.
 *
 * Relying purely on RSS caps coverage at whatever a publisher chooses to syndicate,
 * and silently loses any publisher whose feed has moved or been retired. Two of
 * the largest Malaysian outlets were in exactly that position: The Star and
 * Bernama were both parked here because their advertised feeds return 404, so
 * they have been contributing nothing at all.
 *
 * Their section pages work perfectly well. The Star lists 9 to 14 articles per
 * section; Bernama publishes at general/news.php?id=... So a source can now be
 * an HTML index as well as a feed.
 *
 * link_pattern records how to recognise an article link on that particular site,
 * which is the same idea as fetch_recipe: knowledge about a source, kept with
 * the source, rather than in someone's head.
 *
 * New Straits Times is deliberately not added as an index source - its section
 * page renders the article list in JavaScript, so a plain fetch sees only
 * navigation. Its feed works, and that is enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('source_kind', 12)->default('rss')->after('source_type');
            $table->string('index_url')->nullable()->after('rss_url');
            $table->string('link_pattern')->nullable()->after('index_url');
        });

        DB::table('sources')->update(['source_kind' => 'rss']);

        $now = now();

        // Section pages verified reachable and link-bearing on 2026-08-31.
        // [name, base_url, index_url, link_pattern, language, tier, section]
        $rows = [
            ['The Star - News', 'https://www.thestar.com.my', 'https://www.thestar.com.my/news/', '#/news/[a-z-]+/\d{4}/\d{2}/\d{2}/#', 'English', 'primary', 'news'],
            ['The Star - Business', 'https://www.thestar.com.my', 'https://www.thestar.com.my/business/', '#/business/[a-z-]+/\d{4}/\d{2}/\d{2}/#', 'English', 'primary', 'business'],
            ['The Star - Metro', 'https://www.thestar.com.my', 'https://www.thestar.com.my/metro/', '#/metro/[a-z-]+/\d{4}/\d{2}/\d{2}/#', 'English', 'secondary', 'metro'],
            ['Bernama - English', 'https://www.bernama.com', 'https://www.bernama.com/en/', '#/news\.php\?id=\d+#', 'English', 'primary', 'general'],
            ['Bernama - Malay', 'https://www.bernama.com', 'https://www.bernama.com/bm/', '#/news\.php\?id=\d+#', 'Malay', 'secondary', 'general'],
            ['Malay Mail - Malaysia', 'https://www.malaymail.com', 'https://www.malaymail.com/news/malaysia', '#/news/[a-z]+/\d{4}/\d{2}/\d{2}/#', 'English', 'secondary', 'malaysia'],
            ['The Sun - News Index', 'https://thesun.my', 'https://thesun.my/news', '#/news/[a-z0-9-]{15,}#', 'English', 'secondary', 'news'],
        ];

        $insert = [];

        foreach ($rows as [$name, $base, $index, $pattern, $language, $tier, $section]) {
            if (DB::table('sources')->where('index_url', $index)->exists()) {
                continue;
            }

            $insert[] = [
                'name'                  => $name,
                'base_url'              => $base,
                'rss_url'               => null,
                'index_url'             => $index,
                'link_pattern'          => $pattern,
                'source_kind'           => 'index',
                'language'              => $language,
                'source_type'           => 'mainstream',
                'direct_rss_supported'  => false,
                'google_news_supported' => true,
                'is_active'             => true,
                'priority_tier'         => $tier,
                'discovery_status'      => 'manual',
                'discovered_from'       => 'approved seed list',
                'section'               => $section,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('sources')->insert($insert);
        }
    }

    public function down(): void
    {
        DB::table('sources')->where('source_kind', 'index')->delete();

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['source_kind', 'index_url', 'link_pattern']);
        });
    }
};
