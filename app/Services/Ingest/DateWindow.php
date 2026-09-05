<?php

namespace App\Services\Ingest;

use Carbon\Carbon;

/**
 * The dates a promotion or an event actually runs between.
 *
 * A news story is over the moment it is published; a warehouse sale running
 * "16-20 September" is not. Ordering those by publication date buries them the
 * day after they appear and drops them out of the feed while they are still on,
 * which is the opposite of useful - a sale is only worth reading about BEFORE
 * it ends.
 *
 * So sources of this kind get a window rather than a timestamp, and the feed
 * keeps them until the window closes.
 *
 * The titles carry it plainly, which is why this is a parser and not a model
 * call: "16-20 September 2026: AKEMI Warehouse Sale at The Starling".
 */
class DateWindow
{
    private const MONTHS = [
        'january' => 1, 'januari' => 1, 'jan' => 1,
        'february' => 2, 'februari' => 2, 'feb' => 2,
        'march' => 3, 'mac' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4,
        'may' => 5, 'mei' => 5,
        'june' => 6, 'jun' => 6,
        'july' => 7, 'julai' => 7, 'jul' => 7,
        'august' => 8, 'ogos' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9,
        'october' => 10, 'oktober' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'december' => 12, 'disember' => 12, 'dec' => 12,
    ];

    /**
     * @return array{start: ?string, end: ?string, open: bool}
     *         start/end as Y-m-d, or nulls when the title says nothing.
     *         `open` marks a run with no stated finish - "2 September onwards".
     */
    public static function parse(string $title): array
    {
        $none = ['start' => null, 'end' => null, 'open' => false];

        $text = mb_strtolower(str_replace(
            ['–', '—', ' to ', ' hingga '],
            ['-', '-', '-', '-'],
            $title
        ));

        $months = implode('|', array_keys(self::MONTHS));

        // "1 january 2020-1 january 2026" - a year on BOTH sides. Matched first,
        // or the single-date rule below claims the opening date and quietly
        // turns a six-year span into a one-day window.
        if (preg_match('/(\d{1,2})\s+(' . $months . ')\s+(\d{4})\s*-\s*(\d{1,2})\s+(' . $months . ')\s+(\d{4})/u', $text, $m)) {
            return self::build($m[3], self::MONTHS[$m[2]], $m[1], $m[6], self::MONTHS[$m[5]], $m[4]);
        }

        // "31 august-13 september 2026" - two months, one year at the end.
        if (preg_match('/(\d{1,2})\s+(' . $months . ')\s*-\s*(\d{1,2})\s+(' . $months . ')\s+(\d{4})/u', $text, $m)) {
            return self::build($m[5], self::MONTHS[$m[2]], $m[1], $m[5], self::MONTHS[$m[4]], $m[3]);
        }

        // "16-20 september 2026" - one month, one year.
        if (preg_match('/(\d{1,2})\s*-\s*(\d{1,2})\s+(' . $months . ')\s+(\d{4})/u', $text, $m)) {
            return self::build($m[4], self::MONTHS[$m[3]], $m[1], $m[4], self::MONTHS[$m[3]], $m[2]);
        }

        // "2 september 2026 onwards" - a start and no stated finish.
        if (preg_match('/(\d{1,2})\s+(' . $months . ')\s+(\d{4})\s*(onwards|onward|hingga habis|while stocks last)/u', $text, $m)) {
            $start = self::date($m[3], self::MONTHS[$m[2]], $m[1]);

            return ['start' => $start, 'end' => null, 'open' => $start !== null];
        }

        // "4 september 2026" - a single day.
        if (preg_match('/(\d{1,2})\s+(' . $months . ')\s+(\d{4})/u', $text, $m)) {
            $day = self::date($m[3], self::MONTHS[$m[2]], $m[1]);

            return ['start' => $day, 'end' => $day, 'open' => false];
        }

        return $none;
    }

    /**
     * A window is only useful if it is sane.
     *
     * A sale that ends before it starts, or runs for three years, is a parse
     * that went wrong - and a wrong window would hold a dead promotion on the
     * site for those three years, which is worse than having no window at all.
     */
    private static function build(string $y1, int $m1, string $d1, string $y2, int $m2, string $d2): array
    {
        $start = self::date($y1, $m1, $d1);
        $end   = self::date($y2, $m2, $d2);

        if ($start === null || $end === null || $end < $start) {
            return ['start' => null, 'end' => null, 'open' => false];
        }

        if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) > 400) {
            return ['start' => null, 'end' => null, 'open' => false];
        }

        return ['start' => $start, 'end' => $end, 'open' => false];
    }

    private static function date(string $year, int $month, string $day): ?string
    {
        $y = (int) $year;
        $d = (int) $day;

        if ($y < 2020 || $y > 2100 || !checkdate($month, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $month, $d);
    }
}
