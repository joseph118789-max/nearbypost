<?php

namespace App\Services\Marketplace;

/**
 * Turns a real position into the one the public may see. Spec §13.2, §15.2.
 *
 * ⛔ THE COARSE POINT IS A GRID SNAP, NOT RANDOM JITTER.
 *
 * Jitter looks safer and is not. If the public point is the true point plus a
 * random offset, every fresh read is another sample of the same distribution,
 * and the mean of enough samples is the true point. A watcher who reloads a
 * listing two hundred times recovers the home address they were being
 * protected from.
 *
 * A grid snap has no such property. The same private point always yields the
 * same public point - the centre of the cell it falls in - so reading it a
 * thousand times reveals exactly what reading it once did: which cell.
 *
 * The cell is about 1.1 km on a side, which is small enough to be useful on a
 * map and large enough that a Malaysian residential street cannot be picked out
 * of it.
 */
class PublicLocation
{
    /**
     * 0.01 degrees of latitude is 1.11 km. Longitude cells are the same span in
     * degrees, so they narrow towards the poles - which is the right way round:
     * the cell stays at most 1.11 km wide and gets smaller, never larger.
     */
    private const CELL_DEGREES = 0.01;

    /**
     * What the public sees for one listing or provider.
     *
     * @param  string  $mode  exact_premises | approximate_area | service_area | online_only
     * @return array{lat: ?float, lng: ?float}
     */
    public static function derive(string $mode, ?float $lat, ?float $lng): array
    {
        if ($lat === null || $lng === null) {
            return ['lat' => null, 'lng' => null];
        }

        return match ($mode) {
            // A shop with a shopfront. The point IS the answer, and hiding it
            // would make the listing useless.
            'exact_premises' => ['lat' => round($lat, 7), 'lng' => round($lng, 7)],

            // A home kitchen, a mobile service, a car-pool origin. The cell,
            // never the address.
            'approximate_area', 'service_area' => self::cell($lat, $lng),

            // Nothing to show on a map at all.
            'online_only' => ['lat' => null, 'lng' => null],

            // ⛔ An unknown mode coarsens. A mode this class has not been taught
            // is most likely a new one added to a form, and the safe reading of
            // "I do not know how precise this may be" is "not very".
            default => self::cell($lat, $lng),
        };
    }

    /**
     * The centre of the cell the point falls in.
     *
     * Deterministic: the same private point always lands on the same public
     * one, so repetition reveals nothing that a single read did not.
     */
    public static function cell(float $lat, float $lng): array
    {
        return [
            'lat' => round(floor($lat / self::CELL_DEGREES) * self::CELL_DEGREES + self::CELL_DEGREES / 2, 7),
            'lng' => round(floor($lng / self::CELL_DEGREES) * self::CELL_DEGREES + self::CELL_DEGREES / 2, 7),
        ];
    }

    /**
     * How far the public point may be from the real one, in metres, for the
     * wording a reader is shown ("within about a kilometre of here").
     *
     * Half the diagonal of the cell, which is the worst case.
     */
    public static function uncertaintyMetres(string $mode): int
    {
        return match ($mode) {
            'exact_premises' => 0,
            'online_only'    => 0,
            default          => (int) round(self::CELL_DEGREES * 111_320 * M_SQRT2 / 2),
        };
    }
}
