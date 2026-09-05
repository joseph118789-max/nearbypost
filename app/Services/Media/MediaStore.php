<?php

namespace App\Services\Media;

use App\Services\Community\ImageChecks;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Takes an uploaded file and returns a media id, or refuses and says why.
 *
 * Spec §13.3. Everything Marketplace stores goes through here: a listing photo,
 * a provider's logo, a professional's certificate, a report's evidence.
 *
 * ⛔ THE BROWSER'S WORD IS NEVER TAKEN FOR ANYTHING. Not the mime type, not the
 * extension, not the size. A file claiming image/jpeg is opened and asked what
 * it actually is, because a .jpg that is really a PHP script is the oldest
 * upload attack there is and the only defence that works is reading the bytes.
 *
 * ⛔ A PUBLIC IMAGE IS RE-ENCODED, ALWAYS, EVEN WHEN NOTHING NEEDS CHANGING.
 * Re-encoding is what strips EXIF, and EXIF is what carries the GPS coordinates
 * of the kitchen the photograph was taken in. Copying the original across
 * because it is "already a jpeg" publishes a home address.
 */
class MediaStore
{
    /** 8 MB. Larger than any phone photo needs to be, small enough to refuse a video. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Beyond this, re-encoding costs more memory than the image is worth. */
    private const MAX_PIXELS = 6000;

    /** What a browser is allowed to send us, by what the FILE says it is. */
    private const ALLOWED_IMAGE = ['image/jpeg', 'image/png', 'image/webp'];

    /** Evidence may also be a PDF; it is never re-encoded and never public. */
    private const ALLOWED_DOCUMENT = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    private const PRIVATE_PURPOSES = ['credential_document', 'identity_document', 'report_evidence'];

    /**
     * @param  array{purpose: string, user_id?: int, rights_declared?: bool}  $opts
     * @return int  the media id
     *
     * @throws MediaRejected  with a reason a person can act on
     */
    public static function store(UploadedFile $file, array $opts): int
    {
        $purpose = (string) ($opts['purpose'] ?? '');

        if ($purpose === '') {
            throw new MediaRejected('No purpose was given for this upload.');
        }

        $private = in_array($purpose, self::PRIVATE_PURPOSES, true);

        if (!$file->isValid()) {
            throw new MediaRejected('The file did not arrive complete. Please try again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new MediaRejected(sprintf('That file is %s. The limit is 8 MB.',
                self::human((int) $file->getSize())));
        }

        // ⛔ finfo on the real bytes. getClientMimeType() is whatever the
        // browser felt like saying.
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        $allowed = $private ? self::ALLOWED_DOCUMENT : self::ALLOWED_IMAGE;

        if (!in_array($mime, $allowed, true)) {
            throw new MediaRejected($private
                ? 'Documents must be a JPEG, PNG, WebP or PDF.'
                : 'Images must be a JPEG, PNG or WebP.');
        }

        $isImage = $mime !== 'application/pdf';
        $width   = null;
        $height  = null;

        if ($isImage) {
            $size = @getimagesize($file->getRealPath());

            // ⛔ A file that finfo calls an image but GD cannot measure is not
            // an image. This is the check that catches a script wearing a
            // header, and refusing here is cheaper than refusing at re-encode.
            if ($size === false) {
                throw new MediaRejected('That file says it is an image but cannot be read as one.');
            }

            [$width, $height] = $size;

            if ($width > self::MAX_PIXELS || $height > self::MAX_PIXELS) {
                throw new MediaRejected(sprintf('That image is %d by %d pixels. The limit is %d on either side.',
                    $width, $height, self::MAX_PIXELS));
            }
        }

        // Read the original's EXIF BEFORE anything is re-encoded, because
        // re-encoding is precisely what destroys it.
        [$takenAt, $lat, $lng] = $isImage ? self::exif($file->getRealPath()) : [null, null, null];

        $uuid = (string) Str::uuid();
        $disk = $private ? 'local' : 'public';

        // ⛔ The path is built from a UUID, not from the uploader's filename and
        // not from a sequential id. Spec §13.3: "Prevent document-storage URLs
        // from being publicly guessable."
        $folder    = 'media/' . $purpose . '/' . substr($uuid, 0, 2);
        $extension = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg'));
        $path      = $folder . '/' . $uuid . '.' . $extension;

        $sha   = hash_file('sha256', $file->getRealPath()) ?: null;
        $phash = $isImage ? ImageChecks::perceptualHash($file->getRealPath()) : null;

