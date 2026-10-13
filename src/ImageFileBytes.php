<?php

declare(strict_types=1);

namespace PDF\Images;

/** Encoded bytes supplied by the application; this object never opens a path or URL. */
final readonly class ImageFileBytes
{
    public function __construct(public string $bytes)
    {
    }
}
