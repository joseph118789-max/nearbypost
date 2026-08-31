<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationAlias extends Model
{
    protected $table = 'location_aliases';

    protected $fillable = ['alias_text', 'canonical_name', 'alias_type', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public const TYPE_CITY     = 'city';
    public const TYPE_DISTRICT = 'district';
    public const TYPE_REGION   = 'region';
    public const TYPE_STATE    = 'state';
    public const TYPE_AREA     = 'area';

    public const ALLOWED_TYPES = [
        self::TYPE_CITY, self::TYPE_DISTRICT, self::TYPE_REGION,
        self::TYPE_STATE, self::TYPE_AREA,
    ];

    /** Scope: active aliases only */
    public function scopeActive($q) { $q->where('is_active', true); }

    /**
     * Match a raw place string to a canonical name.
     *
     * The AI returns place text in several shapes: "Sepang", "George Town, Penang",
     * "Jalan Ampang, Kuala Lumpur". A single exact match only ever caught the first
     * shape, so comma forms are now tried segment by segment - most specific first,
     * then broader - and the first alias hit wins.
     *
     * Returns ['status' => 'matched'|'unmatched'|'empty', 'canonical' => string|null,
     *          'alias_type' => string|null, 'matched_on' => string|null]
     */
    public static function resolve(string $placeText): array
    {
        $normalized = self::normalize($placeText);

        if ($normalized === '') {
            return ['status' => 'empty', 'canonical' => null, 'alias_type' => null, 'matched_on' => null];
        }

        foreach (self::candidates($normalized) as $candidate) {
            $match = self::active()
                ->whereRaw('LOWER(alias_text) = ?', [mb_strtolower($candidate)])
                ->first();

            if ($match) {
                return [
                    'status'     => 'matched',
                    'canonical'  => $match->canonical_name,
                    'alias_type' => $match->alias_type,
                    'matched_on' => $candidate,
                ];
            }
        }

        return [
            'status'     => 'unmatched',
            'canonical'  => null,
            'alias_type' => null,
            'matched_on' => null,
        ];
    }

    /**
     * Candidate strings to try, most specific first.
     * "George Town, Penang" becomes ["George Town, Penang", "George Town", "Penang"]
     */
    public static function candidates(string $normalized): array
    {
        $out = [$normalized];

        if (str_contains($normalized, ',')) {
            foreach (explode(',', $normalized) as $segment) {
                $segment = trim($segment);
                if ($segment !== '' && !in_array($segment, $out, true)) {
                    $out[] = $segment;
                }
            }
        }

        return $out;
    }

    /** Simple text normalisation */
    public static function normalize(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s*,\s*Malaysia\s*$/i', '', $text);
        $text = preg_replace('/\s+(kota|daerah|district|area)\s*$/i', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Seed Malaysian place aliases.
     *
     * This is a normalisation layer, not a gate: unmatched place text is passed
     * through to the geocoder unchanged (see EnrichLocationAlias). Entries here fold
     * abbreviations and variant spellings onto one canonical name so the geocode
     * cache hits more often and location labels stay consistent.
     */
    public static function seedMalaysianPlaces(): iterable
    {
        $aliases = [
            // States and federal territories
            ['Johor', 'Johor', 'state'],
            ['Kedah', 'Kedah', 'state'],
            ['Kelantan', 'Kelantan', 'state'],
            ['Melaka', 'Melaka', 'state'],
            ['Malacca', 'Melaka', 'state'],
            ['Negeri Sembilan', 'Negeri Sembilan', 'state'],
            ['Pahang', 'Pahang', 'state'],
            ['Perak', 'Perak', 'state'],
            ['Perlis', 'Perlis', 'state'],
            ['Pulau Pinang', 'Penang', 'state'],
            ['Penang', 'Penang', 'state'],
            ['Sabah', 'Sabah', 'state'],
            ['Sarawak', 'Sarawak', 'state'],
            ['Selangor', 'Selangor', 'state'],
            ['Terengganu', 'Terengganu', 'state'],
            ['Kuala Lumpur', 'Kuala Lumpur', 'city'],
            ['KL', 'Kuala Lumpur', 'city'],
            ['Wilayah Persekutuan', 'Kuala Lumpur', 'city'],
            ['Putrajaya', 'Putrajaya', 'city'],
            ['Labuan', 'Labuan', 'city'],

            // Klang Valley
            ['Petaling Jaya', 'Petaling Jaya', 'city'],
            ['PJ', 'Petaling Jaya', 'city'],
            ['Petaling Jaya Sentral', 'Petaling Jaya', 'area'],
            ['Shah Alam', 'Shah Alam', 'city'],
            ['Klang', 'Klang', 'city'],
            ['Port Klang', 'Port Klang', 'area'],
            ['Subang Jaya', 'Subang Jaya', 'city'],
            ['Subang', 'Subang Jaya', 'area'],
            ['Kajang', 'Kajang', 'city'],
            ['Selayang', 'Selayang', 'city'],
            ['Ampang', 'Ampang', 'area'],
            ['Ampang Jaya', 'Ampang', 'area'],
            ['Cheras', 'Cheras', 'area'],
            ['Puchong', 'Puchong', 'area'],
            ['Seri Kembangan', 'Seri Kembangan', 'area'],
            ['Cyberjaya', 'Cyberjaya', 'city'],
            ['Sepang', 'Sepang', 'district'],
            ['Rawang', 'Rawang', 'area'],
            ['Gombak', 'Gombak', 'district'],
            ['Hulu Langat', 'Hulu Langat', 'district'],
            ['Kuala Selangor', 'Kuala Selangor', 'district'],
            ['Sabak Bernam', 'Sabak Bernam', 'district'],
            ['Banting', 'Banting', 'area'],
            ['Semenyih', 'Semenyih', 'area'],
            ['Bandar Sunway', 'Bandar Sunway', 'area'],
            ['Sunway', 'Bandar Sunway', 'area'],
            ['Damansara', 'Damansara', 'area'],
            ['Kota Damansara', 'Kota Damansara', 'area'],
            ['Bandar Utama', 'Bandar Utama', 'area'],
            ['Mutiara Damansara', 'Mutiara Damansara', 'area'],

            // Kuala Lumpur neighbourhoods
            ['Bangsar', 'Bangsar', 'area'],
            ['Mont Kiara', 'Mont Kiara', 'area'],
            ['TTDI', 'Taman Tun Dr Ismail', 'area'],
            ['Taman Tun Dr Ismail', 'Taman Tun Dr Ismail', 'area'],
            ['Setapak', 'Setapak', 'area'],
            ['Wangsa Maju', 'Wangsa Maju', 'area'],
            ['Sentul', 'Sentul', 'area'],
            ['Brickfields', 'Brickfields', 'area'],
            ['KLCC', 'Kuala Lumpur City Centre', 'area'],
            ['Bukit Bintang', 'Bukit Bintang', 'area'],
            ['Chow Kit', 'Chow Kit', 'area'],
            ['Kepong', 'Kepong', 'area'],
            ['Segambut', 'Segambut', 'area'],
            ['Titiwangsa', 'Titiwangsa', 'area'],
            ['Bukit Jalil', 'Bukit Jalil', 'area'],
            ['Sri Petaling', 'Sri Petaling', 'area'],
            ['Desa Petaling', 'Desa Petaling', 'area'],
            ['Jalan Ampang', 'Jalan Ampang', 'area'],
            ['Sungai Besi', 'Sungai Besi', 'area'],

            // Penang
            ['George Town', 'George Town', 'city'],
            ['Georgetown', 'George Town', 'city'],
            ['Butterworth', 'Butterworth', 'city'],
            ['Seberang Perai', 'Seberang Perai', 'district'],
            ['Bayan Lepas', 'Bayan Lepas', 'area'],
            ['Bukit Mertajam', 'Bukit Mertajam', 'city'],
            ['Balik Pulau', 'Balik Pulau', 'area'],
            ['Nibong Tebal', 'Nibong Tebal', 'area'],

            // Johor
            ['Johor Bahru', 'Johor Bahru', 'city'],
            ['JB', 'Johor Bahru', 'city'],
            ['Iskandar Puteri', 'Iskandar Puteri', 'city'],
            ['Nusajaya', 'Iskandar Puteri', 'city'],
            ['Batu Pahat', 'Batu Pahat', 'city'],
            ['Muar', 'Muar', 'city'],
            ['Kluang', 'Kluang', 'city'],
            ['Kulai', 'Kulai', 'city'],
            ['Kota Tinggi', 'Kota Tinggi', 'city'],
            ['Pontian', 'Pontian', 'city'],
            ['Segamat', 'Segamat', 'city'],
            ['Mersing', 'Mersing', 'city'],
            ['Pasir Gudang', 'Pasir Gudang', 'city'],
            ['Skudai', 'Skudai', 'area'],
            ['Tangkak', 'Tangkak', 'city'],
            ['Yong Peng', 'Yong Peng', 'area'],

            // Perak
            ['Ipoh', 'Ipoh', 'city'],
            ['Taiping', 'Taiping', 'city'],
            ['Teluk Intan', 'Teluk Intan', 'city'],
            ['Sitiawan', 'Sitiawan', 'city'],
            ['Lumut', 'Lumut', 'area'],
            ['Kampar', 'Kampar', 'city'],
            ['Batu Gajah', 'Batu Gajah', 'city'],
            ['Tapah', 'Tapah', 'city'],
            ['Kuala Kangsar', 'Kuala Kangsar', 'city'],
            ['Parit Buntar', 'Parit Buntar', 'city'],
            ['Bagan Serai', 'Bagan Serai', 'area'],
            ['Manjung', 'Manjung', 'district'],
            ['Gerik', 'Gerik', 'area'],
            ['Slim River', 'Slim River', 'area'],

            // Kedah and Perlis
            ['Alor Setar', 'Alor Setar', 'city'],
            ['Sungai Petani', 'Sungai Petani', 'city'],
            ['Kulim', 'Kulim', 'city'],
            ['Langkawi', 'Langkawi', 'district'],
            ['Jitra', 'Jitra', 'area'],
            ['Baling', 'Baling', 'area'],
            ['Kangar', 'Kangar', 'city'],
            ['Padang Besar', 'Padang Besar', 'area'],
            ['Arau', 'Arau', 'area'],

            // Kelantan, Terengganu, Pahang
            ['Kota Bharu', 'Kota Bharu', 'city'],
            ['Pasir Mas', 'Pasir Mas', 'city'],
            ['Tanah Merah', 'Tanah Merah', 'area'],
            ['Gua Musang', 'Gua Musang', 'city'],
            ['Tumpat', 'Tumpat', 'area'],
            ['Kuala Terengganu', 'Kuala Terengganu', 'city'],
            ['Kemaman', 'Kemaman', 'district'],
            ['Dungun', 'Dungun', 'city'],
            ['Marang', 'Marang', 'area'],
            ['Besut', 'Besut', 'district'],
            ['Kuantan', 'Kuantan', 'city'],
            ['Temerloh', 'Temerloh', 'city'],
            ['Bentong', 'Bentong', 'city'],
            ['Raub', 'Raub', 'city'],
            ['Cameron Highlands', 'Cameron Highlands', 'district'],
            ['Genting Highlands', 'Genting Highlands', 'area'],
            ['Pekan', 'Pekan', 'city'],
            ['Jerantut', 'Jerantut', 'city'],
            ['Rompin', 'Rompin', 'district'],

            // Negeri Sembilan and Melaka
            ['Seremban', 'Seremban', 'city'],
            ['Port Dickson', 'Port Dickson', 'city'],
            ['Nilai', 'Nilai', 'city'],
            ['Kuala Pilah', 'Kuala Pilah', 'city'],
            ['Rembau', 'Rembau', 'area'],
            ['Tampin', 'Tampin', 'area'],
            ['Bahau', 'Bahau', 'area'],
            ['Melaka City', 'Melaka', 'city'],
            ['Bandar Melaka', 'Melaka', 'city'],
            ['Ayer Keroh', 'Ayer Keroh', 'area'],
            ['Alor Gajah', 'Alor Gajah', 'city'],
            ['Jasin', 'Jasin', 'city'],

            // Sabah
            ['Kota Kinabalu', 'Kota Kinabalu', 'city'],
            ['KK', 'Kota Kinabalu', 'city'],
            ['Sandakan', 'Sandakan', 'city'],
            ['Tawau', 'Tawau', 'city'],
            ['Lahad Datu', 'Lahad Datu', 'city'],
            ['Semporna', 'Semporna', 'city'],
            ['Keningau', 'Keningau', 'city'],
            ['Kudat', 'Kudat', 'city'],
            ['Ranau', 'Ranau', 'area'],
            ['Kundasang', 'Kundasang', 'area'],
            ['Papar', 'Papar', 'area'],
            ['Beaufort', 'Beaufort', 'area'],
            ['Penampang', 'Penampang', 'district'],
            ['Putatan', 'Putatan', 'area'],
            ['Kunak', 'Kunak', 'area'],

            // Sarawak
            ['Kuching', 'Kuching', 'city'],
            ['Miri', 'Miri', 'city'],
            ['Sibu', 'Sibu', 'city'],
            ['Bintulu', 'Bintulu', 'city'],
            ['Sri Aman', 'Sri Aman', 'city'],
            ['Limbang', 'Limbang', 'city'],
            ['Sarikei', 'Sarikei', 'city'],
            ['Kapit', 'Kapit', 'city'],
            ['Mukah', 'Mukah', 'city'],
            ['Samarahan', 'Samarahan', 'district'],
            ['Serian', 'Serian', 'city'],
            ['Bau', 'Bau', 'area'],
            ['Betong', 'Betong', 'area'],

            // Regions
            ['Klang Valley', 'Klang Valley', 'region'],
            ['Greater KL', 'Klang Valley', 'region'],
            ['Northern Malaysia', 'Northern Malaysia', 'region'],
            ['East Coast', 'East Coast Malaysia', 'region'],
            ['Southern Malaysia', 'Southern Malaysia', 'region'],
            ['Borneo', 'East Malaysia', 'region'],
            ['East Malaysia', 'East Malaysia', 'region'],
            ['Peninsular Malaysia', 'Peninsular Malaysia', 'region'],
        ];

        foreach ($aliases as $alias) {
            yield $alias;
        }
    }
}
