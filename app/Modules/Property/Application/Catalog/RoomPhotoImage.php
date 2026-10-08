<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Catalog;

use App\Shared\Application\Errors\Refusal;

/**
 * Checks a room photo a person uploaded and turns it into what is kept: a JPEG of at most 1600 px on the long side and a small one of at most 640 px. A picture is read by its content,
 * never by its file name; re-drawing it drops the location and camera details inside it, and a picture with transparency is laid on white. A file that is not a PNG, JPEG or WebP picture
 * is refused, and so is a picture too small to be a photo of a room.
 */
final class RoomPhotoImage
{
    public const MAX_BYTES = 2_097_152;

    public const MIN_SIDE = 300;

    public const MAX_SIDE = 8000;

    public const FULL = 1600;

    public const THUMB = 640;

    /** @return array{full: string, thumb: string} */
    public static function accept(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw Refusal::invalid('The photo is at most 2 MB.', ['photo']);
        }

        if (! function_exists('imagecreatefromstring')) {
            throw Refusal::invalid('This server cannot process pictures yet.', ['photo']);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $size = in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? @getimagesizefromstring($bytes) : false;

        if ($size === false || $size[0] < self::MIN_SIDE || $size[1] < self::MIN_SIDE || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw Refusal::invalid('Use a PNG, JPEG or WebP photo between 300 and 8000 pixels on each side.', ['photo']);
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw Refusal::invalid('This photo cannot be read.', ['photo']);
        }

        try {
            return ['full' => self::jpeg($image, self::FULL), 'thumb' => self::jpeg($image, self::THUMB)];
        } finally {
            imagedestroy($image);
        }
    }

    private static function jpeg(\GdImage $source, int $longSide): string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, $longSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $canvas = imagecreatetruecolor($nw, $nh);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($canvas, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($canvas);

        return $out;
    }
}
