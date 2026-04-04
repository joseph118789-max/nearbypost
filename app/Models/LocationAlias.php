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
    public const TYPE_AREA     = 'area';

    public const ALLOWED_TYPES = [self::TYPE_CITY, self::TYPE_DISTRICT, self::TYPE_REGION, self::TYPE_AREA];

    /** Scope: active aliases only */
    public function scopeActive($q) { $q->where('is_active', true); }

    /**
     * Match a raw place string to a canonical name.
     * Returns ['status' => 'matched'|'unmatched'|'ambiguous'|'empty', 'canonical' => string|null, 'alias_type' => string|null]
     */
    public static function resolve(string $placeText): array
    {
        $normalized = self::normalize($placeText);

        if ($normalized === '') {
            return ['status' => 'empty', 'canonical' => null, 'alias_type' => null];
        }

        // Exact match (case-insensitive)
        $match = self::active()
            ->whereRaw('LOWER(alias_text) = ?', [mb_strtolower($normalized)])
            ->first();

        if ($match) {
            return [
                'status'      => 'matched',
                'canonical'   => $match->canonical_name,
                'alias_type'  => $match->alias_type,
            ];
        }

        // Check for ambiguous (multiple partial hits — not implemented yet, just unmatched)
        return [
            'status'     => 'unmatched',
            'canonical'  => null,
            'alias_type' => null,
        ];
    }

    /** Simple text normalisation */
    private static function normalize(string $text): string
    {
        $text = trim($text);
        // Remove trailing period, common suffixes
        $text = preg_replace('/\s+(kota|daerah|district|area)\s*$/i', '', $text);
        return trim($text);
    }

    /**
     * Seed initial Malaysian place aliases.
     * Run: php artisan db:seed --class=LocationAliasSeeder
     */
    public static function seedMalaysianPlaces(): iterable
    {
        $aliases = [
            // ── Cities ────────────────────────────────────────────────────
            ['PJ',           'Petaling Jaya',      'city'],
            ['Petaling Jaya', 'Petaling Jaya',      'city'],
            ['Kuala Lumpur', 'Kuala Lumpur',       'city'],
            ['KL',           'Kuala Lumpur',        'city'],
            ['Klang',        'Klang',               'city'],
            ['Shah Alam',    'Shah Alam',           'city'],
            ['Malacca',      'Malacca',             'city'],
            ['Melaka',       'Malacca',             'city'],
            ['George Town',  'George Town',         'city'],
            ['Penang',       'George Town',          'city'],
            ['Johor Bahru',  'Johor Bahru',         'city'],
            ['JB',           'Johor Bahru',          'city'],
            ['Kuching',      'Kuching',              'city'],
            ['Kota Kinabalu','Kota Kinabalu',        'city'],
            ['KK',           'Kota Kinabalu',        'city'],
            ['Ipoh',         'Ipoh',                 'city'],
            ['Kota Bharu',   'Kota Bharu',           'city'],
            ['Alor Setar',   'Alor Setar',           'city'],
            ['Seremban',     'Seremban',             'city'],
            ['Miri',         'Miri',                 'city'],
            ['Sibu',         'Sibu',                 'city'],

            // ── Districts / Areas ───────────────────────────────────────
            ['Subang',       'Subang',               'area'],
            ['Subang Jaya',  'Subang Jaya',          'area'],
            ['Damansara',    'Damansara',            'area'],
            ['Bangsar',      'Bangsar',              'area'],
            ['Mont Kiara',   'Mont Kiara',           'area'],
            ['TTDI',         'Taman Tun Dr Ismail',  'area'],
            ['Ampang',       'Ampang',               'area'],
            ['Cheras',       'Cheras',               'area'],
            ['Puchong',      'Puchong',              'area'],
            ['Cyberjaya',    'Cyberjaya',            'area'],
            ['Putrajaya',    'Putrajaya',            'area'],
            ['Rawang',       'Rawang',               'area'],
            ['Kajang',       'Kajang',               'area'],
            ['Bandar Sunway','Bandar Sunway',        'area'],
            ['Petaling Jaya Sentral', 'Petaling Jaya', 'area'],

            // ── Regions ─────────────────────────────────────────────────
            ['Klang Valley', 'Klang Valley',        'region'],
            ['Northern Malaysia', 'Northern Malaysia','region'],
            ['East Coast',   'East Coast Malaysia',  'region'],
            ['Southern Malaysia','Southern Malaysia', 'region'],
            ['Borneo',       'East Malaysia',        'region'],
            ['East Malaysia','East Malaysia',        'region'],
        ];

        foreach ($aliases as $alias) {
            yield $alias;
        }
    }
}
