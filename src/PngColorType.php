<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\DeviceColorSpace;

/** PNG colour type codes, distinct from the PDF image colour space. */
enum PngColorType: int
{
    case Gray = 0;
    case RGB = 2;
    case Indexed = 3;
    case GrayAlpha = 4;
    case RGBA = 6;

    public function components(): int
    {
        return match ($this) {
            self::Gray, self::Indexed => 1,
            self::GrayAlpha => 2,
            self::RGB => 3,
            self::RGBA => 4,
        };
    }

    public function colorSpace(): DeviceColorSpace
    {
        return match ($this) {
            self::Gray, self::GrayAlpha => DeviceColorSpace::Gray,
            default => DeviceColorSpace::RGB,
        };
    }
}
