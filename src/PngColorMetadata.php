<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Graphics\ColourSpaces\CalGray;
use PDF\Graphics\ColourSpaces\CalRGB;
use PDF\Graphics\ColourSpaces\ColourSpace;
use PDF\Graphics\ColourSpaces\GammaValues;
use PDF\Graphics\GraphicsState\RenderingIntent;
use PDF\Syntax\Object\NumericType;

/** Per-image colour declarations, applied by PNG table 1 precedence after all chunks are read. */
final class PngColorMetadata
{
    /** @var array<string, true> */
    private array $seen = [];
    private ?float $gamma = null;
    private ?PngChromaticities $chromaticities = null;
    private ?int $intent = null;

    public function read(PngChunk $chunk, bool $paletteSeen, bool $dataSeen): void
    {
        if (isset($this->seen[$chunk->type]) || $paletteSeen || $dataSeen) {
            throw new \InvalidArgumentException('PNG colour chunks must occur once before PLTE and IDAT.');
        }
        $this->seen[$chunk->type] = true;
        $expected = match ($chunk->type) {
            'gAMA' => 4,
            'cHRM' => 32,
            'sRGB' => 1,
            default => throw new \InvalidArgumentException('Unknown PNG colour declaration.'),
        };
        if (strlen($chunk->data) !== $expected) {
            throw new \InvalidArgumentException('PNG colour metadata has an invalid length.');
        }
        if ($chunk->type === 'sRGB') {
            $this->intent = ord($chunk->data);
            if ($this->intent > 3) {
                throw new \InvalidArgumentException('PNG sRGB intent must be between zero and three.');
            }
            return;
        }
        $values = unpack('N*', $chunk->data);
        if ($values === false) {
            throw new \InvalidArgumentException('PNG colour metadata cannot be read.');
        }
        if ($chunk->type === 'gAMA') {
            // PNG 3 section 13.13 explicitly tells decoders to ignore zero gamma.
            $this->gamma = $values[1] === 0 ? null : $values[1] / 100000;
        } else {
            $this->chromaticities = new PngChromaticities(...array_map(static fn (int $value): float => $value / 100000, array_values($values)));
        }
    }

    public function colorSpace(PngHeader $header, ?ImageIccProfile $profile, PngColorDefaults $defaults, ImageImportLimits $limits, ImageImportBudget $budget): ?ColourSpace
    {
        if ($profile !== null) {
            return $profile->colorSpace();
        }
        $components = $header->colorType->colorSpace()->components();
        if ($this->intent !== null) {
            return (new PngSrgbProfile())->read($components, $this->intent, $limits, $budget)->colorSpace();
        }
        if ($this->gamma === null && $this->chromaticities === null) {
            return null;
        }
        $chromaticities = $this->chromaticities ?? $defaults->chromaticities;
        $gamma = new NumericType(1 / ($this->gamma ?? $defaults->imageGamma));
        return $components === 1
            ? new CalGray($chromaticities->whitePoint(), $gamma)
            : new CalRGB($chromaticities->whitePoint(), new GammaValues($gamma, $gamma, $gamma), $chromaticities->matrix());
    }

    public function renderingIntent(?ImageIccProfile $profile): ?RenderingIntent
    {
        if ($profile !== null || $this->intent === null) {
            return null;
        }
        return match ($this->intent) {
            0 => RenderingIntent::Perceptual,
            1 => RenderingIntent::RelativeColorimetric,
            2 => RenderingIntent::Saturation,
            3 => RenderingIntent::AbsoluteColorimetric,
            default => throw new \LogicException('The PNG rendering intent was not validated.'),
        };
    }
}
