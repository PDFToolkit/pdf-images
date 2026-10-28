<?php

declare(strict_types=1);

namespace PDF\Images;

/** Application assumptions for incomplete gAMA/cHRM metadata, never a replacement for a declared profile. */
final readonly class PngColorDefaults
{
    public function __construct(public float $imageGamma = 1 / 2.2, public PngChromaticities $chromaticities = new PngChromaticities())
    {
        if (!is_finite($imageGamma) || $imageGamma <= 0) {
            throw new \InvalidArgumentException('PNG default image gamma must be positive and finite.');
        }
    }
}
