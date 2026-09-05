<?php

namespace App\Services\Geo;

/**
 * The ISO code for a country a place name spells out.
 *
 * Every lookup in the resolver was pinned to `countrycodes=my`. That is right
 * for the Malaysian stories which are nearly all of them - it stops a Kuching
 * from matching a Kuching somewhere else - and catastrophic for the rest: a
 * venue in Hamburg cannot be found inside Malaysia, so the search walked up to
 * "Germany", and the only Germany in Malaysia is the German Embassy in Kuala
 * Lumpur. The constraint did not merely fail. It manufactured a confident
 * wrong answer, four continents deep.
 *
 * So the constraint stays - and follows the name. A story that says Nepal is
 * searched in Nepal.
 */
class CountryCode
{
    /**
     * The names this site actually sees, in the three languages it reads,
     * mapped to what Nominatim wants. Not a world list: the entries here are
     * the ones in PlaceScale::COUNTRIES, which is itself the list of countries
     * that have turned up in Malaysian news.
     */
    private const CODES = [
        'malaysia' => 'my',
        'singapore' => 'sg', 'singapura' => 'sg',
        'indonesia' => 'id', 'thailand' => 'th', 'siam' => 'th',
        'vietnam' => 'vn', 'philippines' => 'ph', 'filipina' => 'ph',
        'brunei' => 'bn', 'cambodia' => 'kh', 'kemboja' => 'kh',
        'laos' => 'la', 'myanmar' => 'mm', 'burma' => 'mm',
        'timor leste' => 'tl', 'east timor' => 'tl',

        'china' => 'cn', 'republik rakyat china' => 'cn',
        'japan' => 'jp', 'jepun' => 'jp',
        'south korea' => 'kr', 'korea selatan' => 'kr', 'korea' => 'kr',
        'north korea' => 'kp', 'korea utara' => 'kp',
        'taiwan' => 'tw', 'hong kong' => 'hk', 'macau' => 'mo',
        'india' => 'in', 'pakistan' => 'pk', 'bangladesh' => 'bd',
        'sri lanka' => 'lk', 'nepal' => 'np', 'bhutan' => 'bt',
        'maldives' => 'mv', 'afghanistan' => 'af', 'mongolia' => 'mn',
        'kazakhstan' => 'kz',

        'saudi arabia' => 'sa', 'arab saudi' => 'sa',
        'united arab emirates' => 'ae', 'uae' => 'ae', 'qatar' => 'qa',
        'kuwait' => 'kw', 'bahrain' => 'bh', 'oman' => 'om', 'yemen' => 'ye',
        'iran' => 'ir', 'iraq' => 'iq', 'syria' => 'sy', 'lebanon' => 'lb',
        'jordan' => 'jo', 'israel' => 'il', 'palestine' => 'ps', 'palestin' => 'ps',
        'turkey' => 'tr', 'turkiye' => 'tr', 'egypt' => 'eg', 'mesir' => 'eg',
        'libya' => 'ly', 'tunisia' => 'tn', 'algeria' => 'dz',
        'morocco' => 'ma', 'sudan' => 'sd',

        'united kingdom' => 'gb', 'britain' => 'gb', 'great britain' => 'gb',
        'england' => 'gb', 'scotland' => 'gb', 'wales' => 'gb',
        'northern ireland' => 'gb', 'ireland' => 'ie',
        'france' => 'fr', 'perancis' => 'fr', 'germany' => 'de', 'jerman' => 'de',
        'italy' => 'it', 'itali' => 'it', 'spain' => 'es', 'sepanyol' => 'es',
        'portugal' => 'pt', 'netherlands' => 'nl', 'belanda' => 'nl',
        'holland' => 'nl', 'belgium' => 'be', 'switzerland' => 'ch',
        'austria' => 'at', 'sweden' => 'se', 'norway' => 'no', 'denmark' => 'dk',
        'finland' => 'fi', 'iceland' => 'is', 'poland' => 'pl',
        'czech republic' => 'cz', 'czechia' => 'cz', 'slovakia' => 'sk',
        'hungary' => 'hu', 'romania' => 'ro', 'bulgaria' => 'bg',
        'greece' => 'gr', 'croatia' => 'hr', 'serbia' => 'rs',
        'russia' => 'ru', 'rusia' => 'ru', 'ukraine' => 'ua', 'belarus' => 'by',
        'lithuania' => 'lt', 'latvia' => 'lv', 'estonia' => 'ee',

        'south africa' => 'za', 'afrika selatan' => 'za', 'nigeria' => 'ng',
        'kenya' => 'ke', 'ethiopia' => 'et', 'ghana' => 'gh',
        'tanzania' => 'tz', 'uganda' => 'ug', 'zimbabwe' => 'zw',
        'somalia' => 'so', 'senegal' => 'sn',

        'united states' => 'us', 'united states of america' => 'us',
        'usa' => 'us', 'us' => 'us', 'america' => 'us',
        'amerika syarikat' => 'us', 'amerika' => 'us',
        'canada' => 'ca', 'kanada' => 'ca', 'mexico' => 'mx', 'brazil' => 'br',
        'argentina' => 'ar', 'chile' => 'cl', 'colombia' => 'co', 'peru' => 'pe',
        'venezuela' => 've', 'cuba' => 'cu', 'australia' => 'au',
        'new zealand' => 'nz', 'papua new guinea' => 'pg', 'fiji' => 'fj',

        // Malay word order and spellings. "Republik Czech" was not here, so a
        // Moto3 win at Brno was searched for inside Malaysia, the walk-up
        // reached the Czech embassy on Jalan Tun Razak, and the story went
        // live 1.7 km from KLCC. The English form is not the only form.
        'republik czech' => 'cz', 'czech' => 'cz', 'republik ceko' => 'cz',
        'brunei darussalam' => 'bn', 'negara brunei darussalam' => 'bn',
        'sumatera' => 'id', 'sumatra' => 'id', 'jawa' => 'id', 'java' => 'id', 'kalimantan' => 'id', 'bali' => 'id',
        'england' => 'gb', 'scotland' => 'gb', 'wales' => 'gb',
        'cina' => 'cn', 'thai' => 'th', 'republik korea' => 'kr',
        'emiriah arab bersatu' => 'ae', 'turki' => 'tr', 'swiss' => 'ch',
        'yunani' => 'gr', 'poland' => 'pl', 'hungary' => 'hu',
        'ukraine' => 'ua', 'ukraina' => 'ua', 'maghribi' => 'ma',
        'republik dominican' => 'do', 'dominican republic' => 'do',
        'united states of america' => 'us', 'amerika syarikat (as)' => 'us',
        'as' => 'us', 'uk' => 'gb', 'england' => 'gb', 'republik afrika selatan' => 'za',

        // Chinese, for the titles that arrive in it.
        '马来西亚' => 'my', '新加坡' => 'sg', '印尼' => 'id', '印度尼西亚' => 'id',
        '泰国' => 'th', '越南' => 'vn', '菲律宾' => 'ph', '汶莱' => 'bn', '文莱' => 'bn',
        '中国' => 'cn', '日本' => 'jp', '韩国' => 'kr', '台湾' => 'tw', '香港' => 'hk',
        '印度' => 'in', '尼泊尔' => 'np', '美国' => 'us', '英国' => 'gb', '德国' => 'de',
        '法国' => 'fr', '捷克' => 'cz', '澳洲' => 'au', '澳大利亚' => 'au',
    ];

