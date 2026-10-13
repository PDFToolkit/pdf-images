<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\ImageDimensions;

/** Supported eight-bit Huffman DCT frame, baseline or progressive. */
final readonly class JpegFrame
{
    /** @var list<JpegComponent> */
    public array $components;

    public function __construct(public ImageDimensions $dimensions, public bool $progressive, JpegComponent ...$components)
    {
        if (!in_array(count($components), [1, 3, 4], true)) {
            throw new \InvalidArgumentException('JPEG frame must have one, three or four components.');
        }
        $identifiers = array_map(static fn (JpegComponent $component): int => $component->identifier, $components);
        if (count(array_unique($identifiers)) !== count($identifiers)) {
            throw new \InvalidArgumentException('Duplicate JPEG frame component identifier.');
        }
        $this->components = array_values($components);
    }
}
