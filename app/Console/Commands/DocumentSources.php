<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Write down what this project has learned about each publisher.
 *
 * All of it was discovered the hard way and then kept in places that cannot be
 * read together - commit messages, the crawler's own fetch_recipe, and whoever
 * happened to be looking. So the same discoveries get made twice.
 *
 * This puts it in the sources table where the panel shows it, a human can edit
 * it, and a model asked to fix the crawler next year can read it.
 *
 * It never overwrites a note somebody has edited unless --force says so: the
 * point is to seed the handbook, not to argue with the newsroom.
 */
class DocumentSources extends Command
{
    protected $signature = 'sources:document {--force : overwrite notes that already exist}';

    protected $description = 'Seed each source with what to expect, how to extract it, and its technical traps';

    /**
     * Matched on the source name, case-insensitively, as a prefix.
     *
     * Interval is in minutes; hour is Kuala Lumpur time and only means anything
     * for sources read once a day.
     */
    private const HANDBOOK = [
        'Free Malaysia Today' => [
            'expect' => 'General Malaysian news in English, and the most productive feed we have - around 30 stories a run. Politics, nation and courts dominate; the section feeds add sports, business and lifestyle.',
            'extract' => 'Ordinary WordPress RSS. The feed gives title, link and date; the body is fetched separately from the article page and read with trafilatura, which works reliably here.',
            'tech' => 'The section feeds live on cms.freemalaysiatoday.com, a different host from the public site - do not "correct" them to www. Timestamps carry a correct +0800 offset.',
            'interval' => 15,
        ],

        'Malay Mail' => [
            'expect' => 'General Malaysian news in English, strong on Klang Valley crime, courts and city government - the kind of story Near Me depends on.',
            'extract' => 'Read from <content:encoded> in the RSS feed, NOT from the article page. The extractor takes the feed copy first and only falls back to fetching the page. SOLVED 2026-09-01: the article pages answer our crawler with 403, but the FEED hands over the whole article anyway - 9,500 characters of the very article whose page refuses us. No user-agent games were needed, and none should be added.',
            'tech' => 'The 403 is no longer a problem and must not be worked around: the feed gives the full text without asking the publisher for anything they have not already published. Timestamps are labelled +0800 correctly, unlike Berita Harian and Harian Metro.',
            'interval' => 15,
        ],

        'New Straits Times' => [
            'expect' => 'General and national news in English, high volume, good coverage of government announcements and the courts.',
            'extract' => 'Read from <content:encoded> in the RSS feed, NOT from the article page. The extractor takes the feed copy first and only falls back to fetching the page. The article page is useless here: nst.com.my renders its articles in the browser, so the HTML we download contains no prose at all and trafilatura returns about 800 characters of navigation menu - long enough to be stored as a success. The feed carries the whole article, up to 15,000 characters.',
            'tech' => 'Server HTML has ZERO prose paragraphs - checked. Its JSON-LD block carries only the headline and a 216-character description, no articleBody. Anything that reads the page will get menus. Read the feed.',
            'interval' => 15,
        ],

        'Berita Harian' => [
            'expect' => 'General news in Malay. Feeds the Malay reading language directly and, through translation, the other two.',
            'extract' => 'Read from <content:encoded> in the RSS feed, NOT from the article page. The extractor takes the feed copy first and only falls back to fetching the page. Berita Harian renders in the browser and its pages answer 403 in any case, so the feed is the only copy obtainable. It carries around 2,500 characters per article.',
            'tech' => 'DANGEROUS TIMESTAMPS. It stamps Malaysian local time but labels the offset +0000, putting every story 8 hours in the future - enough to outrank real breaking news for ever. Judged per timestamp in FetchNewsFeeds::normalisePublishedAt(), never per publisher by name, because Malay Mail labels the same field correctly.',
            'interval' => 15,
        ],

        'Harian Metro' => [
            'expect' => 'Popular Malay-language news: crime, human interest, local incidents. Reliable volume.',
            'extract' => 'Read from <content:encoded> in the RSS feed, NOT from the article page. The extractor takes the feed copy first and only falls back to fetching the page. Harian Metro renders in the browser - the downloaded page has no prose in it - so the feed is the only copy. Around 2,600 characters per article.',
            'tech' => 'Same future-dated timestamp fault as Berita Harian: local time labelled +0000. Handled per timestamp, not per publisher.',
            'interval' => 15,
        ],

        'Utusan' => [
            'expect' => 'General news in Malay, moderate volume.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Nothing unusual recorded yet. If volume drops to zero, check whether the feed path has moved before deactivating.',
            'interval' => 30,
        ],

        'Google News Malaysia' => [
            'expect' => 'Headlines aggregated from every Malaysian publisher, in several languages. Not a publisher in its own right: its value is breadth and, above all, discovery.',
            'extract' => 'Each item carries a <source url="..."> element naming the publisher who actually wrote it. That element is the seed for self-learning source discovery - it is how this project grew from 9 sources to 30.',
            'tech' => 'TWO TRAPS. The link is a Google redirect, not the article, so the reader gets a hop instead of the publisher; de-duplication deliberately prefers the publisher\'s own feed when it has the same story. And the URLs run to 645 characters, which is why feed_ready_items.url had to be widened - short columns silently failed whole batches.',
            'interval' => 15,
        ],

        'The Star' => [
            'expect' => 'The largest English daily, with real sections for business, sport, property, food and motoring - the richest source of sector URLs available to us.',
            'extract' => 'RSS per section rather than one firehose. Worth adding each section as its own child source so empty sub-categories fill.',
            'tech' => 'Currently inactive because the feed returned nothing when last tried. Re-probe before writing it off; this is the single most valuable source still missing.',
            'interval' => 30,
        ],

        'Sin Chew Daily' => [
            'expect' => 'Major Chinese-language daily. Would feed the Chinese reading language from an original rather than a translation, which reads better.',
            'extract' => 'WordPress-style feed at /feed.',
            'tech' => 'Inactive. Chinese sources are the thinnest part of the pipeline - every Chinese story on the site today is translated from English or Malay.',
            'interval' => 30,
        ],

        'China Press' => [
            'expect' => 'Chinese-language daily, general news.',
            'extract' => 'Feed at /feed.',
            'tech' => 'Inactive; same gap as Sin Chew.',
            'interval' => 30,
        ],

        'Oriental Daily' => [
            'expect' => 'Chinese-language daily.',
            'extract' => 'No feed path confirmed yet - only the base URL is known.',
            'tech' => 'Inactive. Needs feed autodiscovery run against it before it can be used.',
            'interval' => 60,
        ],

        'The Edge' => [
            'expect' => 'Business, markets and property. The natural source for Markets & Finance and Property & Real Estate, both of which are thin.',
            'extract' => 'RSS exists but has been unreliable. The site is Next.js and carries article data in embedded JSON.',
            'tech' => 'Inactive. Worth the effort: it is the best Malaysian business source and those categories currently depend on general dailies.',
            'interval' => 60,
        ],

        'Bernama' => [
            'expect' => 'The national news agency: government announcements, official statements, first word on national incidents.',
            'extract' => 'RSS by section id.',
            'tech' => 'Inactive. As the state agency its copy is widely syndicated, so expect heavy duplication against every other source - de-duplication will earn its keep here.',
            'interval' => 30,
        ],

        'paultan.org' => [
            'expect' => 'Cars, launches, road policy, fuel prices. The obvious fix for an empty Automotive category.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Inactive. Enthusiast site rather than a newsroom, so the news-value check matters more here than elsewhere: much of it is reviews rather than events.',
            'interval' => 60,
        ],

        'Lowyat' => [
            'expect' => 'Technology and consumer news, plus Malaysia\'s largest forum. Feeds Technology & Digital.',
            'extract' => 'WordPress RSS for the news site. The forum is a different thing entirely and is not read.',
            'tech' => 'Inactive. Forum threads are not news and must not be ingested as if they were.',
            'interval' => 60,
        ],

        'South China Morning Post' => [
            'expect' => 'Hong Kong and regional news in English. High volume - 67 stories at last count - and mostly not about Malaysia.',
            'extract' => 'Standard RSS.',
            'tech' => 'Its volume is why the Malaysia relevance filter exists. Most of what it sends is now correctly refused; what survives is regional news with a genuine Malaysian angle. Watch the ratio: if almost everything is refused, the requests are being wasted.',
            'interval' => 60,
        ],

        'Borneo Post' => [
            'expect' => 'Sarawak and Sabah news. The only real East Malaysian coverage in the list, and the reason Kuching appears in the feed at all.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Keep this one active. Without it the site is a Klang Valley site.',
            'interval' => 30,
        ],

        'Daily Express Sabah' => [
            'expect' => 'Sabah news. Would balance Borneo Post\'s Sarawak lean.',
            'extract' => 'Feed path not yet confirmed.',
            'tech' => 'Inactive; needs autodiscovery.',
            'interval' => 60,
        ],

        'Sarawak Tribune' => [
            'expect' => 'Sarawak news.',
            'extract' => 'Feed path not yet confirmed.',
            'tech' => 'Inactive; needs autodiscovery.',
            'interval' => 60,
        ],

        'KLSE Screener' => [
            'expect' => 'Bursa Malaysia company announcements and market news.',
            'extract' => 'Not a news feed in the ordinary sense - announcements need their own adapter, and the exchange requires a bootstrap session before it will answer.',
            'tech' => 'Inactive and treated as an aggregator by de-duplication. The Bursa adapter is designed but not built.',
            'interval' => 1440,
            'hour' => 18,
        ],

        'Fintech News Malaysia' => [
            'expect' => 'Payments, banking technology, digital economy. Narrow but genuinely local.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Low volume by nature. Once a day is enough.',
            'interval' => 1440,
            'hour' => 9,
        ],

        'BusinessToday' => [
            'expect' => 'Malaysian business and corporate news.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Moderate volume; hourly is ample.',
            'interval' => 60,
        ],

        'Business Traveller' => [
            'expect' => 'Aviation and travel. Feeds Travel, which has few other sources.',
            'extract' => 'Standard RSS.',
            'tech' => 'International publication - most of its output has no Malaysian angle and is refused by the relevance filter. Kept for the Travel category rather than for volume.',
            'interval' => 1440,
            'hour' => 8,
        ],

        'CodeBlue' => [
            'expect' => 'Health policy and the Malaysian health system. The main source for Health.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Low volume, high relevance. Once or twice a day is enough.',
            'interval' => 720,
        ],

        'aliran' => [
            'expect' => 'Civil society commentary and analysis.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Largely opinion rather than reporting, so expect a high refusal rate from the news-value check. That is correct behaviour, not a fault.',
            'interval' => 1440,
            'hour' => 10,
        ],

        'Law.asia' => [
            'expect' => 'Regional legal and regulatory news.',
            'extract' => 'Standard WordPress RSS.',
            'tech' => 'Regional rather than Malaysian; most items are refused by the relevance filter.',
            'interval' => 1440,
            'hour' => 11,
        ],

        'Malaysiakini' => [
            'expect' => 'Politics and investigative reporting in English.',
            'extract' => 'RSS at /rss/en/news.rss.',
            'tech' => 'Much of the site is behind a subscription. Expect headlines and standfirsts rather than full text, so classification often runs on the headline alone.',
            'interval' => 30,
        ],

        'The Sun' => [
            'expect' => 'General English daily with useful section feeds - sports, business, lifestyle, property and motoring all exist as separate URLs.',
            'extract' => 'Standard RSS per section.',
            'tech' => 'The section feeds are on thesun.my while the parent is on www.thesun.my. Both work; do not normalise one into the other without testing.',
            'interval' => 15,
        ],

        'Human Resources Online' => [
            'expect' => 'Employment and workplace news across the region.',
            'extract' => 'Feed path not confirmed.',
            'tech' => 'Inactive; regional rather than Malaysian.',
            'interval' => 1440,
            'hour' => 12,
        ],

        'Xinhua' => [
            'expect' => 'Chinese state news agency, international coverage.',
            'extract' => 'Feed path not confirmed.',
            'tech' => 'Inactive. Very little Malaysian relevance; would mostly be refused.',
            'interval' => 1440,
            'hour' => 13,
        ],

        'Focus Malaysia' => [
            'expect' => 'Business and general Malaysian news.',
            'extract' => 'Feed path not confirmed.',
            'tech' => 'Inactive; needs autodiscovery.',
            'interval' => 60,
        ],

        'The Vibes' => [
            'expect' => 'General Malaysian news and features.',
            'extract' => 'Feed path not confirmed.',
            'tech' => 'Inactive; needs autodiscovery.',
            'interval' => 60,
        ],

        'kln.gov.my' => [
            'expect' => 'Foreign ministry statements: Malaysians abroad, travel advisories, diplomatic announcements.',
            'extract' => 'Government site with no confirmed feed; would need index-page crawling.',
            'tech' => 'Inactive. Valuable for the "Malaysians abroad" stories the relevance filter is built to keep.',
            'interval' => 1440,
            'hour' => 16,
        ],
    ];

