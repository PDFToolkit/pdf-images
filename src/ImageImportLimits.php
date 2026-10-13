<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\ImageDimensions;

/** Resource bounds applied before allocating decoded image rows. */
final readonly class ImageImportLimits
{
    public function __construct(
        public int $maxEncodedBytes = 16_777_216,
        public int $maxDecodedBytes = 16_777_216,
        public int $maxPixels = 4_194_304,
        public int $maxDimension = 32_768,
        public int $maxChunks = 65_536,
    ) {
        if (min($maxEncodedBytes, $maxDecodedBytes, $maxPixels, $maxDimension, $maxChunks) < 1) {
            throw new \InvalidArgumentException('Image import limits must be positive integers.');
        }
    }

    public function validateFile(ImageFileBytes $file): void
    {
        if (strlen($file->bytes) > $this->maxEncodedBytes) {
            throw new \InvalidArgumentException('Encoded image exceeds the import byte limit.');
        }
    }

    public function validateDimensions(ImageDimensions $dimensions, int $components): int
    {
        if ($components < 1 || $components > 4) {
            throw new \InvalidArgumentException('An image must have one to four encoded components.');
        }
        if (max($dimensions->width, $dimensions->height) > $this->maxDimension || $dimensions->pixels() > $this->maxPixels) {
            throw new \InvalidArgumentException('Image dimensions exceed the import limits.');
        }
        if ($dimensions->pixels() > intdiv($this->maxDecodedBytes, $components)) {
            throw new \InvalidArgumentException('Decoded image exceeds the import byte limit.');
        }
        return $dimensions->pixels() * $components;
    }
}
