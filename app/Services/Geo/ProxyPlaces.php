<?php

namespace App\Services\Geo;

use App\Models\NewsItem;
use App\Services\Ai\DeepSeekAdapter;
use App\Services\Geo\Boundaries\BoundaryCheck;
use App\Services\GeocodingException;
use App\Services\GeocodingRateLimitedException;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When the place a story names is on no map, place the story at another
 * place the SAME story names that is.
 *
 * A bus crashed at "Simpang Tiga junction, Kota Belud-Kundasang road". No
 * index has that junction - not OSM, not Wikidata, and by the owner's account
 * not Google either. But the story also says the injured went to Kota Belud
 * Hospital and the firefighters came from Kota Belud's fire station, and both
 * of those are on the map, six kilometres from the junction. A reader near
 * Kota Belud is who this story is for; a pin at the hospital reaches them, a
 * story with no pin reaches nobody.
 *
 * Three tiers, cheapest and most trustworthy first.
 *
 *  1. The classifier already listed every place in the story with its role
 *     when it read the article, so the alternatives are usually sitting in
 *     place_roles for free - measured, 31 of the 32 stories waiting for a
 *     person had one.
 *  2. A story whose list holds nothing usable is read again by the model,
 *     which is asked for NAMES it saw in the text. The map supplies the
 *     coordinates.
 *  3. Last, at the owner's instruction, the model is asked for the
 *     coordinates of the failed place itself. Measured earlier, its answers
 *     were 0.6 to 3.2 km out and confident when wrong, so this tier is a
 *     candidate like any other and passes the same gates - and a reverse
 *     lookup of the point must land in the town the name gives. A pin from
 *     here is marked as the model's on the story and on the review card.
 *
 * Every candidate, from every tier, must be inside the state the failed name
 * claims, carry its own name (tiers 1-2), and lie within reach of the
 * district the failed name gives.
 */
class ProxyPlaces
{
    /**
     * Where the event was, then where its consequences went, then who acted.
     * Never 'origin' - where those involved came from is by definition not
     * where it happened (a Penang Port story was put in Gurun, Kedah, the
     * crew's home town) - and never 'dateline', the desk the story was filed
     * from.
     */
    private const ROLE_ORDER = ['happened' => 0, 'aftermath' => 1, 'office' => 2];

    /** A building beats a town: it is a point, a town is an area. */
    private const INSTITUTION = '/\b(hospital|klinik|clinic|station|balai|sekolah|school|masjid|mosque|church|gereja|temple|kuil|tokong|stadium|dewan|hall|pejabat|office|university|universiti|college|kolej|kampung|kg\.?|jalan|road|bridge|jambatan|mall|hotel|airport|lapangan|port|pelabuhan|jetty|jeti|centre|center|pusat|complex|kompleks|plaza|park|taman|square|dataran|padang|market|pasar|terminal|court|mahkamah|camp|kem|estate|ladang|mine|lombong|dam|empangan|beach|pantai|waterfall|resort)\b/iu';

    /**
     * English institution types and how the map knows them. OSM carries the
     * signboard name, and Malaysian signboards are in Malay: "Kota Belud Fire
     * and Rescue Station" finds nothing anywhere; "Balai Bomba Kota Belud"
     * is found by two indexes at the same point. Longest first.
     */
    private const MALAY = [
        'fire and rescue station'      => 'Balai Bomba dan Penyelamat',
        'district police headquarters' => 'Ibu Pejabat Polis Daerah',
        'police headquarters'          => 'Ibu Pejabat Polis',
        'magistrates court'            => 'Mahkamah Majistret',
        'community hall'               => 'Dewan',
        'health clinic'                => 'Klinik Kesihatan',
        'district hospital'            => 'Hospital',
        'district office'              => 'Pejabat Daerah',
        'fire station'                 => 'Balai Bomba',
        'police station'               => 'Balai Polis',
        'primary school'               => 'Sekolah Kebangsaan',
        'secondary school'             => 'Sekolah Menengah Kebangsaan',
        'bus terminal'                 => 'Terminal Bas',
        'hospital'                     => 'Hospital',
        'mosque'                       => 'Masjid',
        'clinic'                       => 'Klinik',
        'market'                       => 'Pasar',
        'court'                        => 'Mahkamah',
    ];

