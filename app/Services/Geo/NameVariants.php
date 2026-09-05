<?php

namespace App\Services\Geo;

/**
 * The other ways a place's name is written.
 *
 * The owner typed "borneo convention kuching" into OpenStreetMap and got the
 * building at once; our cascade had sent "Pusat Konvensyen Borneo Kuching
 * (BCCK), Kuching, Sarawak" and got nothing. The map holds ONE spelling of a
 * name - usually the signboard's - and a story uses whichever its language
 * and its writer preferred. Now that the map engine is ours, asking it
 * several ways costs nothing; what still costs is accepting a wrong answer,
 * so every variant is held to the same polygon, name and district tests.
 *
 * Variants, in the order tried:
 *   1. the name without its bracketed acronym
 *   2. the same institution phrase in the other language, both word orders
 *      ("Mahkamah Sesyen Kuala Lumpur" -> "Kuala Lumpur Sessions Court")
 *   3. spelling twins (Kampung/Kg, Tanjung/Tanjong, Sungai/Sg, Jalan/Jln)
 *   4. the distinctive words only ("borneo convention kuching")
 */
class NameVariants
{
    /** Malay phrase => English phrase. Longest first, so "sekolah menengah kebangsaan" beats "sekolah kebangsaan". */
    private const PHRASES = [
        'sekolah menengah kebangsaan' => 'secondary school',
        'ibu pejabat polis daerah'    => 'district police headquarters',
        'pusat latihan kebangsaan'    => 'national training centre',
        'dewan undangan negeri'       => 'state legislative assembly',
        'lapangan terbang antarabangsa' => 'international airport',
        'gelanggang serbaguna'        => 'multipurpose court',
        'pusat konvensyen'            => 'convention centre',
        'sekolah kebangsaan'          => 'primary school',
        'mahkamah majistret'          => "magistrate's court",
        'mahkamah sesyen'             => 'sessions court',
        'mahkamah tinggi'             => 'high court',
        'kompleks mahkamah'           => 'court complex',
        'kompleks sukan'              => 'sports complex',
        'pusat sukan'                 => 'sports centre',
        'lapangan terbang'            => 'airport',
        'balai bomba'                 => 'fire station',
        'balai polis'                 => 'police station',
        'pejabat daerah'              => 'district office',
        'pusat komuniti'              => 'community centre',
        'pusat dagangan'              => 'trade centre',
        'taman tema'                  => 'theme park',
        'pantai'                      => 'beach',
        'jambatan'                    => 'bridge',
        'istana'                      => 'palace',
        'masjid'                      => 'mosque',
        'muzium'                      => 'museum',
        'galeri'                      => 'gallery',
        'dewan'                       => 'hall',
        'stesen'                      => 'station',
        'pelabuhan'                   => 'port',
        'hospital'                    => 'hospital',
        'universiti'                  => 'university',
        'kolej'                       => 'college',
        'perpustakaan'                => 'library',
        'pasar'                       => 'market',
        'tasik'                       => 'lake',
        'pulau'                       => 'island',
    ];

    private const TWINS = [
        'kampung' => ['kampong', 'kg', 'kg.'], 'kampong' => ['kampung'], 'kg' => ['kampung'],
        'tanjung' => ['tanjong'], 'tanjong' => ['tanjung'], 'sungai' => ['sg', 'sg.'], 'sg' => ['sungai'],
        'jalan' => ['jln'], 'jln' => ['jalan'], 'bukit' => ['bkt'], 'bkt' => ['bukit'], 'teluk' => ['telok'], 'telok' => ['teluk'],
        'baharu' => ['bahru', 'baru'], 'bahru' => ['baharu', 'baru'], 'baru' => ['bahru', 'baharu'],
        'centre' => ['center'], 'center' => ['centre'], 'pekan' => ['bandar'],
    ];

