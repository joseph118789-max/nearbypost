<?php

namespace App\Services\Geo;

use App\Services\Geo\Boundaries\BoundaryStore;
use Illuminate\Support\Facades\DB;

/**
 * Search our own gazetteer - the downloaded places (GeoNames, Wikidata,
 * Overture, Foursquare, Who's On First, government lists) - by name, inside
 * a state or a box. Local, unlimited, and holds the rural names no live
 * service indexes. Returns CANDIDATES; the cascade applies the polygon,
 * name and district tests, the same as for any other source.
 */
class GazetteerSearch
{
    /** The normalised form both sides are compared in. */
    public static function key(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = preg_replace('/\s*\([^)]*\)/u', '', $s);
        $s = strtr($s, ['’' => "'", '‘' => "'", '–' => '-', '—' => '-']);
        $s = preg_replace('/[^\p{L}\p{N}\'\-\.\/ ]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);

        return mb_substr(trim($s), 0, 250);
    }

    /**
     * @param  ?string  $iso3   country to search inside
     * @param  ?string  $state  state code (e.g. MY-12) - rows stamped with it, plus rows not yet stamped inside the box
     * @param  ?float[] $bbox   [south, north, west, east]
     * @return list<array{lat:float, lng:float, label:string, display:string, state:?string, country:?string, confidence:float, provider:string, source:string, kind:?string, category:?string, similarity:float}>
     */
    public function find(string $name, ?string $iso3 = 'MYS', ?string $state = null, ?array $bbox = null, int $limit = 8): array
    {
        $k = self::key($name);

        if (mb_strlen($k) < 3) {
            return [];
        }

        // Representatives only: a place folded into another (dup_of) is that other.
        $q = DB::table('gazetteer')->whereNull('dup_of')->select('*')->selectRaw('greatest(similarity(name_key, ?), coalesce(similarity(alt_names, ?), 0)) as sim', [$k, $k]);

        if ($iso3 !== null) {
            $q->where(fn ($w) => $w->where('country', $iso3)->orWhereNull('country'));
        }

        if ($bbox !== null) {
            $q->whereBetween('lat', [$bbox[0], $bbox[1]])->whereBetween('lng', [$bbox[2], $bbox[3]]);
        } elseif ($state !== null) {
            $q->where(fn ($w) => $w->where('state_code', $state)->orWhereNull('state_code'));
        }

        // Trigram similarity uses the GIN index; the prefix and alt-name
        // forms catch "Kampung Tanjong Kapor" against "Kg Tanjung Kapor".
        $q->where(fn ($w) => $w->whereRaw('name_key % ?', [$k])
            ->orWhereRaw('name_key like ?', [$k . '%'])
            ->orWhereRaw('alt_names ilike ?', ['%' . $k . '%']));

        $rows = $q->orderByRaw('(name_key = ?) desc', [$k])->orderByDesc('sim')->orderByRaw('population desc nulls last')->limit($limit)->get();

        $stateNames = [];
        $out = [];

        foreach ($rows as $r) {
            $stateName = null;

            if ($r->state_code !== null) {
                $stateNames[$r->state_code] ??= DB::table('boundaries')->where('level', 1)->where('code', $r->state_code)->value('name');
                $stateName = $stateNames[$r->state_code];
            }

            $display = implode(', ', array_filter([$r->name, $r->admin_text, $stateName]));

            $out[] = [
                'lat'        => (float) $r->lat,
                'lng'        => (float) $r->lng,
                'label'      => (string) $r->name,
                'display'    => $display,
                'state'      => $stateName,
                'country'    => $r->country,
                'confidence' => 0.7,
                'provider'   => 'gazetteer:' . $r->source,
                'source'     => (string) $r->source,
                'kind'       => $r->kind,
                'category'   => $r->category,
                'similarity' => round((float) $r->sim, 3),
            ];
        }

        return $out;
    }

    /** Stamp the state on one row from the polygons (used by the loader). */
    public static function stampRow(int $id, float $lat, float $lng, BoundaryStore $store): void
    {
        $at = $store->locate($lat, $lng);
        DB::table('gazetteer')->where('id', $id)->update(['state_code' => $at['state'] ?? null, 'stamped_at' => now()]);
    }
}