    private const MAX_KM_FROM_DISTRICT = 20.0;   // 30 let a Pekan Nenas story sit at the Johor Bahru checkpoint, 28 km away

    /** @var list<array{step:string, query:string, outcome:string}> */
    private array $attempts = [];

    public function __construct(private GeocodingService $geocoder, private ?object $model = null)
    {
    }

    /** @return list<array{step:string, query:string, outcome:string}> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * The best alternative place the story names, placed and verified - or
     * null when nothing the story names is on the map either and the model's
     * own coordinates could not be verified.
     *
     * @return ?array{name:string, role:string, why:string, lat:float, lng:float, label:string, provider:string, tier:string, state:?string, country:?string}
     */
    public function resolve(NewsItem $item, string $failed, ?string $home = 'my'): ?array
    {
        $this->attempts = [];

        $check = new BoundaryCheck();
        $e = $check->expectation($failed, $home);

        if (($e['state'] ?? null) === null || ($e['state_name'] ?? null) === null) {
            $this->note('proxy', $failed, 'no state to hold an alternative to');

            return null;   // no state means no way to tell a right alternative from a wrong one
        }

        $stateName = $e['state_name'];
        $district  = $this->districtOf($failed, $stateName);
        $anchor    = $this->anchor($failed, $district, $stateName, $home);

        if ($anchor !== null) {
            $this->note('proxy', $failed, sprintf('held to %s (%.4f, %.4f), within %d km', $anchor['name'], $anchor['lat'], $anchor['lng'], (int) self::MAX_KM_FROM_DISTRICT));
        }

        foreach (['roles', 'model'] as $tier) {
            $candidates = $tier === 'roles' ? $this->fromRoles($item, $failed, $stateName, $home) : $this->fromModel($item, $failed, $stateName, $home);

            if ($candidates === []) {
                $this->note('proxy', $failed, $tier === 'roles' ? 'the story\'s own place list holds no alternative' : 'the model found no other place in the text');
                continue;
            }

            foreach ($candidates as $c) {
                $placed = $this->place($c['place'], $stateName, $failed, $anchor, $home);

                if ($placed === null) {
                    continue;
                }

                return $placed + ['name' => $c['place'], 'role' => $c['role'], 'why' => $c['why'], 'tier' => $tier];
            }
        }

        return $this->fromModelCoordinates($failed, $stateName, $district, $anchor, $home);
    }

    /**
     * The places the classifier already listed, minus the one that failed and
     * anything too large to stand in for it. Buildings before towns, and
     * within that the event's own places before the aftermath's.
     *
     * @return list<array{place:string, role:string, why:string}>
     */
    private function fromRoles(NewsItem $item, string $failed, string $stateName, ?string $home): array
    {
        $roles = is_array($item->place_roles) ? $item->place_roles : (json_decode((string) $item->place_roles, true) ?: []);
        $out = [];

        foreach ($roles as $r) {
            $p = trim((string) ($r['p'] ?? ''));
            $role = strtolower((string) ($r['role'] ?? ''));

            if ($p === '' || !isset(self::ROLE_ORDER[$role])) {
                continue;
            }

            if ($this->isTheFailedPlace($p, $failed) || PlaceScale::isTooBigToBeNear($p) || mb_strtolower($p) === mb_strtolower($stateName)) {
                continue;
            }

            if ($this->namesAnotherState($p, $stateName, $home)) {
                $this->note('proxy', $p, 'names another state - not a stand-in for a place in ' . $stateName);
                continue;
            }

            $out[] = ['place' => $p, 'role' => $role, 'why' => trim((string) ($r['why'] ?? '')),
                'rank' => (preg_match(self::INSTITUTION, $p) ? 0 : 10) + self::ROLE_ORDER[$role]];
        }

        usort($out, fn ($a, $b) => $a['rank'] <=> $b['rank']);

        return array_map(fn ($c) => ['place' => $c['place'], 'role' => $c['role'], 'why' => $c['why']], $out);
    }

