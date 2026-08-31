<?php

namespace App\Services\Contribution;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Take one picture from a contributor and store something safe and small.
 *
 * A phone photograph is commonly four to eight megabytes and four thousand
 * pixels wide, for a card that displays it at a few hundred. Serving the
 * original would make a feed of twenty stories weigh more than the rest of the
 * site put together, on the mobile connections most of these readers are using.
 *
 * Every upload is decoded and re-encoded rather than moved into place. That is
 * what makes the compression possible, and it is also what makes the file safe:
 * whatever was in the original - a mis-declared file type, EXIF, a comment
 * block with a script in it, GPS coordinates from the contributor's home - does
 * not survive being turned into pixels and written out again. A file that
 * cannot be decoded as an image is refused rather than stored, so nothing that
 * merely claims to be a JPEG ever lands in a web-served directory.
 */
class ImageIntake
{
    /** Wide enough for a full-width card on a dense screen. */
    private const MAX_WIDTH = 1600;
    private const MAX_HEIGHT = 1600;

    /** Visually indistinguishable from the original at these sizes. */
    private const QUALITY = 78;

    /** Refused before decoding, so a huge file is never loaded into memory. */
    public const MAX_UPLOAD_BYTES = 12 * 1024 * 1024;

    /** Guards against a small file that decompresses into gigabytes. */
    private const MAX_PIXELS = 50_000_000;

    private const ACCEPTED = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];

    /**
     * @return string  path relative to the public root, e.g. uploads/posts/2026/09/ab12.jpg
     *
     * @throws \RuntimeException with a message written for the contributor
     */
    public function store(UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException(__('site.image_too_large'));
        }

        $info = @getimagesize($file->getRealPath());

        if ($info === false || !isset(self::ACCEPTED[$info[2]])) {
            throw new \RuntimeException(__('site.image_not_readable'));
        }

        [$width, $height, $type] = $info;

        if ($width * $height > self::MAX_PIXELS) {
            throw new \RuntimeException(__('site.image_too_large'));
        }

        $decode = self::ACCEPTED[$type];
        $source = @$decode($file->getRealPath());

        if ($source === false) {
            throw new \RuntimeException(__('site.image_not_readable'));
        }

        try {
            $image = $this->resize($source, $width, $height);

            $relative = 'uploads/posts/' . date('Y/m');
            $directory = public_path($relative);

            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException(__('site.image_not_saved'));
            }

            $name = Str::lower(Str::random(24)) . '.jpg';

            // JPEG for everything. A transparent PNG is flattened onto white
            // rather than kept, because these are photographs on white cards.
            if (!imagejpeg($image, $directory . '/' . $name, self::QUALITY)) {
                throw new \RuntimeException(__('site.image_not_saved'));
            }

            @chmod($directory . '/' . $name, 0644);

            return $relative . '/' . $name;
        } finally {
            if (isset($image) && $image !== $source) {
                imagedestroy($image);
            }

            imagedestroy($source);
        }
    }

    /** Remove a stored picture. Silent if it is already gone. */
    public function forget(?string $relativePath): void
    {
        if (!is_string($relativePath) || $relativePath === '') {
            return;
        }

        // Only ever delete inside our own upload tree, whatever the column says.
        if (!str_starts_with($relativePath, 'uploads/posts/') || str_contains($relativePath, '..')) {
            return;
        }

        $full = public_path($relativePath);

        if (is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * @param  \GdImage  $source
     * @return \GdImage
     */
    private function resize($source, int $width, int $height)
    {
        $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1);

        $targetWidth  = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // Transparency becomes white rather than black, which is what a
        // flattened PNG would otherwise give us on a light card.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);

        imagecopyresampled(
            $canvas, $source,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $width, $height
        );

        return $canvas;
    }
}
