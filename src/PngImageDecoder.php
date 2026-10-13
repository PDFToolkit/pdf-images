<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Document\Images\ImageSamples;
use PDF\Document\Images\SampledImage;
use PDF\Document\Images\SampleLayout;

/** Strict framing and bounded decoding of static, non-interlaced eight-bit PNG images. */
final readonly class PngImageDecoder
{
    public function __construct(
        private ImageImportLimits $limits = new ImageImportLimits(),
        private PngScanlineDecoder $scanlines = new PngScanlineDecoder(),
    ) {
    }

    public function decode(ImageFileBytes $file): SampledImage
    {
        $this->limits->validateFile($file);
        if (!str_starts_with($file->bytes, "\x89PNG\r\n\x1a\n")) {
            throw new \InvalidArgumentException('Invalid PNG signature.');
        }
        $dimensions = null;
        $colorType = null;
        $expectedBytes = 0;
        $palette = null;
        $transparency = null;
        $compressed = '';
        $seenData = false;
        $dataClosed = false;
        $finished = false;
        foreach ($this->chunks($file->bytes) as $chunk) {
            if ($dimensions === null && $chunk->type !== 'IHDR') {
                throw new \InvalidArgumentException('PNG IHDR must be the first chunk.');
            }
            if ($seenData && $chunk->type !== 'IDAT') {
                $dataClosed = true;
            }
            switch ($chunk->type) {
                case 'IHDR':
                    if ($dimensions !== null || strlen($chunk->data) !== 13) {
                        throw new \InvalidArgumentException('PNG must contain one thirteen-byte IHDR.');
                    }
                    $header = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $chunk->data);
                    if ($header === false) {
                        throw new \InvalidArgumentException('Invalid PNG header.');
                    }
                    $dimensions = new ImageDimensions($header['width'], $header['height']);
                    $colorType = PngColorType::tryFrom($header['type']);
                    if ($colorType === null || $header['depth'] !== 8 || $header['compression'] !== 0
                        || $header['filter'] !== 0 || $header['interlace'] !== 0) {
                        throw new \InvalidArgumentException('Only static, non-interlaced eight-bit PNG colour types 0, 2, 3, 4 and 6 are supported.');
                    }
                    $sampleBytes = $this->limits->validateDimensions($dimensions, $colorType->components());
                    if ($sampleBytes > $this->limits->maxDecodedBytes - $dimensions->height) {
                        throw new \InvalidArgumentException('Filtered PNG scanlines exceed the import byte limit.');
                    }
                    // Indexed pixels expand to RGB, plus opacity when present.
                    if ($colorType === PngColorType::Indexed) {
                        $this->limits->validateDimensions($dimensions, 4);
                    }
                    $expectedBytes = $sampleBytes + $dimensions->height;
                    break;
                case 'PLTE':
                    if ($palette !== null || $seenData || $transparency !== null || $colorType === PngColorType::Gray
                        || $colorType === PngColorType::GrayAlpha || strlen($chunk->data) < 3
                        || strlen($chunk->data) > 768 || strlen($chunk->data) % 3 !== 0) {
                        throw new \InvalidArgumentException('Invalid PNG palette length or ordering.');
                    }
                    $palette = $chunk->data;
                    break;
                case 'tRNS':
                    if ($transparency !== null || $seenData || $colorType === PngColorType::GrayAlpha || $colorType === PngColorType::RGBA) {
                        throw new \InvalidArgumentException('Invalid PNG transparency chunk or ordering.');
                    }
                    $transparency = $chunk->data;
                    $this->validateTransparency($colorType, $transparency, $palette);
                    break;
                case 'IDAT':
                    if ($dataClosed || ($colorType === PngColorType::Indexed && $palette === null)) {
                        throw new \InvalidArgumentException('PNG IDAT chunks must be consecutive and follow the required palette.');
                    }
                    $seenData = true;
                    $compressed .= $chunk->data;
                    break;
                case 'IEND':
                    if (!$seenData || $chunk->data !== '') {
                        throw new \InvalidArgumentException('Invalid PNG IEND or missing image data.');
                    }
                    $finished = true;
                    break;
                case 'acTL':
                case 'fcTL':
                case 'fdAT':
                case 'iCCP':
                case 'cICP':
                case 'mDCV':
                case 'cLLI':
                    throw new \InvalidArgumentException('PNG animation and embedded colour profiles are not supported.');
                default:
                    if ($chunk->critical()) {
                        throw new \InvalidArgumentException('Unsupported critical PNG chunk.');
                    }
                    // Ancillary metadata is not interpreted as executable content.
            }
            if ($finished) {
                break;
            }
        }
        if (!$finished || $dimensions === null || $colorType === null) {
            throw new \InvalidArgumentException('PNG image is incomplete.');
        }
        $filtered = $this->inflate($compressed, $expectedBytes);
        $samples = $this->scanlines->decode($filtered, $dimensions, $colorType);
        return $this->separateSamples($samples, $dimensions, $colorType, $palette, $transparency);
    }

    /** @return \Generator<int, PngChunk> */
    private function chunks(string $bytes): \Generator
    {
        $offset = 8;
        $length = strlen($bytes);
        $count = 0;
        while ($offset < $length) {
            if (++$count > $this->limits->maxChunks || $length - $offset < 12) {
                throw new \InvalidArgumentException('PNG chunk count exceeds the limit or chunk framing is truncated.');
            }
            $decoded = unpack('Nlength', $bytes, $offset);
            if ($decoded === false || $decoded['length'] > 0x7fffffff || $decoded['length'] > $length - $offset - 12) {
                throw new \InvalidArgumentException('PNG chunk length is invalid or exceeds the supplied bytes.');
            }
            $chunkLength = $decoded['length'];
            $type = substr($bytes, $offset + 4, 4);
            $chunk = new PngChunk($type, substr($bytes, $offset + 8, $chunkLength), substr($bytes, $offset + 8 + $chunkLength, 4));
            $offset += $chunkLength + 12;
            if ($type === 'IEND' && $offset !== $length) {
                throw new \InvalidArgumentException('PNG bytes follow the IEND chunk.');
            }
            yield $chunk;
        }
    }

    private function validateTransparency(?PngColorType $type, string $data, ?string $palette): void
    {
        $length = strlen($data);
        if ($type === PngColorType::Indexed) {
            if ($palette === null || $length < 1 || $length > intdiv(strlen($palette), 3)) {
                throw new \InvalidArgumentException('PNG indexed transparency must follow and fit the palette.');
            }
        } elseif ($type === PngColorType::Gray || $type === PngColorType::RGB) {
            $expected = $type === PngColorType::Gray ? 2 : 6;
            if ($length !== $expected) {
                throw new \InvalidArgumentException('Invalid PNG transparency sample count.');
            }
            for ($offset = 0; $offset < $length; $offset += 2) {
                if (ord($data[$offset]) !== 0) {
                    throw new \InvalidArgumentException('PNG transparency sample exceeds the eight-bit sample depth.');
                }
            }
        } else {
            throw new \InvalidArgumentException('Unsupported PNG transparency colour type.');
        }
    }

    private function inflate(string $compressed, int $expectedBytes): string
    {
        $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new \RuntimeException('Could not initialize PNG zlib decoding.');
        }
        $decoded = '';
        $length = strlen($compressed);
        // A small compressed input slice bounds each temporary expansion as well.
        for ($offset = 0; $offset < $length; $offset += 64) {
            $part = @inflate_add($context, substr($compressed, $offset, 64), ZLIB_SYNC_FLUSH);
            if ($part === false || strlen($part) > $expectedBytes - strlen($decoded)) {
                throw new \InvalidArgumentException('PNG zlib stream is invalid or exceeds the expected scanline length.');
            }
            $decoded .= $part;
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                if (inflate_get_read_len($context) !== $length) {
                    throw new \InvalidArgumentException('PNG contains trailing compressed data.');
                }
                break;
            }
        }
        if (inflate_get_status($context) !== ZLIB_STREAM_END || strlen($decoded) !== $expectedBytes) {
            throw new \InvalidArgumentException('PNG compressed scanlines are truncated or have an unexpected length.');
        }
        return $decoded;
    }

    private function separateSamples(string $bytes, ImageDimensions $dimensions, PngColorType $type, ?string $palette, ?string $transparency): SampledImage
    {
        $colors = '';
        $alpha = '';
        $components = $type->components();
        $hasAlpha = $transparency !== null || $type === PngColorType::RGBA || $type === PngColorType::GrayAlpha;
        $transparentColor = null;
        if ($transparency !== null && $type !== PngColorType::Indexed) {
            $transparentColor = '';
            for ($index = 1; $index < strlen($transparency); $index += 2) {
                $transparentColor .= $transparency[$index];
            }
        }
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += $components) {
            if ($type === PngColorType::Indexed) {
                $index = ord($bytes[$offset]);
                if ($palette === null || $index >= intdiv(strlen($palette), 3)) {
                    throw new \InvalidArgumentException('PNG pixel index exceeds its palette.');
                }
                $colors .= substr($palette, $index * 3, 3);
                if ($hasAlpha) {
                    $alpha .= $transparency !== null && $index < strlen($transparency) ? $transparency[$index] : "\xff";
                }
            } elseif ($type === PngColorType::RGBA || $type === PngColorType::GrayAlpha) {
                $colors .= substr($bytes, $offset, $components - 1);
                $alpha .= $bytes[$offset + $components - 1];
            } else {
                $pixel = substr($bytes, $offset, $components);
                $colors .= $pixel;
                if ($hasAlpha) {
                    $alpha .= $pixel === $transparentColor ? "\0" : "\xff";
                }
            }
        }
        return new SampledImage(
            new ImageSamples($dimensions, new SampleLayout($type->colorSpace()), $colors),
            $hasAlpha ? new ImageSamples($dimensions, new SampleLayout(DeviceColorSpace::Gray), $alpha) : null,
        );
    }
}
