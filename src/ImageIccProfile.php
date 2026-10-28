<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Graphics\ColourSpaces\ICCProfile;
use PDF\Syntax\Object\IntegerType;

/** Checks ICC framing and image colour compatibility; profile transforms remain viewer responsibilities. */
final readonly class ImageIccProfile
{
    public function __construct(public string $bytes, public int $components, int $maxTags = 4096)
    {
        $space = match ($components) {
            1 => 'GRAY',
            3 => 'RGB ',
            4 => 'CMYK',
            default => throw new \InvalidArgumentException('Unsupported ICC image component count.'),
        };
        $length = strlen($bytes);
        if ($length < 132 || $this->integer(0) !== $length || substr($bytes, 36, 4) !== 'acsp'
            || substr($bytes, 16, 4) !== $space || !in_array(substr($bytes, 20, 4), ['XYZ ', 'Lab '], true)
            || !in_array(ord($bytes[8]), [2, 4], true)
            || !in_array(substr($bytes, 12, 4), ['scnr', 'mntr', 'prtr', 'spac'], true)) {
            throw new \InvalidArgumentException('ICC profile framing or colour space is incompatible with the image.');
        }
        $tags = $this->integer(128);
        if ($maxTags < 1 || $tags < 1 || $tags > $maxTags || $tags > intdiv($length - 132, 12)) {
            throw new \InvalidArgumentException('ICC profile tag table exceeds its declared bytes.');
        }
        $tableEnd = 132 + $tags * 12;
        $seen = [];
        for ($index = 0; $index < $tags; ++$index) {
            $entry = 132 + $index * 12;
            $signature = substr($bytes, $entry, 4);
            $offset = $this->integer($entry + 4);
            $size = $this->integer($entry + 8);
            if (isset($seen[$signature]) || $offset % 4 !== 0 || $offset < $tableEnd
                || $size < 8 || $offset > $length || $size > $length - $offset) {
                throw new \InvalidArgumentException('ICC profile tag offset, size or identity is invalid.');
            }
            $seen[$signature] = true;
        }
    }

    public function colorSpace(): ICCBased
    {
        return new ICCBased(new ICCProfile($this->bytes, new IntegerType($this->components)));
    }

    private function integer(int $offset): int
    {
        return ord($this->bytes[$offset]) * 16_777_216 + ord($this->bytes[$offset + 1]) * 65_536
            + ord($this->bytes[$offset + 2]) * 256 + ord($this->bytes[$offset + 3]);
    }
}
