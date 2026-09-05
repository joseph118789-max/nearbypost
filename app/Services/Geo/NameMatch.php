<?php

namespace App\Services\Geo;

/**
 * Does a returned place carry the name that was asked for?
 *
 * A search bounded to the right state is necessary and not sufficient.
 * Asked for "Kampung Pengkalan Abai" inside Sabah, the fuzzy index returned
 * "Kampung Pengkalan Peturu" - inside Sabah, well-formed, and a different
 * village. Asked for a T-junction near Kota Belud it returned "Simpang Tiga,
 * Kinabatangan": simpang tiga IS the Malay for T-junction, and Kinabatangan
 * is 250 km away.
 *
 * So the words that name a KIND of place - kampung, jalan, masjid, simpang
 * - are set aside, and every word that is left in the question must appear
 * in the answer. "Pengkalan Abai" needs "abai"; "Peturu" has none.
 */
class NameMatch
{
    private const GENERIC = [
        'kampung', 'kampong', 'kg', 'jalan', 'jln', 'lorong', 'lrg', 'pekan', 'bandar', 'taman', 'sungai', 'sg',
        'bukit', 'pantai', 'pulau', 'tanjung', 'tanjong', 'teluk', 'kuala', 'batu', 'stadium', 'dewan', 'sekolah',
        'sk', 'smk', 'masjid', 'surau', 'hospital', 'klinik', 'pejabat', 'pusat', 'kompleks', 'plaza', 'hotel',
        'resort', 'junction', 'simpang', 'tiga', 'empat', 'road', 'street', 'the', 'of', 'di', 'dan', 'and', 'ibu',
        'daerah', 'jabatan', 'balai', 'polis', 'kem', 'skim', 'penempatan', 'studio', 'station', 'stesen', 'mall',
        'centre', 'center', 'building', 'bangunan', 'menara', 'tower', 'wisma', 'kolej', 'universiti', 'universiti',
        'international', 'antarabangsa', 'convention', 'persidangan', 'hutan', 'simpan', 'jetty', 'jeti', 'lembah',
        'galeri', 'gallery', 'muzium', 'museum', 'jabatan', 'kementerian', 'ministry', 'department',
        'kedai', 'runcit', 'warung', 'gerai', 'restoran', 'restaurant', 'kafe', 'cafe', 'mini', 'ubat', 'makan',
        'kanan', 'kiri',   // the right and left branch of a river
        'home', 'house', 'rumah', 'residence', 'kediaman', 'flat', 'apartment', 'condo', 'condominium', 'unit',   // where someone lives is not a name
        'waters', 'off', 'offshore', 'coast', 'perairan',   // the sea off a place
        'no', 'st', 'rd', 'dr', 'mt', 'bt', 'km', 'my', 'ke', 'ya', 'us',   // two-letter words that are not names
        'baru', 'lama', 'besar', 'kecil', 'utara', 'selatan', 'timur', 'barat', 'tengah', 'hulu', 'hilir',
        'district', 'mukim', 'malaysia', 'sabah', 'sarawak', 'johor', 'kedah', 'kelantan', 'melaka', 'pahang',
        'perak', 'perlis', 'penang', 'pinang', 'selangor', 'terengganu', 'putrajaya', 'labuan', 'negeri', 'sembilan',
    ];

    /** Spellings that differ by convention, not by place. */
    private const SAME = ['tanjong' => 'tanjung', 'kampong' => 'kampung', 'kg' => 'kampung', 'jln' => 'jalan', 'sg' => 'sungai', 'lrg' => 'lorong'];

