<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\ImageDimensions;

/** One nonempty reduced image in PNG transmission order. */
final readonly class PngPass
{
    public ImageDimensions $dimensions;

    public function __construct(public int $x, public int $y, public int $dx, public int $dy, int $width, int $height)
    {
        $this->dimensions = new ImageDimensions($width, $height);
    }
}