    /** Notes applied to a section child when its parent has none of its own. */
    private const SECTION_NOTES = [
        'sports' => 'Sport only: match reports, results, transfers, national squads. This is what fills the Badminton, Football and Motorsports sub-categories, which are otherwise empty.',
        'business' => 'Company results, appointments, deals and the economy. Feeds Business & Corporate and Markets & Finance.',
        'lifestyle' => 'Food, culture, health and living. Expect a higher refusal rate from the news-value check than a news feed - much lifestyle copy is advisory rather than an event.',
        'property' => 'Launches, transactions, planning and housing policy. Feeds Property & Real Estate, which is thin.',
        'automotive' => 'Cars, launches, road policy and fuel prices. Feeds Automotive, which is otherwise empty.',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $written = 0;
        $kept = 0;

        foreach (DB::table('sources')->orderBy('id')->get() as $source) {
            $entry = $this->lookup($source);

            if ($entry === null) {
                continue;
            }

            $changes = [];

            foreach (['expect_note' => 'expect', 'extract_note' => 'extract', 'tech_note' => 'tech'] as $column => $key) {
                if (!isset($entry[$key])) {
                    continue;
                }

                // Never argue with an edit somebody made by hand.
                if (!$force && trim((string) ($source->{$column} ?? '')) !== '') {
                    continue;
                }

                $changes[$column] = $entry[$key];
            }

            if (isset($entry['interval']) && ($force || $source->fetch_interval_minutes === null)) {
                $changes['fetch_interval_minutes'] = $entry['interval'];
            }

            if (isset($entry['hour']) && ($force || $source->fetch_at_hour === null)) {
                $changes['fetch_at_hour'] = $entry['hour'];
            }

            if ($changes === []) {
                $kept++;
                continue;
            }

            $changes['notes_updated_at'] = now();

            DB::table('sources')->where('id', $source->id)->update($changes);
            $written++;

            $this->line("  documented: {$source->name}");
        }

        $this->info("Wrote notes for {$written} source(s); left {$kept} alone.");

        return self::SUCCESS;
    }

    /**
     * A publisher's own entry, or the generic note for its section.
     */
    private function lookup(object $source): ?array
    {
        $name = mb_strtolower(trim((string) $source->name));

        foreach (self::HANDBOOK as $prefix => $entry) {
            if (str_starts_with($name, mb_strtolower($prefix))) {
                // A child inherits its parent's extraction and technical notes,
                // which are properties of the publisher, but describes its own
                // section for what to expect.
                if ($source->parent_source_id && isset(self::SECTION_NOTES[$source->section])) {
                    $entry['expect'] = self::SECTION_NOTES[$source->section];

                    if (!isset($entry['interval'])) {
                        $entry['interval'] = 60;
                    }
                }

                return $entry;
            }
        }

        if ($source->section && isset(self::SECTION_NOTES[$source->section])) {
            return [
                'expect' => self::SECTION_NOTES[$source->section],
                'interval' => 60,
            ];
        }

        return null;
    }
}