    public static function holds(string $asked, string $answer): bool
    {
        $need = self::distinctive($asked);
        $have = self::tokens($answer);

        // "Sabah International Convention Centre" is four generic words and
        // one specific building. When nothing is distinctive, every word
        // must appear - and there must be at least three, so "Masjid" alone
        // still holds to nothing.
        if ($need === []) {
            $all = self::tokens($asked);

            // "Masjid" alone holds to nothing; "Sungai Perak" (two generic
            // words that together name one river) holds when BOTH appear in
            // the answer's OWN name, not merely somewhere in its address.
            if (count($all) < 2) {
                return false;
            }

            $own = count($all) < 3 ? self::tokens(explode(',', $answer)[0]) : $have;

            foreach ($all as $w) {
                if (!self::present($w, $own)) {   // twins count: Jabatan Muzium MALAYSIA is Jabatan Muzium NEGARA
                    return false;
                }
            }

            return true;
        }

        foreach ($need as $w) {
            if (!self::present($w, $have)) {
                return false;
            }
        }

        // The distinctive words match, but the KIND of place may not:
        // "Dataran Bandaraya" and "Dewan Bandaraya" share a word and are a
        // square and a city hall; "Kampung Tanjong Kapor" and "Masjid
        // Tanjung Kapor" are a village and a mosque. When the question names
        // a kind and the answer names a different kind and not ours, it is
        // a different place. A bare town ("Kinarut, Papar") names no kind
        // and so conflicts with nothing.
        // The KIND is read from the answer's own name - its first component -
        // not from its address. "Sarawak State Assembly, Darul Hana Bridge,
        // Petra Jaya" is an assembly; the bridge is where it stands.
        // (soft kinds and twins handled there: "Bangunan Forum" is not in
        // conflict with a "... Cultural Centre")
        return !self::kindsConflict($asked, $answer);
    }

    /**
     * Malay place names are spelt as their signboards were painted: Tanjong
     * and Tanjung, Kampong and Kampung, Nenas and Nanas. A word is present
     * if it is there, or a word one letter away is - for words of five
     * letters or more, so "Batu" never becomes "Bata".
     */
    /** Words that are a state: matched exactly, never by a slip of one letter. */
    private const STATES = ['perak', 'perlis', 'kedah', 'penang', 'pinang', 'selangor', 'melaka', 'malacca', 'johor', 'johore', 'pahang',
        'terengganu', 'kelantan', 'sabah', 'sarawak', 'labuan', 'putrajaya', 'sembilan', 'malaysia', 'singapore', 'singapura', 'brunei'];

    /** The same word in the other language: the map often holds the English name for a Malay question, or the reverse. */
    private const WORD_TWINS = [
        'konvensyen' => 'convention', 'persekutuan' => 'federation', 'persatuan' => 'association', 'antarabangsa' => 'international',
        'negeri' => 'state', 'bandaraya' => 'city', 'utama' => 'main', 'baru' => 'new', 'lama' => 'old', 'besar' => 'grand',
        'tengah' => 'central', 'timur' => 'east', 'barat' => 'west', 'selatan' => 'south', 'utara' => 'north', 'perpustakaan' => 'library',
        'pusat' => 'centre', 'kompleks' => 'complex', 'bangunan' => 'building', 'menara' => 'tower', 'lapangan' => 'airport',
        'terbang' => 'airport', 'perindustrian' => 'industrial', 'perniagaan' => 'business', 'kebangsaan' => 'national', 'daerah' => 'district',
        'sains' => 'science', 'teknologi' => 'technology', 'kesihatan' => 'health', 'pendidikan' => 'education', 'sukan' => 'sports',
        'dewan' => 'hall', 'masjid' => 'mosque', 'jambatan' => 'bridge', 'pantai' => 'beach', 'pasar' => 'market', 'muzium' => 'museum',
        'galeri' => 'gallery', 'stesen' => 'station', 'pelabuhan' => 'port', 'tasik' => 'lake', 'pulau' => 'island', 'istana' => 'palace',
        'sekolah' => 'school', 'mahkamah' => 'court', 'klinik' => 'clinic', 'kolej' => 'college', 'universiti' => 'university',
        'taman' => 'park', 'kampung' => 'village', 'jalan' => 'road', 'bukit' => 'hill', 'sungai' => 'river', 'gereja' => 'church',
        'balai' => 'station', 'polis' => 'police', 'bomba' => 'fire', 'padang' => 'field', 'gunung' => 'mount', 'air' => 'water',
        'imigresen' => 'immigration', 'tahanan' => 'detention', 'penjara' => 'prison', 'depoh' => 'depot', 'kastam' => 'customs',
        'latihan' => 'training', 'kebangsaan' => 'national', 'pertanian' => 'agriculture', 'perikanan' => 'fisheries',
    ];