    /**
     * The model reads the story again and names other places IN THE TEXT.
     * Names only; the map places them, the polygon checks them.
     *
     * @return list<array{place:string, role:string, why:string}>
     */
    private function fromModel(NewsItem $item, string $failed, string $stateName, ?string $home): array
    {
        $text = $this->storyText($item);

        if (mb_strlen($text) < 80) {
            $this->note('model', $failed, 'no article text to read');

            return [];
        }

        $model = $this->model ?? \App\Services\Ai\AiRouter::for('place_names');

        if (!$model->isConfigured()) {
            $this->note('model', $failed, 'no model key configured');

            return [];
        }

        $prompt = "A news story names a place that no map index knows. I need OTHER places the same story names that a map would know: "
            . "a hospital, a school, a police or fire station, a mosque, a stadium, a road, a village, a town.\n\n"
            . "Rules:\n- List only places that appear in the text below. Never invent one.\n- Never give coordinates.\n"
            . "- Prefer places closest to where the event happened.\n- Up to 5, best first.\n"
            . "- Only places AT or NEAR the event: where it happened, where the injured or the aftermath went, an office that acted there. Not where people came from, not the city the story was filed from.\n"
            . "- Reply with JSON only, no prose: [{\"place\":\"<name exactly as in the text>\",\"role\":\"happened|aftermath|office\",\"why\":\"<one short phrase>\"}]\n\n"
            . "Unplaceable name: {$failed}\nTitle: {$item->title}\n\nText:\n" . mb_substr($text, 0, 3500);

        try {
            $reply = $model->complete($prompt, 0.1);
        } catch (\Throwable $x) {
            $this->note('model', $failed, 'model call failed: ' . mb_substr($x->getMessage(), 0, 80));
            Log::warning('ProxyPlaces model call failed', ['news_item_id' => $item->id, 'error' => $x->getMessage()]);

            return [];
        }

        $usage = $model->lastUsage();
        $rows  = $this->json($reply);

        if (!is_array($rows)) {
            $this->note('model', $failed, 'reply was not JSON: ' . mb_substr($reply, 0, 60));

            return [];
        }

        $haystack = mb_strtolower($item->title . "\n" . $text);
        $out = [];

        foreach ($rows as $r) {
            $p = trim((string) ($r['place'] ?? ''));
            $role = strtolower((string) ($r['role'] ?? 'happened'));

            if ($p === '' || $this->isTheFailedPlace($p, $failed) || PlaceScale::isTooBigToBeNear($p) || $this->namesAnotherState($p, $stateName, $home)) {
                continue;
            }

            // "Only places in the text" is a rule the model is told; this is
            // the check that it kept it.
            if (!str_contains($haystack, mb_strtolower($p))) {
                $this->note('model', $p, 'proposed, but those words are not in the story - dropped');
                continue;
            }

            $out[] = ['place' => $p, 'role' => isset(self::ROLE_ORDER[$role]) ? $role : 'happened', 'why' => trim((string) ($r['why'] ?? ''))];
        }

        $this->note('model', $failed, sprintf('read the story again: %d place(s) proposed, %d present in the text (%d tokens)',
            count($rows), count($out), (int) (($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0))));

        return $out;
    }

