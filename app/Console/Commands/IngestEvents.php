<?php

namespace App\Console\Commands;

use App\Services\Ingest\DateWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Events, taken from sources that publish them as data rather than as prose.
 *
 * The RSS fetcher cannot reach these - almost nothing in the events business
 * publishes a feed - but both sources hand over exactly what this site needs
 * and in a form that cannot be misread:
 *
 *   AllEvents  schema.org MusicEvent on each event page: name, startDate,
 *              endDate, the venue and the town it is in.
 *   MyCEB      a JSON payload inside the calendar page: event_name,
 *              headquarter_hotel, physical_city, date_range.
 *
 * So these do NOT go to the classifier. Everything a classifier would be asked
 * to work out - what it is, where it is, when it runs - is already stated by
 * the publisher, and today's review showed the model getting places wrong that
 * an article stated plainly. Spending a request to re-derive a fact we were
 * handed, and risking a worse answer, would be the wrong trade.
 *
 * Run: php artisan ingest:events --source=allevents
 *      php artisan ingest:events --source=myceb
 */
class IngestEvents extends Command
{
    protected $signature = 'ingest:events
        {--source=all : allevents, myceb, or all}
        {--limit=40 : Most events to take in one run}
        {--dry-run : Report what would be stored and store nothing}';

    protected $description = 'Take events from AllEvents and MyCEB, which publish them as structured data';

    private const UA = 'Mozilla/5.0 (compatible; Nearbypost/1.0; +https://nearbypost.com)';

    private const ALLEVENTS_LISTING = 'https://allevents.in/kuala-lumpur/concerts';

    private const MYCEB_CALENDAR = 'https://www.myceb.com.my/events/calendar-of-events';

    public function handle(): int
    {
        $which = (string) $this->option('source');
        $limit = (int) $this->option('limit');

        $stored = 0;

        if (in_array($which, ['all', 'allevents'], true)) {
            $stored += $this->take('AllEvents', $this->fromAllEvents($limit));
        }

        if (in_array($which, ['all', 'myceb'], true)) {
            $stored += $this->take('MyCEB', $this->fromMyCeb($limit));
        }

        $this->newLine();
        $this->info(sprintf('%s %d events.', $this->option('dry-run') ? 'Would store' : 'Stored', $stored));

        return 0;
    }

    // ── AllEvents: one page per event, schema.org on each ──────────────────

    private function fromAllEvents(int $limit): array
    {
        $html = $this->get(self::ALLEVENTS_LISTING);

        if ($html === null) {
            $this->error('  AllEvents listing could not be read.');

            return [];
        }

        preg_match_all('#https://allevents\.in/kuala-lumpur/[a-z0-9\-]+/\d{10,}#', $html, $m);

        $urls = array_slice(array_values(array_unique($m[0])), 0, $limit);

        $this->line(sprintf('  AllEvents: %d event pages on the listing', count($urls)));

        $events = [];

        foreach ($urls as $url) {
            // Already taken. Asking again costs a request and changes nothing.
            if (DB::table('news_items')->where('url', $url)->exists()) {
                continue;
            }

            $page = $this->get($url);

            if ($page === null) {
                continue;
            }

            $event = $this->schemaEvent($page);

            if ($event === null) {
                $this->line('    no event data: ' . mb_substr($url, 0, 70));

                continue;
            }

            $event['url'] = $url;
            $events[] = $event;

            usleep(400000);   // a listing site, not a public API - do not hammer it
        }

        return $events;
    }

