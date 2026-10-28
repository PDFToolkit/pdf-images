<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Graphics\ColourSpaces\MatrixABC;
use PDF\Graphics\ColourSpaces\WhitePoint;
use PDF\Syntax\Object\NumericType;

/** Explicit CIE xy primaries and white point; no device or global colour state. */
final readonly class PngChromaticities
{
    public function __construct(
        public float $whiteX = 0.3127,
        public float $whiteY = 0.3290,
        public float $redX = 0.6400,
        public float $redY = 0.3300,
        public float $greenX = 0.3000,
        public float $greenY = 0.6000,
        public float $blueX = 0.1500,
        public float $blueY = 0.0600,
    ) {
        foreach ([[$whiteX, $whiteY], [$redX, $redY], [$greenX, $greenY], [$blueX, $blueY]] as [$x, $y]) {
            if (!is_finite($x) || !is_finite($y) || $x < 0 || $y < 0 || $x + $y > 1) {
                throw new \InvalidArgumentException('PNG chromaticities require meaningful CIE xy coordinates.');
            }
        }
        if ($whiteX <= 0 || $whiteY <= 0 || $whiteX + $whiteY >= 1) {
            throw new \InvalidArgumentException('PNG white point must have positive X and Z components.');
        }
    }

    public function whitePoint(): WhitePoint
    {
        return new WhitePoint(new NumericType($this->whiteX / $this->whiteY), new NumericType(1), new NumericType((1 - $this->whiteX - $this->whiteY) / $this->whiteY));
    }

    public function matrix(): MatrixABC
    {
        $columns = [
            [$this->redX, $this->redY, 1 - $this->redX - $this->redY],
            [$this->greenX, $this->greenY, 1 - $this->greenX - $this->greenY],
            [$this->blueX, $this->blueY, 1 - $this->blueX - $this->blueY],
        ];
        $white = [$this->whiteX / $this->whiteY, 1.0, (1 - $this->whiteX - $this->whiteY) / $this->whiteY];
        $determinant = $this->determinant($columns);
        if (abs($determinant) < 1.0e-12) {
            throw new \InvalidArgumentException('PNG chromaticities have singular RGB primaries.');
        }
        $values = [];
        foreach ($columns as $index => $column) {
            $replaced = $columns;
            $replaced[$index] = $white;
            $scale = $this->determinant($replaced) / $determinant;
            if (!is_finite($scale) || $scale <= 0) {
                throw new \InvalidArgumentException('PNG white point lies outside its RGB primary triangle.');
            }
            foreach ($column as $coordinate) {
                $values[] = new NumericType($coordinate * $scale);
            }
        }
        return new MatrixABC(...$values);
    }

    /** @param array<array<float>> $columns */
    private function determinant(array $columns): float
    {
        [$a, $b, $c] = $columns;
        return $a[0] * ($b[1] * $c[2] - $b[2] * $c[1]) - $b[0] * ($a[1] * $c[2] - $a[2] * $c[1]) + $c[0] * ($a[1] * $b[2] - $a[2] * $b[1]);
    }
}