        if ($private || !$isImage) {
            // Evidence is stored exactly as it arrived. It is never served to
            // the public, so there is nothing to strip, and altering evidence
            // would be the wrong thing to do to it.
            Storage::disk($disk)->put($path, file_get_contents($file->getRealPath()));
        } else {
            Storage::disk($disk)->put($path, self::reEncoded($file->getRealPath(), $mime));
        }

        $bytes = (int) (Storage::disk($disk)->size($path) ?: $file->getSize());

        return (int) DB::table('media')->insertGetId([
            'public_uuid'      => $uuid,
            'owner_user_id'    => $opts['user_id'] ?? null,
            'visibility'       => $private ? 'private' : 'public',
            'purpose'          => $purpose,
            'disk'             => $disk,
            'path'             => $path,
            'original_name'    => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime_type'        => $mime,
            'size_bytes'       => $bytes,
            'width'            => $width,
            'height'           => $height,
            'sha256'           => $sha,
            'perceptual_hash'  => $phash,
            'exif_captured_at' => $takenAt,
            'exif_lat_private' => $lat,
            'exif_lng_private' => $lng,
            'moderation_status' => $private ? 'not_required' : 'pending',
            'rights_declared'  => (bool) ($opts['rights_declared'] ?? false),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /**
     * The URL a reader may be shown, or null when there is not one.
     *
     * ⛔ A private row returns null. There is no "unlisted" URL for evidence -
     * a moderator reaches it through an authorised, expiring link built
     * elsewhere, and nothing in a public template can produce one by accident.
     */
    public static function publicUrl(int $mediaId): ?string
    {
        $row = DB::table('media')->where('id', $mediaId)->whereNull('deleted_at')
            ->first(['visibility', 'disk', 'path', 'moderation_status']);

        if (!$row || $row->visibility !== 'public' || $row->moderation_status === 'rejected') {
            return null;
        }

        return Storage::disk($row->disk)->url($row->path);
    }

    /**
     * Re-encode through GD, which drops every metadata block on the way.
     *
     * Imagick is not installed on this server - checked 4 Sep 2026 - so GD it
     * is. Quality 86 is where a photograph stops losing anything a person can
     * see on a phone.
     */
    private static function reEncoded(string $path, string $mime): string
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw new MediaRejected('That image could not be processed.');
        }

        // PNG and WebP can carry transparency, and flattening it onto black
        // would ruin a logo. Both are kept in their own format.
        ob_start();

        try {
            if ($mime === 'image/png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image, null, 6);
            } elseif ($mime === 'image/webp') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagewebp($image, null, 86);
            } else {
                imagejpeg($image, null, 86);
            }

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw new MediaRejected('That image could not be processed.');
        } finally {
            imagedestroy($image);
        }
    }

    /** @return array{0: ?\Carbon\Carbon, 1: ?float, 2: ?float} */
    private static function exif(string $path): array
    {
        if (!function_exists('exif_read_data')) {
            return [null, null, null];
        }

        $exif = @exif_read_data($path, 'EXIF,GPS', true) ?: [];

        $taken = null;

        if (!empty($exif['EXIF']['DateTimeOriginal'])) {
            try {
                $taken = \Carbon\Carbon::createFromFormat('Y:m:d H:i:s', $exif['EXIF']['DateTimeOriginal']);
            } catch (\Throwable $e) {
                $taken = null;
            }
        }

        $lat = null;
        $lng = null;

        if (!empty($exif['GPS']['GPSLatitude']) && !empty($exif['GPS']['GPSLongitude'])) {
            $lat = self::coordinate($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef'] ?? 'N');
            $lng = self::coordinate($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef'] ?? 'E');
        }

        return [$taken, $lat, $lng];
    }

    /** EXIF stores degrees, minutes and seconds as three "numerator/denominator" strings. */
    private static function coordinate(array $parts, string $ref): ?float
    {
        if (count($parts) < 3) {
            return null;
        }

        $value = 0.0;

        foreach ([0, 1, 2] as $i => $unit) {
            [$n, $d] = array_pad(explode('/', (string) $parts[$unit]), 2, 1);

            if ((float) $d == 0.0) {
                return null;
            }

            $value += ((float) $n / (float) $d) / (60 ** $i);
        }

        return in_array(strtoupper($ref), ['S', 'W'], true) ? -$value : $value;
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}
