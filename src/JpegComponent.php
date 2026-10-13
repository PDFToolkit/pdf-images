<?php

declare(strict_types=1);

namespace PDF\Images;

/** One component in a JPEG frame header, including its sampling and quantization table. */
final readonly class JpegComponent
{
    public function __construct(public int $identifier, public int $horizontalSampling, public int $verticalSampling, public int $quantizationTable)
    {
        if ($identifier < 0 || $identifier > 255 || min($horizontalSampling, $verticalSampling) < 1
            || max($horizontalSampling, $verticalSampling) > 4 || $quantizationTable < 0 || $quantizationTable > 3) {
            throw new \InvalidArgumentException('Invalid JPEG frame component.');
        }
    }
}
