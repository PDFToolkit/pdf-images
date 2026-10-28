<?php

declare(strict_types=1);

namespace PDF\Images;

use PDF\Document\Images\SampledImage;

/** Bounded static PNG conversion, including packed/16-bit samples, Adam7 and embedded ICC profiles. */
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
        $budget = new ImageImportBudget($this->limits);
        $header = null;
        $passes = null;
        $palette = null;
        $transparency = null;
        $profile = null;
        $compressed = '';
        $seenData = false;
        $dataClosed = false;
        $finished = false;
        foreach ($this->chunks($file->bytes) as $chunk) {
            if ($header === null && $chunk->type !== 'IHDR') {
                throw new \InvalidArgumentException('PNG IHDR must be the first chunk.');
            }
            if ($seenData && $chunk->type !== 'IDAT') {
                $dataClosed = true;
            }
            switch ($chunk->type) {
                case 'IHDR':
                    if ($header !== null) {
                        throw new \InvalidArgumentException('PNG must contain only one IHDR.');
                    }
                    $header = new PngHeader($chunk->data, $this->limits);
                    $passes = new PngPasses($header, $this->limits);
                    break;
                case 'PLTE':
                    if ($header === null || $palette !== null || $seenData || $transparency !== null
                        || $header->colorType === PngColorType::Gray || $header->colorType === PngColorType::GrayAlpha
                        || strlen($chunk->data) < 3 || strlen($chunk->data) > 768 || strlen($chunk->data) % 3 !== 0
                        || ($header->colorType === PngColorType::Indexed && intdiv(strlen($chunk->data), 3) > (1 << $header->depth))) {
                        throw new \InvalidArgumentException('Invalid PNG palette length or ordering.');
                    }
                    $palette = $chunk->data;
                    break;
                case 'tRNS':
                    if ($header === null || $transparency !== null || $seenData) {
                        throw new \InvalidArgumentException('Invalid PNG transparency chunk or ordering.');
                    }
                    $transparency = $chunk->data;
                    $this->validateTransparency($header, $transparency, $palette);
                    break;
                case 'iCCP':
                    if ($header === null || $profile !== null || $palette !== null || $seenData) {
                        throw new \InvalidArgumentException('PNG ICC profile must occur once before PLTE and IDAT.');
                    }
                    $profile = (new PngProfileReader($this->limits))->read($chunk->data, $header, $budget);
                    break;
                case 'IDAT':
                    if ($header === null || $dataClosed || ($header->colorType === PngColorType::Indexed && $palette === null)) {
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
                case 'cICP':
                case 'mDCV':
                case 'cLLI':
                    throw new \InvalidArgumentException('PNG animation and HDR colour metadata are not supported.');
                default:
                    if ($chunk->critical()) {
                        throw new \InvalidArgumentException('Unsupported critical PNG chunk.');
                    }
                    // Other ancillary metadata does not alter or execute image samples.
            }
            if ($finished) {
                break;
            }
        }
        if (!$finished || $header === null || $passes === null) {
            throw new \InvalidArgumentException('PNG image is incomplete.');
        }
        $filtered = (new ImageDataInflater())->decode($compressed, $passes->filteredBytes, $budget, $passes->filteredBytes);
        $raster = (new PngRasterDecoder($this->scanlines))->decode($filtered, $header, $passes, $budget);
        return (new PngSampleConverter($this->limits))->convert($raster, $palette, $transparency, $profile, $budget);
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

    private function validateTransparency(PngHeader $header, string $data, ?string $palette): void
    {
        $type = $header->colorType;
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
                $sample = ord($data[$offset]) * 256 + ord($data[$offset + 1]);
                if ($sample >= (1 << $header->depth)) {
                    throw new \InvalidArgumentException('PNG transparency sample exceeds the image sample depth.');
                }
            }
        } else {
            throw new \InvalidArgumentException('PNG alpha colour types cannot contain a transparency key.');
        }
    }
}
