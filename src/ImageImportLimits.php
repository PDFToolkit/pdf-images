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
        public int $maxCumulativeDecodedBytes = 67_108_864,
        public int $maxProfileBytes = 4_194_304,
        public int $maxProfileTags = 4096,
    ) {
        if (min($maxEncodedBytes, $maxDecodedBytes, $maxPixels, $maxDimension, $maxChunks, $maxCumulativeDecodedBytes, $maxProfileBytes, $maxProfileTags) < 1) {
            throw new \InvalidArgumentException('Image import limits must be positive integers.');
        }
    }

    public function validateFile(ImageFileBytes $file): void
    {
        if (strlen($file->bytes) > $this->maxEncodedBytes) {
            throw new \InvalidArgumentException('Encoded image exceeds the import byte limit.');
        }
    }

    public function validateDimensions(ImageDimensions $dimensions, int $components, int $bitsPerComponent = 8): int
    {
        if ($components < 1 || $components > 4) {
            throw new \InvalidArgumentException('An image must have one to four encoded components.');
        }
        if (max($dimensions->width, $dimensions->height) > $this->maxDimension || $dimensions->pixels() > $this->maxPixels) {
            throw new \InvalidArgumentException('Image dimensions exceed the import limits.');
        }
        if (!in_array($bitsPerComponent, [1, 2, 4, 8, 16], true)
            || $dimensions->width > intdiv(PHP_INT_MAX - 7, $components * $bitsPerComponent)) {
            throw new \InvalidArgumentException('Image sample depth or scanline length is invalid.');
        }
        $rowBytes = intdiv($dimensions->width * $components * $bitsPerComponent + 7, 8);
        if ($dimensions->height > intdiv($this->maxDecodedBytes, $rowBytes)) {
            throw new \InvalidArgumentException('Decoded image exceeds the import byte limit.');
        }
        return $dimensions->height * $rowBytes;
    }
}
