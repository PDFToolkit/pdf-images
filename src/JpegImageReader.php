<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\DCTImage;
use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\SampleLayout;

/** Inspects JPEG framing and supported colour semantics without decoding DCT coefficients. */
final readonly class JpegImageReader
{
    public function __construct(private ImageImportLimits $limits = new ImageImportLimits())
    {
    }

    public function read(ImageFileBytes $file): DCTImage
    {
        $this->limits->validateFile($file);
        $bytes = $file->bytes;
        $length = strlen($bytes);
        if ($length < 4 || substr($bytes, 0, 2) !== "\xff\xd8") {
            throw new \InvalidArgumentException('Invalid JPEG start-of-image marker.');
        }
        $profileParts = new JpegProfileParts($this->limits, new ImageImportBudget($this->limits));
        $frame = null;
        $adobeTransform = null;
        $jfif = false;
        $scan = false;
        $finished = false;
        $quantizationTables = [];
        $huffmanTables = [];
        $offset = 2;
        $markers = 0;
        while ($offset < $length) {
            if (++$markers > $this->limits->maxChunks || ord($bytes[$offset++]) !== 255) {
                throw new \InvalidArgumentException('Invalid JPEG marker framing or marker count limit exceeded.');
            }
            while ($offset < $length && ord($bytes[$offset]) === 255) {
                ++$offset;
            }
            if ($offset >= $length) {
                throw new \InvalidArgumentException('Truncated JPEG marker.');
            }
            $marker = ord($bytes[$offset++]);
            if ($marker === 0xd9) {
                if (!$scan || $frame === null || $offset !== $length) {
                    throw new \InvalidArgumentException('Invalid JPEG end-of-image marker or trailing bytes.');
                }
                $finished = true;
                break;
            }
            if ($marker === 0 || $marker === 0xd8 || $marker === 0x01 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                throw new \InvalidArgumentException('Unexpected standalone JPEG marker.');
            }
            if ($length - $offset < 2) {
                throw new \InvalidArgumentException('Truncated JPEG segment length.');
            }
            $segmentLength = ord($bytes[$offset]) * 256 + ord($bytes[$offset + 1]);
            if ($segmentLength < 2 || $segmentLength > $length - $offset) {
                throw new \InvalidArgumentException('JPEG segment length exceeds the supplied bytes.');
            }
            $payload = substr($bytes, $offset + 2, $segmentLength - 2);
            $offset += $segmentLength;
            if ($marker === 0xc0 || $marker === 0xc2) {
                if ($frame !== null || $scan) {
                    throw new \InvalidArgumentException('JPEG contains multiple or misplaced frame headers.');
                }
                $frame = $this->frame($payload, $marker === 0xc2);
                $this->limits->validateDimensions($frame->dimensions, count($frame->components));
            } elseif ($marker >= 0xc0 && $marker <= 0xcf && !in_array($marker, [0xc4], true)) {
                throw new \InvalidArgumentException('Only eight-bit baseline and progressive Huffman DCT JPEG frames are supported.');
            } elseif ($marker === 0xdb) {
                $this->quantizationTables($payload, $quantizationTables);
            } elseif ($marker === 0xc4) {
                $this->huffmanTables($payload, $huffmanTables);
            } elseif ($marker === 0xdd) {
                if (strlen($payload) !== 2) {
                    throw new \InvalidArgumentException('JPEG restart interval must contain two bytes.');
                }
            } elseif ($marker === 0xda) {
                if ($frame === null) {
                    throw new \InvalidArgumentException('JPEG scan precedes its frame header.');
                }
                $this->scanHeader($payload, $frame, $quantizationTables, $huffmanTables);
                $scan = true;
                $offset = $this->scanEnd($bytes, $offset);
            } elseif ($marker === 0xee && str_starts_with($payload, 'Adobe')) {
                if ($scan || $adobeTransform !== null || strlen($payload) !== 12) {
                    throw new \InvalidArgumentException('Invalid or duplicate Adobe JPEG marker.');
                }
                $adobeTransform = ord($payload[11]);
            } elseif ($marker === 0xe0 && str_starts_with($payload, "JFIF\0")) {
                if ($scan || $jfif || strlen($payload) < 14
                    || strlen($payload) !== 14 + ord($payload[12]) * ord($payload[13]) * 3) {
                    throw new \InvalidArgumentException('Invalid or duplicate JPEG JFIF marker.');
                }
                $jfif = true;
            } elseif ($marker === 0xe2 && str_starts_with($payload, "ICC_PROFILE\0")) {
                $profileParts->append($payload);
            } elseif (!(($marker >= 0xe0 && $marker <= 0xef) || $marker === 0xfe)) {
                throw new \InvalidArgumentException('Unsupported JPEG segment marker.');
            }
        }
        if (!$finished || $frame === null) {
            throw new \InvalidArgumentException('JPEG image is incomplete.');
        }
        return $this->image($file, $frame, $adobeTransform, $jfif, $profileParts->profile(count($frame->components)));
    }

    private function frame(string $payload, bool $progressive): JpegFrame
    {
        if (strlen($payload) < 6 || ord($payload[0]) !== 8 || strlen($payload) !== 6 + ord($payload[5]) * 3) {
            throw new \InvalidArgumentException('Invalid eight-bit JPEG frame header.');
        }
        $components = [];
        for ($offset = 6; $offset < strlen($payload); $offset += 3) {
            $sampling = ord($payload[$offset + 1]);
            $components[] = new JpegComponent(ord($payload[$offset]), $sampling >> 4, $sampling & 15, ord($payload[$offset + 2]));
        }
        return new JpegFrame(new ImageDimensions(
            ord($payload[3]) * 256 + ord($payload[4]),
            ord($payload[1]) * 256 + ord($payload[2]),
        ), $progressive, ...$components);
    }

    /** @param array<int, true> $tables */
    private function quantizationTables(string $payload, array &$tables): void
    {
        if ($payload === '') {
            throw new \InvalidArgumentException('Empty JPEG quantization segment.');
        }
        for ($offset = 0; $offset < strlen($payload);) {
            $specification = ord($payload[$offset++]);
            $precision = $specification >> 4;
            $identifier = $specification & 15;
            $tableLength = $precision === 0 ? 64 : 128;
            if ($precision > 1 || $identifier > 3 || strlen($payload) - $offset < $tableLength) {
                throw new \InvalidArgumentException('Invalid JPEG quantization table.');
            }
            $tables[$identifier] = true;
            $offset += $tableLength;
        }
    }

    /** @param array<int, true> $tables */
    private function huffmanTables(string $payload, array &$tables): void
    {
        if ($payload === '') {
            throw new \InvalidArgumentException('Empty JPEG Huffman segment.');
        }
        for ($offset = 0; $offset < strlen($payload);) {
            if (strlen($payload) - $offset < 17) {
                throw new \InvalidArgumentException('Truncated JPEG Huffman table.');
            }
            $specification = ord($payload[$offset++]);
            if (($specification >> 4) > 1 || ($specification & 15) > 3) {
                throw new \InvalidArgumentException('Invalid JPEG Huffman table identifier.');
            }
            $symbols = 0;
            $available = 1;
            for ($index = 0; $index < 16; ++$index) {
                $count = ord($payload[$offset++]);
                $symbols += $count;
                $available = $available * 2 - $count;
                if ($available < 0) {
                    throw new \InvalidArgumentException('Oversubscribed JPEG Huffman table.');
                }
            }
            if ($symbols < 1 || $symbols > 256 || $symbols > strlen($payload) - $offset) {
                throw new \InvalidArgumentException('Invalid JPEG Huffman symbol count.');
            }
            $tables[$specification] = true;
            $offset += $symbols;
        }
    }

    /**
     * @param array<int, true> $quantizationTables
     * @param array<int, true> $huffmanTables
     */
    private function scanHeader(string $payload, JpegFrame $frame, array $quantizationTables, array $huffmanTables): void
    {
        if ($payload === '' || ord($payload[0]) < 1 || ord($payload[0]) > count($frame->components)
            || strlen($payload) !== 4 + ord($payload[0]) * 2) {
            throw new \InvalidArgumentException('Invalid JPEG scan header.');
        }
        $start = ord($payload[strlen($payload) - 3]);
        $end = ord($payload[strlen($payload) - 2]);
        $approximation = ord($payload[strlen($payload) - 1]);
        if ($start > $end || $end > 63 || ($approximation >> 4) > 13 || ($approximation & 15) > 13
            || (!$frame->progressive && ($start !== 0 || $end !== 63 || $approximation !== 0))
            || ($frame->progressive && (($start === 0 && $end !== 0) || ($start > 0 && ord($payload[0]) !== 1)))) {
            throw new \InvalidArgumentException('Unsupported JPEG scan spectral selection.');
        }
        $seen = [];
        for ($offset = 1; $offset < strlen($payload) - 3; $offset += 2) {
            $identifier = ord($payload[$offset]);
            $component = null;
            foreach ($frame->components as $candidate) {
                if ($candidate->identifier === $identifier) {
                    $component = $candidate;
                }
            }
            if ($component === null || isset($seen[$identifier]) || !isset($quantizationTables[$component->quantizationTable])) {
                throw new \InvalidArgumentException('JPEG scan references a missing component or quantization table.');
            }
            $seen[$identifier] = true;
            $selectors = ord($payload[$offset + 1]);
            if (($selectors >> 4) > 3 || ($selectors & 15) > 3
                || ($start === 0 && ($approximation >> 4) === 0 && !isset($huffmanTables[$selectors >> 4]))
                || ($end > 0 && !isset($huffmanTables[0x10 | ($selectors & 15)]))) {
                throw new \InvalidArgumentException('JPEG scan references an invalid or missing Huffman table.');
            }
        }
    }

    private function scanEnd(string $bytes, int $offset): int
    {
        $start = $offset;
        $length = strlen($bytes);
        while ($offset < $length) {
            if (ord($bytes[$offset]) !== 255) {
                ++$offset;
                continue;
            }
            $markerOffset = $offset++;
            while ($offset < $length && ord($bytes[$offset]) === 255) {
                ++$offset;
            }
            if ($offset >= $length) {
                break;
            }
            $marker = ord($bytes[$offset]);
            if ($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd7)) {
                ++$offset;
                continue;
            }
            if ($markerOffset === $start) {
                throw new \InvalidArgumentException('JPEG entropy-coded scan is empty.');
            }
            return $markerOffset;
        }
        throw new \InvalidArgumentException('Truncated JPEG entropy-coded scan.');
    }

    private function image(ImageFileBytes $file, JpegFrame $frame, ?int $adobeTransform, bool $jfif, ?ImageIccProfile $profile): DCTImage
    {
        $count = count($frame->components);
        if ($count === 1) {
            if ($adobeTransform !== null && $adobeTransform !== 0) {
                throw new \InvalidArgumentException('Unsupported grayscale Adobe JPEG colour transform.');
            }
            return new DCTImage($file->bytes, $frame->dimensions, new SampleLayout($profile?->colorSpace() ?? DeviceColorSpace::Gray), 0);
        }
        if ($count === 4) {
            if (!in_array($adobeTransform, [0, 2], true) || $jfif) {
                throw new \InvalidArgumentException('Four-component JPEG requires an unambiguous Adobe CMYK or YCCK marker.');
            }
            return new DCTImage($file->bytes, $frame->dimensions, new SampleLayout($profile?->colorSpace() ?? DeviceColorSpace::CMYK), $adobeTransform === 2 ? 1 : 0, true);
        }
        $identifiers = array_map(static fn (JpegComponent $component): int => $component->identifier, $frame->components);
        if ($adobeTransform !== null && !in_array($adobeTransform, [0, 1], true)) {
            throw new \InvalidArgumentException('Unsupported Adobe JPEG RGB colour transform.');
        }
        $transform = $adobeTransform ?? match (true) {
            $jfif, $identifiers === [1, 2, 3] => 1,
            $identifiers === [82, 71, 66] => 0,
            default => throw new \InvalidArgumentException('JPEG component colour semantics are ambiguous.'),
        };
        if ($jfif && $transform !== 1) {
            throw new \InvalidArgumentException('Conflicting JFIF and Adobe JPEG colour transforms.');
        }
        return new DCTImage($file->bytes, $frame->dimensions, new SampleLayout($profile?->colorSpace() ?? DeviceColorSpace::RGB), $transform);
    }
}
