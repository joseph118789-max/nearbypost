<?php

namespace App\Services\Community;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * What can be known about a photo without a vision model: its hashes (exact
 * and perceptual, for duplicates within Nearbypost), the EXIF capture time
 * and GPS read privately from the ORIGINAL upload before it is re-encoded
 * (re-encoding strips EXIF from the public copy), and whether the photo's
 * own GPS agrees with the pin. Never called "verification".
 */
final class ImageChecks
{
    public static function analyse(UploadedFile $file, int $newsItemId, ?array $pin, ?string $publicPath): array
    {
        $path = $file->getRealPath();
        $sha  = hash_file('sha256', $path);
        $phash = self::perceptualHash($path);
        $info = @getimagesize($path) ?: [null, null, null, null];
        $exif = @exif_read_data($path, 'EXIF,GPS', true) ?: [];
        $taken = null; $lat = null; $lng = null;

        if (!empty($exif['EXIF']['DateTimeOriginal'])) {
            try { $taken = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $exif['EXIF']['DateTimeOriginal']); } catch (\Throwable) {}
        }

        if (!empty($exif['GPS']['GPSLatitude']) && !empty($exif['GPS']['GPSLongitude'])) {
            $lat = self::coord($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
            $lng = self::coord($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
        }

        $consistency = 'no_exif'; $distance = null;

        if ($lat !== null && $pin) {
            $distance = (int) round(self::metres($lat, $lng, $pin['lat'], $pin['lng']));
            $consistency = $distance <= 1500 ? 'consistent' : 'far';
        }

        // duplicates within Nearbypost: exact by sha256, near by hamming distance on the perceptual hash
        $dupId = null; $dupKind = null;
        $exact = DB::table('community_post_media')->where('sha256', $sha)->where('news_item_id', '<>', $newsItemId)->value('news_item_id');

        if ($exact) {
            $dupId = (int) $exact; $dupKind = 'exact';
        } elseif ($phash !== null) {
            foreach (DB::table('community_post_media')->whereNotNull('perceptual_hash')->where('news_item_id', '<>', $newsItemId)->orderByDesc('id')->limit(2000)->get(['news_item_id', 'perceptual_hash']) as $row) {
                if (self::hamming($phash, $row->perceptual_hash) <= 6) { $dupId = (int) $row->news_item_id; $dupKind = 'near'; break; }
            }
        }

        DB::table('community_post_media')->updateOrInsert(['news_item_id' => $newsItemId], [
            'public_path' => $publicPath, 'mime_type' => $info['mime'] ?? null, 'width' => $info[0] ?? null, 'height' => $info[1] ?? null,
            'size_bytes' => $file->getSize(), 'sha256' => $sha, 'perceptual_hash' => $phash, 'exif_captured_at' => $taken,
            'exif_lat_private' => $lat, 'exif_lng_private' => $lng, 'exif_consistency' => $consistency, 'exif_distance_m' => $distance,
            'duplicate_of' => $dupId, 'duplicate_kind' => $dupKind, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return ['sha256' => $sha, 'phash' => $phash, 'taken' => $taken, 'consistency' => $consistency, 'distance_m' => $distance, 'duplicate_of' => $dupId, 'duplicate_kind' => $dupKind];
    }

    /** 8x8 average hash, 64 bits as 16 hex chars. */
    public static function perceptualHash(string $path): ?string
    {
        $img = @imagecreatefromstring((string) @file_get_contents($path));

        if (!$img) {
            return null;
        }

        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));
        $vals = [];

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $vals[] = (int) round(0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
            }
        }

        $avg = array_sum($vals) / 64;
        $bits = '';

        foreach ($vals as $v) {
            $bits .= $v >= $avg ? '1' : '0';
        }

        return str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT) . str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT);
    }

    public static function hamming(string $a, string $b): int
    {
        $d = 0;

        for ($i = 0; $i < min(strlen($a), strlen($b)); $i++) {
            $d += substr_count(str_pad(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), 4, '0', STR_PAD_LEFT), '1');
        }

        return $d;
    }

    private static function coord(array $parts, string $ref): ?float
    {
        $f = fn ($s) => (function ($s) { [$n, $d] = array_pad(explode('/', (string) $s), 2, 1); return (float) $d === 0.0 ? 0.0 : (float) $n / (float) $d; })($s);
        $deg = $f($parts[0] ?? 0) + $f($parts[1] ?? 0) / 60 + $f($parts[2] ?? 0) / 3600;

        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$deg : $deg;
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
