<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\SampledImage;
use PDF\Document\Images\SampleLayout;

/** Preserves sample precision; indexed colour expands to RGB and opacity is unassociated. */
final readonly class PngSampleConverter
{
    public function __construct(private ImageImportLimits $limits)
    {
    }

    public function convert(PngRaster $raster, ?string $palette, ?string $transparency, ?ImageIccProfile $profile, ImageImportBudget $budget): SampledImage
    {
        $header = $raster->header;
        $type = $header->colorType;
        $space = $profile?->colorSpace() ?? $type->colorSpace();
        $depth = $type === PngColorType::Indexed ? 8 : $header->depth;
        $intrinsicAlpha = $type === PngColorType::GrayAlpha || $type === PngColorType::RGBA;
        $hasAlpha = $intrinsicAlpha || $transparency !== null;
        $alphaDepth = $intrinsicAlpha ? $header->depth : 8;
        $colorBytes = $this->limits->validateDimensions($header->dimensions, $type->colorSpace()->components(), $depth);
        $alphaBytes = $hasAlpha ? $this->limits->validateDimensions($header->dimensions, 1, $alphaDepth) : 0;
        if ($alphaBytes > $this->limits->maxDecodedBytes - $colorBytes) {
            throw new \InvalidArgumentException('PNG colour and alpha exceed the combined output limit.');
        }
        $budget->reserve($colorBytes + $alphaBytes);
        if (!$hasAlpha && $type !== PngColorType::Indexed) {
            return new SampledImage(new ImageSamples($header->dimensions, new SampleLayout($space, $depth), $raster->bytes));
        }
        $colors = $intrinsicAlpha || $type === PngColorType::Indexed ? '' : $raster->bytes;
        $alpha = '';
        for ($y = 0; $y < $header->dimensions->height; ++$y) {
            for ($x = 0; $x < $header->dimensions->width; ++$x) {
                if ($type === PngColorType::Indexed) {
                    $index = $raster->sample($x, $y);
                    if ($palette === null || $index >= intdiv(strlen($palette), 3)) {
                        throw new \InvalidArgumentException('PNG pixel index exceeds its palette.');
                    }
                    $colors .= substr($palette, $index * 3, 3);
                    if ($hasAlpha) {
                        $alpha .= $transparency !== null && $index < strlen($transparency) ? $transparency[$index] : "\xff";
                    }
                } elseif ($intrinsicAlpha) {
                    $width = intdiv($header->depth, 8);
                    $pixel = ($y * $header->dimensions->width + $x) * $type->components() * $width;
                    $colors .= substr($raster->bytes, $pixel, ($type->components() - 1) * $width);
                    $alpha .= substr($raster->bytes, $pixel + ($type->components() - 1) * $width, $width);
                } elseif ($transparency !== null) {
                    $transparent = true;
                    for ($component = 0; $component < $type->components(); ++$component) {
                        $value = ord($transparency[$component * 2]) * 256 + ord($transparency[$component * 2 + 1]);
                        $transparent = $transparent && $raster->sample($x, $y, $component) === $value;
                    }
                    $alpha .= $transparent ? "\0" : "\xff";
                }
            }
        }
        return new SampledImage(
            new ImageSamples($header->dimensions, new SampleLayout($space, $depth), $colors),
            $hasAlpha ? new ImageSamples($header->dimensions, new SampleLayout(DeviceColorSpace::Gray, $alphaDepth), $alpha) : null,
        );
    }
}
