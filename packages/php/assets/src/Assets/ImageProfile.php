<?php
namespace ZoltaGaska\Assets;

final class ImageProfile
{
    public const MAX_BYTES = 4194304;
    public const MAX_PIXELS = 4194304;

    /** Decode the supplied bytes only after checking the uncompressed image budget. */
    public static function decode(string $bytes, ?string $expectedMime = null): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? null;
        if (strlen($bytes) > self::MAX_BYTES || !in_array($mime, ['image/png', 'image/jpeg'], true)
            || ($expectedMime !== null && $mime !== $expectedMime)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048) {
            throw new \DomainException('Image outside supported PNG/JPEG profile.');
        }
        if (($mime === 'image/png' && !str_ends_with($bytes, hex2bin('0000000049454e44ae426082')))
            || ($mime === 'image/jpeg' && !str_ends_with($bytes, "\xff\xd9"))) throw new \DomainException('Invalid image ending.');
        $image = @imagecreatefromstring($bytes);
        if (!$image) throw new \DomainException('Invalid image data.');
        return [$image, $mime, $info[0] * $info[1]];
    }

    public static function poster(string $bytes): array
    {
        [$image, $mime] = self::decode($bytes);
        $stream = tmpfile();
        if (!$stream) throw new \RuntimeException('Cannot prepare poster.');
        imagesavealpha($image, true);
        $ok = $mime === 'image/png' ? imagepng($image, $stream) : imagejpeg($image, $stream, 90);
        if (!$ok || !rewind($stream)) throw new \RuntimeException('Cannot encode poster.');
        if (fstat($stream)['size'] > self::MAX_BYTES) throw new \DomainException('Prepared poster exceeds byte budget.');
        return [$stream, $mime];
    }
}
