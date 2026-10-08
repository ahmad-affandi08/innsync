<?php

declare(strict_types=1);

namespace App\Modules\Property\Application\Branding;

use App\Shared\Application\Errors\Refusal;
use DOMDocument;

/**
 * Checks a logo a person uploaded and returns what is safe to keep. A picture is read by its content, never by its file name; a photo is re-drawn so the
 * location and camera details inside it are dropped; a vector (SVG) is refused rather than repaired if it holds anything but shapes, because a vector can carry scripts.
 */
final class LogoImage
{
    public const MAX_BYTES = 524288;

    public const MIN_SIDE = 64;

    public const MAX_SIDE = 4000;

    /** @return array{mime: string, content: string} */
    public static function accept(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw Refusal::invalid('The logo is at most 512 KB.', ['logo']);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return match (true) {
            $mime === 'image/svg+xml' || self::looksLikeSvg($bytes) => ['mime' => 'image/svg+xml', 'content' => self::vector($bytes)],
            in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) => self::raster($bytes, $mime),
            default => throw Refusal::invalid('Use a PNG, JPEG, WebP or SVG picture.', ['logo']),
        };
    }

    private static function looksLikeSvg(string $bytes): bool
    {
        return preg_match('/^\s*(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $bytes) === 1;
    }

    /** @return array{mime: string, content: string} */
    private static function raster(string $bytes, string $mime): array
    {
        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[0] < self::MIN_SIDE || $size[1] < self::MIN_SIDE || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw Refusal::invalid('The picture must be between 64 and 4000 pixels on each side.', ['logo']);
        }

        if (! function_exists('imagecreatefromstring')) {
            return ['mime' => $mime, 'content' => $bytes];
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw Refusal::invalid('This picture cannot be read.', ['logo']);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();

        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 90),
            'image/webp' => imagewebp($image, null, 90),
            default => imagepng($image, null, 6),
        };

        $clean = (string) ob_get_clean();
        imagedestroy($image);

        return ['mime' => $mime, 'content' => $clean];
    }

    private static function vector(string $bytes): string
    {
        // Anything that can run, load or point outside the picture refuses it.
        if (preg_match('/<!DOCTYPE|<!ENTITY|<script|<foreignObject|<iframe|<object|<embed|<audio|<video|<animate|<set\b|javascript:|data:text|\son[a-z]+\s*=|@import|url\s*\(\s*[\'"]?\s*(https?:|\/\/)/i', $bytes) === 1) {
            throw Refusal::invalid('This SVG contains something other than shapes. Export it again as plain shapes, or use a PNG.', ['logo']);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($bytes, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $document->documentElement : null;

        if ($root === null || strtolower($root->localName) !== 'svg') {
            throw Refusal::invalid('This SVG cannot be read.', ['logo']);
        }

        foreach ($document->getElementsByTagName('*') as $node) {
            foreach (['href', 'xlink:href'] as $attribute) {
                $value = $node->getAttribute($attribute);

                if ($value !== '' && ! str_starts_with($value, '#')) {
                    throw Refusal::invalid('This SVG refers to another file. Use one with shapes only.', ['logo']);
                }
            }
        }

        if (! $root->hasAttribute('viewBox') && (! $root->hasAttribute('width') || ! $root->hasAttribute('height'))) {
            throw Refusal::invalid('The SVG has no size. Export it with a viewBox.', ['logo']);
        }

        return $bytes;
    }
}
