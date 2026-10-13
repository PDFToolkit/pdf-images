<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\ImageDimensions;

/** Reconstructs the five PNG scanline filters for byte-aligned samples. */
final readonly class PngScanlineDecoder
{
    public function decode(string $filtered, ImageDimensions $dimensions, PngColorType $colorType): string
    {
        $components = $colorType->components();
        if ($dimensions->width > intdiv(PHP_INT_MAX - 1, $components)) {
            throw new \InvalidArgumentException('PNG scanline length exceeds the integer range.');
        }
        $rowBytes = $dimensions->width * $components;
        if ($dimensions->height > intdiv(PHP_INT_MAX, $rowBytes + 1)
            || strlen($filtered) !== ($rowBytes + 1) * $dimensions->height) {
            throw new \InvalidArgumentException('PNG scanline length does not match its dimensions.');
        }
        $previous = str_repeat("\0", $rowBytes);
        $samples = '';
        $offset = 0;
        for ($rowIndex = 0; $rowIndex < $dimensions->height; ++$rowIndex) {
            $filter = ord($filtered[$offset++]);
            if ($filter > 4) {
                throw new \InvalidArgumentException('Unsupported PNG scanline filter.');
            }
            $row = substr($filtered, $offset, $rowBytes);
            $offset += $rowBytes;
            for ($index = 0; $index < $rowBytes; ++$index) {
                $left = $index < $components ? 0 : ord($row[$index - $components]);
                $above = ord($previous[$index]);
                $upperLeft = $index < $components ? 0 : ord($previous[$index - $components]);
                $predictor = match ($filter) {
                    0 => 0,
                    1 => $left,
                    2 => $above,
                    3 => intdiv($left + $above, 2),
                    4 => $this->paeth($left, $above, $upperLeft),
                };
                $row[$index] = chr((ord($row[$index]) + $predictor) & 255);
            }
            $samples .= $row;
            $previous = $row;
        }
        return $samples;
    }

    private function paeth(int $left, int $above, int $upperLeft): int
    {
        $estimate = $left + $above - $upperLeft;
        $leftDistance = abs($estimate - $left);
        $aboveDistance = abs($estimate - $above);
        $upperLeftDistance = abs($estimate - $upperLeft);
        if ($leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }
        return $aboveDistance <= $upperLeftDistance ? $above : $upperLeft;
    }
}
