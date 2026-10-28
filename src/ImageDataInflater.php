<?php

declare(strict_types=1);

namespace PDF\Images;

/** Bounded zlib for image samples and ICC profiles; all output consumes the same import budget. */
final readonly class ImageDataInflater
{
    public function decode(string $compressed, int $maximumBytes, ImageImportBudget $budget, ?int $exactBytes = null): string
    {
        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new \RuntimeException('Could not initialize image zlib decoding.');
        }
        $decoded = '';
        $length = strlen($compressed);
        for ($offset = 0; $offset < $length; $offset += 64) {
            $part = @inflate_add($context, substr($compressed, $offset, 64), ZLIB_SYNC_FLUSH);
            if ($part === false || strlen($part) > $maximumBytes - strlen($decoded)) {
                throw new \InvalidArgumentException('Image zlib data is invalid or exceeds its decoded-byte limit.');
            }
            $budget->reserve(strlen($part));
            $decoded .= $part;
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                if (inflate_get_read_len($context) !== $length) {
                    throw new \InvalidArgumentException('Image contains trailing compressed data.');
                }
                break;
            }
        }
        if (inflate_get_status($context) !== ZLIB_STREAM_END || ($exactBytes !== null && strlen($decoded) !== $exactBytes)) {
            throw new \InvalidArgumentException('Image zlib data is truncated or has an unexpected length.');
        }
        return $decoded;
    }
}
