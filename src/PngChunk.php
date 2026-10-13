<?php

declare(strict_types=1);

namespace PDF\Images;

/** A framed PNG chunk with its checksum verified before interpretation. */
final readonly class PngChunk
{
    public function __construct(public string $type, public string $data, string $crc)
    {
        if (preg_match('/^[A-Za-z]{4}$/D', $type) !== 1 || !ctype_upper($type[2])) {
            throw new \InvalidArgumentException('Invalid PNG chunk type.');
        }
        if (!hash_equals(hash('crc32b', $type . $data, true), $crc)) {
            throw new \InvalidArgumentException('PNG chunk checksum does not match.');
        }
    }

    public function critical(): bool
    {
        return ctype_upper($this->type[0]);
    }
}
