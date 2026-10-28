<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\ImageDimensions;

/** PNG 3, table 12: only the specified colour/depth combinations and interlace methods. */
final readonly class PngHeader
{
    public ImageDimensions $dimensions;
    public PngColorType $colorType;
    public int $depth;
    public int $interlace;

    public function __construct(string $bytes, ImageImportLimits $limits)
    {
        if (strlen($bytes) !== 13) {
            throw new \InvalidArgumentException('PNG must contain a thirteen-byte IHDR.');
        }
        $fields = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $bytes);
        if ($fields === false) {
            throw new \InvalidArgumentException('Invalid PNG header.');
        }
        $this->dimensions = new ImageDimensions($fields['width'], $fields['height']);
        $this->colorType = PngColorType::tryFrom($fields['type']) ?? throw new \InvalidArgumentException('Unknown PNG colour type.');
        $this->depth = $fields['depth'];
        $this->interlace = $fields['interlace'];
        $depths = match ($this->colorType) {
            PngColorType::Gray => [1, 2, 4, 8, 16],
            PngColorType::Indexed => [1, 2, 4, 8],
            default => [8, 16],
        };
        if (!in_array($this->depth, $depths, true) || $fields['compression'] !== 0
            || $fields['filter'] !== 0 || !in_array($this->interlace, [0, 1], true)) {
            throw new \InvalidArgumentException('Unsupported PNG depth, compression, filter or interlace method.');
        }
        $limits->validateDimensions($this->dimensions, $this->colorType->components(), $this->depth);
    }

    public function rowBytes(int $width): int
    {
        return intdiv($width * $this->colorType->components() * $this->depth + 7, 8);
    }
}