    /**
     * @return list<string> other spellings of the BARE name, best first, none equal to it
     */
    public static function of(string $bare): array
    {
        $bare = trim($bare);
        $out  = [];

        // 0. the acronym ALONE. "(FORUM)" is part of the building's name on
        //    the map; "forum, miri" is the query that finds it. The owner
        //    typed exactly that and had it in one go.
        if (preg_match('/\(([A-Za-z][A-Za-z0-9&.\- ]{1,24})\)/', $bare, $m)) {
            $out[] = trim($m[1]);
        }

        // 1. the acronym gone
        $stripped = trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $bare));
        $stripped = preg_replace('/\s+/', ' ', $stripped);

        if ($stripped !== $bare) {
            $out[] = $stripped;
        }

        // 2. the other language, both word orders
        $low = mb_strtolower($stripped);

        foreach (self::PHRASES as $ms => $en) {
            foreach ([[$ms, $en], [$en, $ms]] as [$from, $to]) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($from, '/') . '(?![\p{L}])/u', $low)) {
                    $rest = trim(preg_replace('/\s+/', ' ', preg_replace('/(?<![\p{L}])' . preg_quote($from, '/') . '(?![\p{L}])/iu', ' ', $stripped)));
                    $to   = mb_convert_case($to, MB_CASE_TITLE);

                    if ($rest !== '') {
                        $out[] = $rest . ' ' . $to;   // "Kuala Lumpur Sessions Court" / "Borneo Kuching Convention Centre"
                        $out[] = $to . ' ' . $rest;   // "Mahkamah Sesyen Kuala Lumpur"
                    } else {
                        $out[] = $to;                    // "Magistrate's Court" alone -> "Mahkamah Majistret"
                    }
                }
            }
        }

        // 2b. the institution that CONTAINS the one named: a sessions court
        //     or a magistrate's court sits in the town's court complex, which
        //     is what the map usually has.
        foreach (['mahkamah sesyen' => 'Kompleks Mahkamah', 'mahkamah majistret' => 'Kompleks Mahkamah', 'mahkamah tinggi' => 'Kompleks Mahkamah',
                  'sessions court' => 'Court Complex', "magistrate's court" => 'Court Complex', 'high court' => 'Court Complex'] as $from => $container) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($from, '/') . '(?![\p{L}])/iu', $low)) {
                $rest = trim(preg_replace('/\s+/', ' ', preg_replace('/(?<![\p{L}])' . preg_quote($from, '/') . '(?![\p{L}])/iu', ' ', $stripped)));
                $out[] = trim($container . ' ' . $rest);
            }
        }

        // 2a2. the sea off a place is the place: "waters off Penang Port"
        if (preg_match('/^(?:the\s+)?(?:waters?\s+off|off\s+the\s+coast\s+of|waters\s+of|perairan|offshore\s+(?:of|from))\s+(.+)$/iu', $stripped, $m)) {
            $out[] = trim($m[1]);
        }

        // 2b2. one family of words: Jabatan Muzium MALAYSIA is on the map as
        //      Jabatan Muzium NEGARA
        $family = ['malaysia', 'negara', 'kebangsaan', 'national'];

        foreach ($family as $word) {
            if (preg_match('/\b' . $word . '\b/iu', $stripped)) {
                foreach ($family as $other) {
                    if ($other !== $word) {
                        $out[] = preg_replace('/\b' . $word . '\b/iu', ucfirst($other), $stripped);
                    }
                }
            }
        }

        // 2c. a river's branch: "Sg Sadit Kanan" is on the map as Sungai Sadit
        if (preg_match('/\b(kanan|kiri)\b/iu', $stripped)) {
            $out[] = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(kanan|kiri)\b/iu', ' ', $stripped)));
        }

        // 3. spelling twins, one word at a time
        $words = preg_split('/\s+/', $stripped);

        foreach ($words as $i => $w) {
            $k = mb_strtolower(rtrim($w, '.,'));

            foreach (self::TWINS[$k] ?? [] as $twin) {
                $copy = $words;
                $copy[$i] = ctype_upper(mb_substr($w, 0, 1)) ? mb_convert_case($twin, MB_CASE_TITLE) : $twin;
                $out[] = implode(' ', $copy);
            }
        }

        // 4. the distinctive words only, then every arrangement of them the
        //    owner would type: adjacent pairs, and each word on its own. The
        //    caller holds a single-word hit to the rest of the name.
        $distinct = NameMatch::distinctiveWords($stripped);

        if (count($distinct) >= 2 && count($distinct) < count($words)) {
            $out[] = implode(' ', $distinct);
        }

        for ($i = 0; $i + 1 < count($distinct); $i++) {
            $out[] = $distinct[$i] . ' ' . $distinct[$i + 1];
        }

        foreach ($distinct as $w) {
            if (mb_strlen($w) >= 4 && !NameMatch::isKind($w)) {   // never a kind alone: "dataran" matched Anjung Dataran Merdeka
                $out[] = $w;
            }
        }

        $seen = [mb_strtolower($bare) => true];
        $uniq = [];

        foreach ($out as $v) {
            $key = mb_strtolower(trim($v));

            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $uniq[] = trim($v);
            }
        }

        return array_slice($uniq, 0, 14);
    }
}
