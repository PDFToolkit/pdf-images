<?php

declare(strict_types=1);

namespace PDF\Images;

/** PNG 3, section 8.1: empty Adam7 passes have no filter bytes. */
final readonly class PngPasses
{
    /** @var list<PngPass> */
    public array $passes;
    public int $filteredBytes;

    public function __construct(PngHeader $header, ImageImportLimits $limits)
    {
        $geometry = $header->interlace === 0 ? [[0, 0, 1, 1]] : [
            [0, 0, 8, 8], [4, 0, 8, 8], [0, 4, 4, 8], [2, 0, 4, 4],
            [0, 2, 2, 4], [1, 0, 2, 2], [0, 1, 1, 2],
        ];
        $passes = [];
        $total = 0;
        foreach ($geometry as [$x, $y, $dx, $dy]) {
            if ($x >= $header->dimensions->width || $y >= $header->dimensions->height) {
                continue;
            }
            $width = intdiv($header->dimensions->width - $x + $dx - 1, $dx);
            $height = intdiv($header->dimensions->height - $y + $dy - 1, $dy);
            $row = $header->rowBytes($width) + 1;
            if ($height > intdiv($limits->maxDecodedBytes - $total, $row)) {
                throw new \InvalidArgumentException('Filtered PNG passes exceed the import byte limit.');
            }
            $total += $row * $height;
            $passes[] = new PngPass($x, $y, $dx, $dy, $width, $height);
        }
        $this->passes = $passes;
        $this->filteredBytes = $total;
    }
}
