<?php

declare(strict_types=1);

namespace PDF\Images;

/** Restores each pass independently before scattering samples into their final rows. */
final readonly class PngRasterDecoder
{
    public function __construct(private PngScanlineDecoder $scanlines = new PngScanlineDecoder())
    {
    }

    public function decode(string $filtered, PngHeader $header, PngPasses $passes, ImageImportBudget $budget): PngRaster
    {
        $rowBytes = $header->rowBytes($header->dimensions->width);
        $size = $rowBytes * $header->dimensions->height;
        $budget->reserve($size);
        $bytes = $header->interlace === 0 ? '' : str_repeat("\0", $size);
        $offset = 0;
        $pixelBits = $header->colorType->components() * $header->depth;
        foreach ($passes->passes as $pass) {
            $passRow = $header->rowBytes($pass->dimensions->width);
            $length = ($passRow + 1) * $pass->dimensions->height;
            // The pass slice, reconstructed rows and row scratch buffers are explicit allocations.
            $budget->reserve($length);
            $budget->reserve($passRow * $pass->dimensions->height);
            $budget->reserve(2 * $passRow);
            $decoded = $this->scanlines->decode(substr($filtered, $offset, $length), $pass->dimensions, $header->colorType, $header->depth);
            $offset += $length;
            if ($header->interlace === 0) {
                return new PngRaster($header, $decoded);
            }
            for ($y = 0; $y < $pass->dimensions->height; ++$y) {
                for ($x = 0; $x < $pass->dimensions->width; ++$x) {
                    $target = ($pass->y + $y * $pass->dy) * $rowBytes * 8 + ($pass->x + $x * $pass->dx) * $pixelBits;
                    $source = $y * $passRow * 8 + $x * $pixelBits;
                    if ($pixelBits >= 8) {
                        for ($byte = 0; $byte < intdiv($pixelBits, 8); ++$byte) {
                            $bytes[intdiv($target, 8) + $byte] = $decoded[intdiv($source, 8) + $byte];
                        }
                    } else {
                        $mask = (1 << $pixelBits) - 1;
                        $sample = (ord($decoded[intdiv($source, 8)]) >> (8 - $pixelBits - $source % 8)) & $mask;
                        $shift = 8 - $pixelBits - $target % 8;
                        $index = intdiv($target, 8);
                        $bytes[$index] = chr((ord($bytes[$index]) & ~($mask << $shift)) | ($sample << $shift));
                    }
                }
            }
        }
        return new PngRaster($header, $bytes);
    }
}
