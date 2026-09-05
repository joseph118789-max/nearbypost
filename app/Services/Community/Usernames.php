<?php

namespace App\Services\Community;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Unique, lower-case, ASCII @usernames; reserved names refused; a taken name gets a number. */
final class Usernames
{
    private const RESERVED = ['admin', 'administrator', 'nearbypost', 'staff', 'moderator', 'mod', 'support', 'help', 'news', 'official', 'community',
        'root', 'system', 'api', 'me', 'login', 'register', 'contribute', 'post', 'feed', 'people', 'about', 'contact', 'null', 'undefined'];

    public static function normalise(string $raw): string
    {
        $s = Str::ascii(trim($raw));
        $s = strtolower(preg_replace('/[^a-zA-Z0-9_.]+/', '', str_replace([' ', '-'], '_', $s)));
        $s = trim(preg_replace('/_+/', '_', $s), '_.');

        return mb_substr($s, 0, 30);
    }

    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED, true);
    }

    /** A free username derived from a display name. */
    public static function suggest(string $displayName, ?int $ignoreUserId = null): string
    {
        $base = self::normalise($displayName);

        if (strlen($base) < 3 || self::isReserved($base)) {
            $base = 'reader' . random_int(1000, 9999);
        }

        $candidate = $base;
        $n = 1;

        while (self::taken($candidate, $ignoreUserId)) {
            $n++;
            $candidate = mb_substr($base, 0, 26) . $n;
        }

        return $candidate;
    }

    /** Three free names close to the one that was taken. */
    public static function alternatives(string $wanted, ?string $displayName = null): array
    {
        $base = self::normalise($wanted) ?: self::normalise((string) $displayName) ?: 'reader';
        $out = [];
        $tries = [];

        if ($displayName) {
            $parts = array_values(array_filter(preg_split('/[\s_.]+/', strtolower(\Illuminate\Support\Str::ascii($displayName)))));
            $parts = array_map(fn ($p) => preg_replace('/[^a-z0-9]/', '', $p), $parts);
            $parts = array_values(array_filter($parts));

            if (count($parts) >= 2) {
                $tries[] = $parts[0] . '_' . $parts[count($parts) - 1];
                $tries[] = $parts[0] . '.' . $parts[count($parts) - 1];
                $tries[] = $parts[count($parts) - 1] . '_' . $parts[0];
            }
        }

        $tries[] = $base . random_int(2, 99);
        $tries[] = $base . '_' . date('y');
        $tries[] = $base . random_int(100, 999);

        foreach ($tries as $t) {
            $t = mb_substr($t, 0, 30);
            if (strlen($t) >= 3 && !self::isReserved($t) && !self::taken($t) && !in_array($t, $out, true)) { $out[] = $t; }
            if (count($out) === 3) { break; }
        }

        while (count($out) < 3) {
            $t = $base . random_int(100, 999);
            if (!self::taken($t) && !in_array($t, $out, true)) { $out[] = $t; }
        }

        return $out;
    }

    public static function valid(string $name): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_.]{2,29}$/', $name) && !preg_match('/[_.]{2}|[_.]$/', $name);
    }

    public static function taken(string $name, ?int $ignoreUserId = null): bool
    {
        return DB::table('users')->whereRaw('lower(username) = ?', [strtolower($name)])
            ->when($ignoreUserId !== null, fn ($q) => $q->where('id', '<>', $ignoreUserId))->exists();
    }
}
