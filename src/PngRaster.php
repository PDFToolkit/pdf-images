<?php

declare(strict_types=1);

namespace PDF\Images;

/** Reconstructed, row-padded PNG samples in their original precision. */
final readonly class PngRaster
{
    public function __construct(public PngHeader $header, public string $bytes)
    {
        if (strlen($bytes) !== $header->rowBytes($header->dimensions->width) * $header->dimensions->height) {
            throw new \InvalidArgumentException('PNG raster length does not match its header.');
        }
    }

    public function sample(int $x, int $y, int $component = 0): int
    {
        if ($x < 0 || $x >= $this->header->dimensions->width || $y < 0 || $y >= $this->header->dimensions->height
            || $component < 0 || $component >= $this->header->colorType->components()) {
            throw new \OutOfBoundsException('PNG sample coordinates exceed the raster.');
        }
        $bit = $y * $this->header->rowBytes($this->header->dimensions->width) * 8
            + ($x * $this->header->colorType->components() + $component) * $this->header->depth;
        $offset = intdiv($bit, 8);
        return match ($this->header->depth) {
            16 => ord($this->bytes[$offset]) * 256 + ord($this->bytes[$offset + 1]),
            8 => ord($this->bytes[$offset]),
            default => (ord($this->bytes[$offset]) >> (8 - $this->header->depth - $bit % 8)) & ((1 << $this->header->depth) - 1),
        };
    }
}