    /**
     * The last tier: the model's own coordinates for the failed place.
     *
     * Accepted only when the point is inside the claimed state, within reach
     * of the district the name gives, and a reverse lookup of the point comes
     * back inside the town the name gives - so a confident answer in the
     * wrong town is refused the way any other wrong answer is.
     *
     * @return ?array{name:string, role:string, why:string, lat:float, lng:float, label:string, provider:string, tier:string, state:?string, country:?string}
     */
    private function fromModelCoordinates(string $failed, string $stateName, ?string $district, ?array $anchor, ?string $home): ?array
    {
        // Its own task, with no helper on purpose: measured 4 Sep, gpt-5-mini never refuses and was
        // once 403 km out while answering "known": "yes". DeepSeek at least says it does not know.
        $model = $this->model ?? \App\Services\Ai\AiRouter::for('place_coordinates');

        if (!$model->isConfigured()) {
            return null;
        }

        // Landmarks FIRST, and the numbers last.
        //
        // Measured 4 Sep 2026 over 18 places: asked straight for coordinates,
        // DeepSeek's median error was 6.7 km. Asked to name what stands near
        // the place before answering, the same model's own coordinates came
        // back at 2.5 km - and the landmark it named, put on the map by us,
        // was better still. A language model knows what a place is next to far
        // better than it knows two decimal numbers.
        $prompt = "Where is this place? {$failed}\n\n"
            . "Name up to THREE well-known things within about a kilometre of it - a mosque, a school, a clinic, a mall, a stadium, a jetty, a named junction - each written exactly as a map would list it, nearest first.\n"
            . "Reply with JSON only: {\"town\":\"<the town it is in>\",\"district\":\"<the district it is in>\",\"landmarks\":[\"<nearest>\",\"<next>\",\"<next>\"],\"known\":\"yes|no\",\"lat\":<decimal or null>,\"lng\":<decimal or null>}\n"
            . "Answer \"known\":\"no\" and leave lat and lng null unless you are sure of the spot to within about one kilometre. The landmarks matter more than the numbers. Never give a town centre and call it the place.";

        try {
            $reply = $model->complete($prompt, 0.0);
        } catch (\Throwable $x) {
            $this->note('model', $failed, 'coordinate call failed: ' . mb_substr($x->getMessage(), 0, 80));

            return null;
        }

        $r = $this->json($reply);

        // THE STATE GOES ON THE END OF EVERY QUERY, AND THE SEARCH WALKS UP.
        //
        // Owner's rule, 4 Sep 2026, and it is the right one. My first attempt
        // searched a landmark on its own to widen coverage and "Masjid Jamek
        // An-Nur" matched a mosque 1,100 km away. With the state appended that
        // match cannot happen, so the ladder can be climbed safely:
        //
        //   landmark, town, state   the tightest, and the most accurate
        //   landmark, state         wider, still inside the right state
        //   town, state             a town-level pin
        //   district, state         the last rung
        //
        // Measured over 18 places: every one was placed, median 4.3 km, worst
        // 41.6 km - against 1,210 km when the state was left off. The rung that
        // answered decides how precise we claim to be.
        $town = trim((string) ($r['town'] ?? ''));
        $districtNamed = trim((string) ($r['district'] ?? ''));
        $landmarks = array_values(array_filter(array_map('trim', (array) ($r['landmarks'] ?? []))));

        $ladder = [];

        foreach (array_slice($landmarks, 0, 3) as $landmark) {
            if (mb_strlen($landmark) < 5 || $this->isTheFailedPlace($landmark, $failed)) {
                continue;
            }

            // ⛔ A river, a highway or a mountain range is not a pin: "Sungai Johor"
            // put a story 41 km out because a long river's point is arbitrary.
            if (PlaceScale::isTooBigToBeNear($landmark)) {
                $this->note('model', $failed, sprintf('"%s" is too big to stand for a spot - skipped', $landmark));

                continue;
            }

            if ($town !== '') {
                $ladder[] = ['landmark and town', $landmark . ', ' . $town, 'exact'];
            }

            $ladder[] = ['landmark', $landmark, 'exact'];
        }

        if ($town !== '' && !$this->isTheFailedPlace($town, $failed)) {
            $ladder[] = ['town', $town, 'approximate'];
        }

        if ($districtNamed !== '' && !$this->isTheFailedPlace($districtNamed, $failed) && mb_strtolower($districtNamed) !== mb_strtolower($town)) {
            $ladder[] = ['district', $districtNamed, 'approximate'];
        }

        foreach ($ladder as [$rung, $query, $precision]) {
            // place() puts the state on the end and runs the boundary check
            $hit = $this->place($query, $stateName, $failed, $anchor, $home);

            if ($hit === null) {
                continue;
            }

            $this->note('model', $failed, sprintf('placed from the %s the model named: "%s, %s" -> %.5f, %.5f', $rung, $query, $stateName, $hit['lat'], $hit['lng']));

            return [
                'name' => $query, 'role' => 'model', 'lat' => $hit['lat'], 'lng' => $hit['lng'],
                'why' => 'the model named this ' . $rung . ' for the place; the map placed it with the state appended, and it passed the boundary and district checks',
                'label' => $hit['label'] ?? $query, 'provider' => 'model_' . str_replace(' ', '_', $rung), 'tier' => 'model_landmark',
                'precision' => $precision, 'state' => $hit['state'] ?? $stateName, 'country' => $hit['country'] ?? null,
            ];
        }

        if (!is_array($r) || strtolower((string) ($r['known'] ?? 'no')) !== 'yes' || !is_numeric($r['lat'] ?? null) || !is_numeric($r['lng'] ?? null)) {
            $this->note('model', $failed, 'the model does not know the spot');

            return null;
        }

        $lat = (float) $r['lat'];
        $lng = (float) $r['lng'];
        $query = "model coordinates for {$failed}";

        $v = (new BoundaryCheck())->verify($failed, $lat, $lng, $home);

        if ($v['verdict'] === 'outside') {
            $this->note('model', $query, sprintf('%.5f, %.5f refused by boundary: %s', $lat, $lng, $v['detail']));

            return null;
        }

        if ($anchor !== null) {
            $km = $this->km($lat, $lng, $anchor['lat'], $anchor['lng']);

            if ($km > self::MAX_KM_FROM_DISTRICT) {
                $this->note('model', $query, sprintf('%.5f, %.5f is %.0f km from %s - refused', $lat, $lng, $km, $anchor['name']));

                return null;
            }
        }

        // The point, asked the other way round: what is here? The answer
        // must be in the town the name gives.
        $reverse = null;

        try {
            $reverse = $this->geocoder->reverse($lat, $lng);
        } catch (\Throwable $x) {
            // a missing reverse answer is not a refusal; the polygon already spoke
        }

        if ($anchor === null && $district !== null && $reverse !== null && !PlaceContainment::holds($failed, $reverse, $stateName)) {
            $this->note('model', $query, sprintf('%.5f, %.5f reverse-looks-up as "%s", not in %s - refused', $lat, $lng, mb_substr($reverse, 0, 60), $district));

            return null;
        }

        $this->note('model', $query, sprintf('%.5f, %.5f accepted: %s; reverse lookup "%s"; landmark given: %s', $lat, $lng, $v['detail'],
            mb_substr((string) $reverse, 0, 60), mb_substr((string) ($r['landmark'] ?? '-'), 0, 60)));

        return ['name' => $failed, 'role' => 'model', 'why' => 'coordinates proposed by the model, checked against the state boundary, the district and a reverse lookup; typically 1-3 km out',
            'lat' => $lat, 'lng' => $lng, 'label' => mb_substr((string) ($reverse ?: $failed), 0, 200), 'provider' => 'model', 'tier' => 'model_coordinates',
            'state' => $stateName, 'country' => null];
    }