    /** Words an official name carries that a question rarely bothers with. */
    private const TOLERATED = ['daerah', 'district', 'negeri', 'bahagian', 'wilayah', 'cawangan', 'branch', 'new', 'baru', 'old', 'lama',
        'main', 'utama', 'ibu', 'pejabat', 'office', 'kompleks', 'complex', 'pusat', 'centre', 'center', 'bangunan', 'building', 'sdn', 'bhd',
        'kuala', 'bandar', 'town', 'city', 'bandaraya', 'majlis', 'council', 'perbandaran', 'municipal', 'the', 'and', 'dan', 'of'];

    /**
     * The distinctive words of an answer's OWN name that the question never
     * spoke of, in either language. "Ducati Borneo Kuching" for the Borneo
     * convention centre has a stranger: Ducati. "Borneo Convention Centre
     * Kuching" has none - convention is konvensyen's twin, centre is pusat's.
     * A stranger means another place that happens to share some words.
     *
     * @return list<string>
     */
    public static function strangers(string $question, string $answerOwnName): array
    {
        $have = self::tokens($question);
        $out  = [];

        foreach (self::distinctive($answerOwnName) as $w) {
            if (mb_strlen($w) < 3 || in_array($w, self::TOLERATED, true) || self::present($w, $have)) {
                continue;
            }

            $out[] = $w;
        }

        return $out;
    }

    private static function present(string $w, array $have): bool
    {
        // the word, or its twin in the other language, exactly or one letter
        // off ("associations" for persatuan)
        $spellings = [$w];

        // one family: Jabatan Muzium MALAYSIA is Jabatan Muzium NEGARA on the map
        if (in_array($w, ['malaysia', 'negara', 'kebangsaan', 'national'], true)) {
            $spellings = ['malaysia', 'negara', 'kebangsaan', 'national'];
        }

        if (isset(self::WORD_TWINS[$w])) {
            $spellings[] = self::WORD_TWINS[$w];
        }

        foreach (array_keys(self::WORD_TWINS, $w, true) as $malay) {
            $spellings[] = $malay;
        }

        foreach ($spellings as $s) {
            if (in_array($s, $have, true)) {
                return true;
            }

            // a state's name is never one letter off: "Sungai Perah" is not Sungai Perak
            if (mb_strlen($s) < 5 || in_array($s, self::STATES, true)) {
                continue;
            }

            // one letter off; two for long words (Bayumes / Bayuemas)
            $slack = mb_strlen($s) >= 7 ? 2 : 1;

            foreach ($have as $h) {
                if (abs(mb_strlen($h) - mb_strlen($s)) <= $slack && levenshtein($s, $h) <= $slack) {
                    return true;
                }
            }
        }

        return false;
    }

    /** How many of these words the text carries, twins and one-letter slips included. */
    public static function countPresent(array $words, string $text): int
    {
        $have = self::tokens($text);

        return count(array_filter($words, fn ($w) => self::present(mb_strtolower($w), $have)));
    }

    /**
     * Does the answer's own name carry EVERY word of the question, generic ones
     * included (twins and one-letter slips allowed)? This is "the whole name
     * holds", which waives the no-strangers rule: "Federation of Orang Ulu
     * Associations Sarawak Malaysia Cultural Centre" carries all of
     * "Persekutuan Persatuan Orang Ulu Sarawak Malaysia"; "Ah Chong 亚春面之家"
     * carries only the "Ah Chong" of "Kedai Runcit Ah Chong" and is a noodle
     * house, not the grocery.
     */
    public static function holdsAll(string $asked, string $answerOwnName): bool
    {
        $have = self::tokens($answerOwnName);

        foreach (self::tokens($asked) as $w) {
            if (mb_strlen($w) < 2 || in_array($w, ['the', 'of', 'and', 'dan', 'di', 'in', 'at'], true)) {
                continue;
            }

            if (!self::present($w, $have)) {
                return false;
            }
        }

        return true;
    }