    /**
     * A state, nation or major city that names its country by itself, for the
     * countries whose news the site now reads: "Palm Beach Gardens, Florida"
     * is in the USA, "Sydney, New South Wales" in Australia, "Old Trafford,
     * Manchester" in Britain. Checked on a TRAILING part as a whole, before
     * the country words inside it - "New South Wales" is not Wales.
     * Malaysian namesakes (Victoria in Labuan, George Town) are left out.
     */
    private const REGIONS = [
        // USA
        'alabama' => 'us', 'alaska' => 'us', 'arizona' => 'us', 'arkansas' => 'us', 'california' => 'us', 'colorado' => 'us',
        'connecticut' => 'us', 'delaware' => 'us', 'florida' => 'us', 'georgia' => 'us', 'hawaii' => 'us', 'idaho' => 'us',
        'illinois' => 'us', 'indiana' => 'us', 'iowa' => 'us', 'kansas' => 'us', 'kentucky' => 'us', 'louisiana' => 'us',
        'maine' => 'us', 'maryland' => 'us', 'massachusetts' => 'us', 'michigan' => 'us', 'minnesota' => 'us', 'mississippi' => 'us',
        'missouri' => 'us', 'montana' => 'us', 'nebraska' => 'us', 'nevada' => 'us', 'new hampshire' => 'us', 'new jersey' => 'us',
        'new mexico' => 'us', 'new york' => 'us', 'north carolina' => 'us', 'north dakota' => 'us', 'ohio' => 'us', 'oklahoma' => 'us',
        'oregon' => 'us', 'pennsylvania' => 'us', 'rhode island' => 'us', 'south carolina' => 'us', 'south dakota' => 'us',
        'tennessee' => 'us', 'texas' => 'us', 'utah' => 'us', 'vermont' => 'us', 'virginia' => 'us', 'washington' => 'us',
        'west virginia' => 'us', 'wisconsin' => 'us', 'wyoming' => 'us', 'washington dc' => 'us', 'washington, d.c.' => 'us',
        'los angeles' => 'us', 'chicago' => 'us', 'houston' => 'us', 'san francisco' => 'us', 'miami' => 'us', 'boston' => 'us', 'seattle' => 'us',
        // Australia
        'new south wales' => 'au', 'nsw' => 'au', 'queensland' => 'au', 'qld' => 'au', 'western australia' => 'au', 'south australia' => 'au',
        'tasmania' => 'au', 'northern territory' => 'au', 'australian capital territory' => 'au', 'act' => 'au', 'victoria, australia' => 'au',
        'sydney' => 'au', 'melbourne' => 'au', 'brisbane' => 'au', 'perth' => 'au', 'adelaide' => 'au', 'canberra' => 'au', 'hobart' => 'au',
        'darwin' => 'au', 'gold coast' => 'au', 'newcastle, nsw' => 'au', 'wollongong' => 'au', 'geelong' => 'au', 'cairns' => 'au', 'townsville' => 'au',
        // United Kingdom
        'england' => 'gb', 'scotland' => 'gb', 'wales' => 'gb', 'northern ireland' => 'gb', 'london' => 'gb', 'greater london' => 'gb',
        'manchester' => 'gb', 'greater manchester' => 'gb', 'birmingham' => 'gb', 'liverpool' => 'gb', 'leeds' => 'gb', 'sheffield' => 'gb',
        'bristol' => 'gb', 'newcastle upon tyne' => 'gb', 'nottingham' => 'gb', 'leicester' => 'gb', 'coventry' => 'gb', 'glasgow' => 'gb',
        'edinburgh' => 'gb', 'aberdeen' => 'gb', 'dundee' => 'gb', 'cardiff' => 'gb', 'swansea' => 'gb', 'belfast' => 'gb', 'oxford' => 'gb',
        'cambridge' => 'gb', 'brighton' => 'gb', 'southampton' => 'gb', 'portsmouth' => 'gb', 'reading' => 'gb', 'york' => 'gb',
        'yorkshire' => 'gb', 'kent' => 'gb', 'essex' => 'gb', 'surrey' => 'gb', 'sussex' => 'gb', 'devon' => 'gb', 'cornwall' => 'gb',
        'lancashire' => 'gb', 'merseyside' => 'gb', 'west midlands' => 'gb', 'hampshire' => 'gb', 'norfolk' => 'gb', 'suffolk' => 'gb',
    ];