    /**
     * The first schema.org Event on a page, as this site needs it.
     */
    private function schemaEvent(string $html): ?array
    {
        if (!preg_match_all('#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#si', $html, $m)) {
            return null;
        }

        foreach ($m[1] as $block) {
            $data = json_decode(trim($block), true);

            if (!is_array($data)) {
                continue;
            }

            foreach ($this->flatten($data) as $node) {
                $type = $node['@type'] ?? '';

                if (!is_string($type) || !str_contains($type, 'Event')) {
                    continue;
                }

                $start = $this->day($node['startDate'] ?? null);

                if ($start === null) {
                    continue;
                }

                $venue = $node['location']['name'] ?? null;
                $town  = $node['location']['address']['addressLocality'] ?? null;

                return [
                    // Decoded: the markup carries &quot; and &amp;, and a
                    // headline reading 'Biru Mata Hitamku &quot;30 Tahun&quot;'
                    // is a headline nobody wrote.
                    'title' => html_entity_decode((string) ($node['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'start' => $start,
                    // A concert with no end runs for the evening, not forever.
                    'end'   => $this->day($node['endDate'] ?? null) ?? $start,
                    'place' => $this->place($venue, $town),
                ];
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> every object in a JSON-LD block */
    private function flatten(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $data['@graph'];
        }

        return array_is_list($data) ? $data : [$data];
    }

    // ── MyCEB: one JSON payload for the whole calendar ─────────────────────

    private function fromMyCeb(int $limit): array
    {
        $html = $this->get(self::MYCEB_CALENDAR);

        if ($html === null) {
            $this->error('  MyCEB calendar could not be read.');

            return [];
        }

        // The page carries its calendar as escaped JSON inside the markup.
        $decoded = str_replace(['\\u0026', '\\"', '\\/'], ['&', '"', '/'], $html);

        preg_match_all(
            '#"event_name":"(.*?)","event_web_address":"(.*?)".*?"headquarter_hotel":"(.*?)".*?"physical_city":"(.*?)","date_range":"(.*?)"#s',
            $decoded,
            $m,
            PREG_SET_ORDER
        );

        $this->line(sprintf('  MyCEB: %d entries in the calendar', count($m)));

        $events = [];

        foreach (array_slice($m, 0, $limit) as $row) {
            [, $name, $web, $venue, $city, $range] = $row;

            $window = DateWindow::parse($range . ' ');

            if ($window['start'] === null) {
                $this->line('    unreadable dates: ' . mb_substr($range, 0, 40));

                continue;
            }

            // Its own site is the story's address. Where there is none, the
            // calendar entry itself is - never a bare listing URL, which would
            // send every event to the same page.
            $url = str_starts_with($web, 'http') ? $web : self::MYCEB_CALENDAR . '#' . md5($name);

            $events[] = [
                'title' => html_entity_decode($name),
                'start' => $window['start'],
                'end'   => $window['end'] ?? $window['start'],
                'place' => $this->place($venue, $city),
                'url'   => $url,
            ];
        }

        return $events;
    }

    // ── shared ─────────────────────────────────────────────────────────────

    /**
     * Store what was found, skipping anything already held and anything whose
     * run is over.
     */
    private function take(string $source, array $events): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = date('Y-m-d');
        $stored = 0;

        foreach ($events as $event) {
            if (trim($event['title']) === '' || $event['end'] < $today) {
                continue;
            }

            if (DB::table('news_items')->where('url', $event['url'])->exists()) {
                continue;
            }

            $this->line(sprintf('    %s → %s  %-34s %s',
                $event['start'], $event['end'],
                mb_substr((string) ($event['place'] ?? '(nowhere)'), 0, 34),
                mb_substr($event['title'], 0, 46)
            ));

            $stored++;

            if ($dry) {
                continue;
            }

            DB::table('news_items')->insert([
                'title'        => mb_substr($event['title'], 0, 250),
                'url'          => mb_substr($event['url'], 0, 1000),
                'source'       => $source,
                'summary'      => $this->summary($event),
                'published_at' => now(),
                'published_precision' => 'scraped',   // the listing has no time; this is when we saw it
                'status'       => 'active',
                'is_article'   => true,

                // Stated by the publisher, not inferred. This is the whole
                // reason these two sources are worth the separate path.
                'event_start'      => $event['start'],
                'event_end'        => $event['end'],
                'event_open_ended' => false,
                'main_place_text'  => $event['place'],

                // Not sent to the classifier: what it is, where it is and when
                // it runs are already known, and a model asked to re-derive
                // them can only agree or be wrong.
                'ai_status'         => 'success',
                'ai_processed_at'   => now(),
                'ai_category'       => $source === 'AllEvents'
                    ? 'entertainment / arts & culture'
                    : 'business & corporate',
                'sub_category'      => $source === 'AllEvents'
                    ? 'Music & Performing Arts'
                    : 'Industry Development',
                'relevance_mode'    => $event['place'] ? 'location_and_category' : 'category_only',
                'malaysia_relevant' => true,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        return $stored;
    }

    private function summary(array $event): string
    {
        $when = $event['start'] === $event['end']
            ? date('j F Y', strtotime($event['start']))
            : date('j F', strtotime($event['start'])) . ' to ' . date('j F Y', strtotime($event['end']));

        return trim($event['title'] . '. ' . $when . ($event['place'] ? ', at ' . $event['place'] : '') . '.');
    }

    private function place(?string $venue, ?string $town): ?string
    {
        $venue = trim((string) $venue);
        $town  = trim((string) $town);

        // "TBA" is a venue nobody can be near. A conference whose hall is not
        // yet decided is national news until it is, and putting the letters
        // T-B-A through a geocoder is how a story ends up somewhere absurd.
        $unknown = ['tba', 'tbc', 'tbd', 'to be advised', 'to be announced', 'n/a', '-'];

        if (in_array(mb_strtolower($venue), $unknown, true)) {
            $venue = '';
        }

        if (in_array(mb_strtolower($town), $unknown, true)) {
            $town = '';
        }

        // Written the way every other place on this site is written: narrowest
        // first, so the geocoder is given an address rather than a riddle.
        $parts = array_filter([$venue, $town], fn ($p) => $p !== '');

        if ($parts === []) {
            return null;
        }

        // "Berjaya Times Square, Berjaya Times Square" helps nobody.
        if (count($parts) === 2 && str_contains(mb_strtolower($parts[0]), mb_strtolower($parts[1]))) {
            array_pop($parts);
        }

        return mb_substr(implode(', ', $parts), 0, 200);
    }

    private function day(?string $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d', $time);
    }

    private function get(string $url): ?string
    {
        try {
            $r = Http::withHeaders(['User-Agent' => self::UA, 'Accept' => 'text/html'])
                ->timeout(25)
                ->get($url);

            return $r->successful() ? $r->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