    /** The words of a name that are not generic - what a search can hold to. */
    public static function distinctiveWords(string $name): array
    {
        return self::distinctive($name);
    }

    /** The same kind in the other language, so "Dewan X" can be found as "X Hall". */
    private const KIND_TWINS = [
        'dewan' => 'hall', 'masjid' => 'mosque', 'jambatan' => 'bridge', 'pantai' => 'beach', 'pasar' => 'market',
        'muzium' => 'museum', 'galeri' => 'gallery', 'stesen' => 'station', 'pelabuhan' => 'port', 'tasik' => 'lake',
        'pulau' => 'island', 'istana' => 'palace', 'sekolah' => 'school', 'mahkamah' => 'court', 'hospital' => 'hospital',
        'klinik' => 'clinic', 'kolej' => 'college', 'universiti' => 'university', 'stadium' => 'stadium', 'hotel' => 'hotel',
        'taman' => 'park', 'kampung' => 'village', 'jalan' => 'road', 'bukit' => 'hill', 'sungai' => 'river', 'gereja' => 'church',
    ];

    /**
     * When the question names a KIND of place (a hall, a court), does the
     * answer's own name carry that kind, in either language? A variant that
     * dropped the kind word ("ahmad shah") matched a religious school named
     * after the same sultan; the school's name carries no hall, so it is not
     * the hall. A question that names no kind passes.
     */
    /** Kinds that only say "something is here": a complex, a centre, a building. They need not match. */
    private const SOFT_KINDS = ['complex', 'kompleks', 'centre', 'center', 'pusat', 'building', 'bangunan', 'tower', 'menara', 'plaza', 'park', 'taman', 'office', 'pejabat'];

    /** The kind's twin in the other language, if any: mahkamah -> court, court -> mahkamah. */
    public static function twinOf(string $kind): ?string
    {
        $kind = mb_strtolower($kind);

        if (isset(self::KIND_TWINS[$kind])) {
            return self::KIND_TWINS[$kind];
        }

        $ms = array_search($kind, self::KIND_TWINS, true);

        return $ms === false ? null : $ms;
    }

    /** Is this word a KIND of place (a hall, a road, a court) rather than a name? */
    public static function isKind(string $word): bool
    {
        return in_array(mb_strtolower($word), self::KINDS, true);
    }

    /**
     * The kind an answer's own name is HEADED by: "Lorong Dataran Utama" is a
     * lorong, whatever else its name carries. Soft kinds (a complex, a
     * building) are skipped so "Kompleks Mahkamah" reads as a court.
     */
    public static function headKind(string $answer): ?string
    {
        foreach (self::tokens(explode(',', $answer)[0]) as $w) {
            if (in_array($w, self::KINDS, true) && !in_array($w, self::SOFT_KINDS, true)) {
                return $w;
            }
        }

        return null;
    }

    public static function kindMatches(string $asked, string $answer): bool
    {
        $askedKinds = array_values(array_diff(array_intersect(self::tokens($asked), self::KINDS), self::SOFT_KINDS));

        if ($askedKinds === []) {
            return true;
        }

        // an answer headed by another kind is another kind of place
        $head = self::headKind($answer);

        if ($head !== null) {
            $wantedHead = $askedKinds;

            foreach ($askedKinds as $k) {
                if (isset(self::KIND_TWINS[$k])) { $wantedHead[] = self::KIND_TWINS[$k]; }
                if (($ms = array_search($k, self::KIND_TWINS, true)) !== false) { $wantedHead[] = $ms; }
            }

            return in_array($head, $wantedHead, true);
        }

        $wanted = $askedKinds;

        foreach ($askedKinds as $k) {
            if (isset(self::KIND_TWINS[$k])) { $wanted[] = self::KIND_TWINS[$k]; }
            if (($ms = array_search($k, self::KIND_TWINS, true)) !== false) { $wanted[] = $ms; }
        }

        return array_intersect(self::tokens(explode(',', $answer)[0]), $wanted) !== [];
    }