    /** The country a state, nation or major city belongs to, or null. */
    public static function regionCountry(string $part): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($part))), " .");

        return self::REGIONS[$text] ?? null;
    }

    /** Every name this map knows, for the callers that test membership. */
    public static function names(): array
    {
        return array_keys(self::CODES);
    }

    /**
     * Which country should this name be searched in?
     *
     * Malaysia unless the name says otherwise, because nearly every story is
     * Malaysian and the constraint is what stops a Kuching matching a Kuching
     * elsewhere. Only a country written out in the name overrides it.
     */
    public static function forPlace(string $placeName, ?string $default = 'my'): ?string
    {
        $parts = array_map('trim', explode(',', $placeName));
        $last  = count($parts) - 1;

        foreach (array_reverse($parts, true) as $i => $part) {
            // a trailing part that IS a state or a major city: "Florida",
            // "New South Wales" (not Wales), "Manchester"
            if ($i > 0 && ($region = self::regionCountry($part)) !== null) {
                return $region;
            }

            // Only a trailing component may name its country by a word
            // inside it. The first component is the place, and "Lebuh China,
            // George Town" is a street in Penang, not a story from Beijing.
            //
            // Unless it is the ONLY component. "Nepal-Tibet border" and
            // "China Masters in Shenzhen" have nowhere else to put the
            // country, and a single-part Malaysian street named after a
            // country does not occur in practice - the classifier always
            // writes the town after it.
            $code = self::codeFor($part, $i > 0 || $last === 0);

            if ($code !== null) {
                return $code;
            }
        }

        // Nothing in the name says: the publisher's country, or null for an
        // international outlet, which means "search the world".
        return $default === null ? null : strtolower($default);
    }

    /**
     * One component of a name, as a country code - or null if it is not a
     * country. "Republic of Korea", "Kingdom of Thailand", "Negara Brunei" and
     * "Republik Czech" all resolve; the prefix is the same handful of words
     * in every language the site reads.
     */
    public static function codeFor(string $part, bool $allowInner = true): ?string
    {
        $text = mb_strtolower(trim($part));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text, " .");

        if ($text === '') {
            return null;
        }

        if (isset(self::CODES[$text])) {
            return self::CODES[$text];
        }

        $stripped = preg_replace(
            '/^(the|republic of|kingdom of|state of|federal republic of|people\'s republic of|negara|republik|kerajaan)\s+/u',
            '',
            $text
        );

        if (isset(self::CODES[trim((string) $stripped)])) {
            return self::CODES[trim((string) $stripped)];
        }

        if (!$allowInner) {
            return null;
        }

        // "Brunei Darussalam", "Nepal-China border", "Sumatera Utara,
        // Indonesia (Sumatra)": the country is a word inside the part rather
        // than the whole of it. One country found is an answer; two is a
        // border, which is none.
        $found = [];

        foreach (preg_split('/[^\p{L}]+/u', $text) ?: [] as $tok) {
            $code = self::CODES[$tok] ?? null;

            if ($code !== null && $code !== 'my') {
                $found[$code] = true;
            }
        }

        // Two-word countries, the same way.
        foreach (['united states', 'united kingdom', 'south korea', 'north korea', 'hong kong', 'new zealand',
                  'saudi arabia', 'sri lanka', 'south africa', 'czech republic', 'timor leste'] as $two) {
            if (str_contains($text, $two)) {
                $found[self::CODES[$two]] = true;
            }
        }

        return count($found) === 1 ? array_key_first($found) : null;
    }
}