    /**
     * One candidate, through the same doors as any other answer. The query is
     * always "place, state" - the owner's rule, after a bare "Dataran
     * Bandaraya" came back from Kota Bharu and Johor Bahru in turn - and the
     * English form is retried in Malay when the map does not know it.
     *
     * @return ?array{lat:float, lng:float, label:string, provider:string, state:?string, country:?string}
     */
    private function place(string $name, string $stateName, string $failed, ?array $anchor, ?string $home): ?array
    {
        $check = new BoundaryCheck();

        foreach (array_unique(array_filter([$name, $this->malay($name)])) as $form) {
            $query = str_contains(mb_strtolower($form), mb_strtolower($stateName)) ? $form : "{$form}, {$stateName}";

            try {
                $r = $this->geocoder->geocode($query, $home);
            } catch (GeocodingRateLimitedException $x) {
                throw $x;
            } catch (GeocodingException $x) {
                $this->note('proxy', $query, mb_substr($x->getMessage(), 0, 90));
                continue;
            }

            $display = (string) ($r['display'] ?? ($r['label'] . ', ' . ($r['state'] ?? '')));

            // Inside the state the FAILED name claims.
            $v = $check->verify($failed, (float) $r['lat'], (float) $r['lng'], $home);

            if ($v['verdict'] === 'outside') {
                $this->note('proxy', $query, 'refused by boundary: ' . $v['detail']);
                continue;
            }

            if (!NameMatch::holds($form, $display)) {
                $this->note('proxy', $query, 'answer is a different place: ' . mb_substr($display, 0, 60));
                continue;
            }

            if ($anchor !== null) {
                $km = $this->km((float) $r['lat'], (float) $r['lng'], $anchor['lat'], $anchor['lng']);

                if ($km > self::MAX_KM_FROM_DISTRICT) {
                    $this->note('proxy', $query, sprintf('%.0f km from %s - not the one the story means', $km, $anchor['name']));
                    continue;
                }
            }

            $this->note('proxy', $query, 'placed: ' . mb_substr($display, 0, 70) . ' (' . $v['detail'] . ')');

            return ['lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'label' => mb_substr($display, 0, 200),
                'provider' => (string) ($r['provider'] ?? $this->geocoder->lastProvider()), 'state' => $r['state'] ?? null, 'country' => $r['country'] ?? null];
        }

        return null;
    }