    /**
     * Does the answer name a different KIND of place from the question? Asked
     * on the ORIGINAL name when a variant has dropped the kind word: the
     * variant "sultan haji ahmad shah" carries no kind, so on its own it
     * accepted a mosque for a hall.
     */
    public static function kindsConflict(string $asked, string $answer): bool
    {
        // "Dataran Sungai Perak" carries the whole of "Sungai Perak": a square
        // ON the river, in the town the story gave. Not a conflict of kinds.
        $askedWords  = implode(' ', self::tokens($asked));
        $answerWords = ' ' . implode(' ', self::tokens(explode(',', $answer)[0])) . ' ';

        if ($askedWords !== '' && str_contains($answerWords, ' ' . $askedWords . ' ')) {
            return false;
        }

        // soft kinds (a complex, a centre) say nothing; a kind's twin in the
        // other language is the same kind ("pusat" is "centre")
        $askedKinds  = array_values(array_diff(array_intersect(self::tokens($asked), self::KINDS), self::SOFT_KINDS));
        $answerKinds = array_values(array_diff(array_intersect(self::tokens(explode(',', $answer)[0]), self::KINDS), self::SOFT_KINDS));

        foreach ($askedKinds as $k) {
            if (isset(self::KIND_TWINS[$k])) { $askedKinds[] = self::KIND_TWINS[$k]; }
            if (($ms = array_search($k, self::KIND_TWINS, true)) !== false) { $askedKinds[] = $ms; }
        }

        return $askedKinds !== [] && $answerKinds !== [] && array_intersect($askedKinds, $answerKinds) === [];
    }

    /** Words that say what KIND of place a name is. Two different kinds are two different places. */
    private const KINDS = [
        'kampung', 'kampong', 'kg', 'taman', 'jalan', 'jln', 'lorong', 'lrg', 'masjid', 'surau', 'gereja', 'kuil', 'tokong',
        'dewan', 'dataran', 'padang', 'stadium', 'sekolah', 'sk', 'smk', 'kolej', 'universiti', 'hospital', 'klinik',
        'balai', 'pejabat', 'jabatan', 'mahkamah', 'stesen', 'station', 'terminal', 'mall', 'plaza', 'hotel', 'resort',
        'kompleks', 'pusat', 'menara', 'wisma', 'bangunan', 'pasar', 'jeti', 'jetty', 'pelabuhan', 'lapangan', 'empangan',
        'sungai', 'sg', 'bukit', 'gunung', 'pulau', 'pantai', 'tasik', 'ladang', 'estet', 'kilang', 'lombong',
        'galeri', 'gallery', 'muzium', 'museum', 'skim', 'taman',
        'hall', 'court', 'school', 'mosque', 'church', 'temple', 'hospital', 'clinic', 'bridge', 'palace', 'market',
        'park', 'beach', 'lake', 'island', 'centre', 'center', 'complex', 'hotel', 'resort', 'tower', 'mall', 'port',
        'airport', 'university', 'college', 'library', 'office', 'headquarters', 'depot', 'jetty', 'terminal', 'village', 'road', 'street',
    ];

    /** @return list<string> the words that actually name the place */
    public static function distinctive(string $s): array
    {
        return array_values(array_filter(self::tokens($s), fn ($w) => !in_array($w, self::GENERIC, true) && mb_strlen($w) > 1));   // two-letter words count: "Ah Seng" is not "Tong Seng"
    }

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        $s = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s));
        $out = [];

        foreach (preg_split('/\s+/', trim($s)) ?: [] as $w) {
            if ($w === '') continue;
            $out[] = self::SAME[$w] ?? $w;
        }

        return $out;
    }
}
