<?php

declare(strict_types=1);

namespace PDF\Images;

/** Cumulative decoded buffers for one import; never shared implicitly between images. */
final class ImageImportBudget
{
    private int $decoded = 0;

    public function __construct(private readonly ImageImportLimits $limits)
    {
    }

    public function reserve(int $bytes): void
    {
        if ($bytes < 0 || $bytes > $this->limits->maxCumulativeDecodedBytes - $this->decoded) {
            throw new \InvalidArgumentException('Image conversion exceeds the cumulative decoded-byte budget.');
        }
        $this->decoded += $bytes;
    }
}