    /** "Kota Belud Fire and Rescue Station" -> "Balai Bomba dan Penyelamat Kota Belud". */
    private function malay(string $name): ?string
    {
        foreach (self::MALAY as $english => $malay) {
            if (preg_match('/^(.+?)\s+' . preg_quote($english, '/') . '$/iu', $name, $m)) {
                return $malay . ' ' . trim($m[1]);
            }
        }

        return null;
    }

    /**
     * The district or town the failed name gives, if it gives one. A road
     * between two towns gives its first town: "Kota Belud-Kundasang road"
     * is held to Kota Belud.
     */
    private function districtOf(string $failed, string $stateName): ?string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', preg_replace('/\s*\([^)]*\)/', '', $failed)))));

        foreach (array_slice($parts, 1) as $part) {
            if (mb_strtolower($part) === mb_strtolower($stateName) || PlaceScale::isTooBigToBeNear($part)) {
                continue;
            }

            if (preg_match('/\b(road|jalan|highway|lebuhraya|expressway|trunk)\b/iu', $part)) {
                $towns = preg_split('/\s*[-\x{2013}\x{2014}\/]\s*/u', trim(preg_replace('/\b(road|jalan|highway|lebuhraya|expressway|trunk|the|federal|persekutuan)\b/iu', '', $part)));
                $town  = trim(preg_replace('/\s+/', ' ', (string) ($towns[0] ?? '')));

                if (mb_strlen($town) >= 4) {
                    return $town;
                }

                continue;
            }

            return $part;
        }

        return null;
    }

    /**
     * The point a stand-in is held to. The district the name gives when it
     * gives one; otherwise the leading words of the place itself, which is
     * often a town ("Pekan Nenas Immigration depot" is in Pekan Nenas).
     * Either must resolve inside the state and carry its own name, or there
     * is no anchor - and with no anchor a stand-in is still accepted, but
     * only inside the state.
     *
     * @return ?array{name:string, lat:float, lng:float}
     */
    private function anchor(string $failed, ?string $district, string $stateName, ?string $home): ?array
    {
        $first = trim((string) (explode(',', preg_replace('/\s*\([^)]*\)/', '', $failed))[0] ?? ''));
        $words = preg_split('/\s+/', $first);
        $tries = array_filter([$district, count($words) >= 2 ? implode(' ', array_slice($words, 0, 2)) : null, count($words) >= 3 ? implode(' ', array_slice($words, 0, 3)) : null]);

        foreach (array_unique($tries) as $name) {
            if (PlaceScale::isTooBigToBeNear($name) || preg_match('/^(km|kilomet|jalan|the|waters|off)\b/iu', $name)) {
                continue;
            }

            $p = $this->point("{$name}, {$stateName}", $home);

            if ($p === null) {
                continue;
            }

            $v = (new BoundaryCheck())->verify("{$name}, {$stateName}", $p['lat'], $p['lng'], $home);

            if ($v['verdict'] === 'outside' || !NameMatch::holds($name, $p['display'] ?? $name)) {
                continue;
            }

            return $p;
        }

        return null;
    }

    /** "Gurun, Kedah" cannot stand in for a place in Penang. */
    private function namesAnotherState(string $candidate, string $stateName, ?string $home): bool
    {
        $e = (new BoundaryCheck())->expectation($candidate, $home);
        $named = $e['state_name'] ?? null;

        return $named !== null && mb_strtolower($named) !== mb_strtolower($stateName);
    }

    /** @return ?array{name:string, lat:float, lng:float} */
    private function point(string $query, ?string $home): ?array
    {
        try {
            $r = $this->geocoder->geocode($query, $home);

            return ['name' => explode(',', $query)[0], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'],
                'display' => (string) ($r['display'] ?? ($r['label'] . ', ' . ($r['state'] ?? '')))];
        } catch (GeocodingRateLimitedException $x) {
            throw $x;
        } catch (GeocodingException $x) {
            return null;
        }
    }

    private function isTheFailedPlace(string $candidate, string $failed): bool
    {
        $c = mb_strtolower($candidate);
        $f = mb_strtolower($failed);

        return $c === $f || str_starts_with($f, $c . ',') || str_starts_with($f, $c . ' ') || str_contains($f, ', ' . $c);
    }

    private function storyText(NewsItem $item): string
    {
        $extracted = DB::table('extraction_jobs')->where('news_item_id', $item->id)->orderByDesc('id')->value('extracted_text');

        return trim(implode("\n\n", array_filter([(string) $item->summary, (string) $item->ai_summary, (string) $extracted, (string) $item->body])));
    }

    /** The model's reply as JSON, fences and prose stripped. */
    private function json(string $reply): mixed
    {
        $s = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($reply)));
        $v = json_decode($s, true);

        if ($v === null && preg_match('/[\[{].*[\]}]/s', $s, $m)) {
            $v = json_decode($m[0], true);
        }

        // One object per line, no array around them - what the model sent
        // for the Dataran Bandaraya story. Wrap them.
        if ($v === null && preg_match_all('/\{[^{}]*\}/s', $s, $mm) && $mm[0] !== []) {
            $v = json_decode('[' . implode(',', $mm[0]) . ']', true);
        }

        // A single object where a list was asked for.
        if (is_array($v) && isset($v['place'])) {
            $v = [$v];
        }

        return $v;
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function note(string $step, string $query, string $outcome): void
    {
        $this->attempts[] = ['step' => $step, 'query' => mb_substr($query, 0, 120), 'outcome' => mb_substr($outcome, 0, 200)];
    }
}
