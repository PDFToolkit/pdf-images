<?php

declare(strict_types=1);

namespace PDF\Images\Tests;

use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Images\PngImageDecoder;
use PDF\Serialization\SerializationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PngFormatsTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function formats(): iterable
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/Fixtures/png/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['cases'] as $name => $record) {
            yield $name => [$name, $record];
        }
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('formats')]
    public function testEveryLegalDepthAndAdam7SampleIsPreserved(string $name, array $expected): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/png/' . $name . '.png');
        self::assertSame($expected['sha256'], hash('sha256', $bytes));
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($bytes));
        self::assertSame($expected['width'], $image->samples->dimensions->width);
        self::assertSame($expected['height'], $image->samples->dimensions->height);
        self::assertSame($expected['colorDepth'], $image->samples->layout->bitsPerComponent);
        self::assertSame($expected['components'], $image->samples->layout->components());
        self::assertSame(base64_decode($expected['samples'], true), $image->samples->bytes);
        self::assertSame($expected['alpha'] === null ? null : base64_decode($expected['alpha'], true), $image->alpha?->bytes);
        self::assertSame($expected['alphaDepth'], $image->alpha?->layout->bitsPerComponent);
        if ($expected['profileSha256'] !== null) {
            $space = $image->samples->layout->colorSpace;
            self::assertInstanceOf(ICCBased::class, $space);
            self::assertSame($expected['profileSha256'], hash('sha256', $space->profile->bytes(new SerializationContext())));
            self::assertSame($expected['components'], $space->profile->components->integer());
        }
    }

    public function testTransparencyKeyComparesAllSixteenBits(): void
    {
        $png = $this->header(2, 1, 0, 16) . $this->chunk('tRNS', "\x12\x34")
            . $this->chunk('IDAT', gzcompress("\0\x12\x34\x12\x35")) . $this->chunk('IEND', '');
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($png));
        self::assertSame("\x12\x34\x12\x35", $image->samples->bytes);
        self::assertSame("\0\xff", $image->alpha?->bytes);
    }

    public function testEmptyAdam7PassesHaveNoFilterBytes(): void
    {
        foreach ([1, 2, 4, 8, 16] as $depth) {
            $sample = $depth === 16 ? "\x12\x34" : "\x80";
            $png = $this->header(1, 1, 0, $depth, 1)
                . $this->chunk('IDAT', gzcompress("\0" . $sample)) . $this->chunk('IEND', '');
            $image = (new PngImageDecoder())->decode(new ImageFileBytes($png));
            self::assertSame($depth === 16 ? 0x1234 : 1 << ($depth - 1), $image->samples->sample(0, 0, 0));
        }
    }

    public function testInvalidPaletteSizeAndHighTransparencyBitsAreRejected(): void
    {
        $cases = [
            $this->header(1, 1, 3, 1) . $this->chunk('PLTE', str_repeat("\0", 9)),
            $this->header(1, 1, 0, 2) . $this->chunk('tRNS', "\0\x04"),
        ];
        foreach ($cases as $prefix) {
            $this->reject($prefix . $this->chunk('IDAT', gzcompress("\0\0")) . $this->chunk('IEND', ''));
        }
    }

    public function testIccFramingOrderingAndColourCompatibilityAreValidated(): void
    {
        $profile = file_get_contents(__DIR__ . '/Fixtures/png/rgb.icc');
        $header = $this->header(1, 1, 2, 8);
        $data = $this->chunk('IDAT', gzcompress("\0\x11\x22\x33"));
        $end = $this->chunk('IEND', '');
        $icc = $this->chunk('iCCP', "profile\0\0" . gzcompress($profile));
        foreach ([
            $header . $icc . $icc . $data . $end,
            $header . $data . $icc . $end,
            $header . $this->chunk('PLTE', "\0\0\0") . $icc . $data . $end,
            $this->header(1, 1, 0, 8) . $icc . $this->chunk('IDAT', gzcompress("\0\0")) . $end,
        ] as $png) {
            $this->reject($png);
        }
        $badSignature = substr_replace($profile, 'nope', 36, 4);
        $badOffset = substr_replace($profile, pack('N', strlen($profile) + 4), 136, 4);
        $badSize = substr_replace($profile, pack('N', strlen($profile) + 1), 0, 4);
        foreach ([$badSignature, $badOffset, $badSize] as $badProfile) {
            $this->reject($header . $this->chunk('iCCP', "profile\0\0" . gzcompress($badProfile)) . $data . $end);
        }
        foreach ([" name\0\0", "two  spaces\0\0", "name\0\1", "bad\x7f\0\0"] as $prefix) {
            $this->reject($header . $this->chunk('iCCP', $prefix . gzcompress($profile)) . $data . $end);
        }
    }

    public function testProfileInflationAndCumulativeBudgetsAreEnforced(): void
    {
        $file = new ImageFileBytes(file_get_contents(__DIR__ . '/Fixtures/png/rgb-icc.png'));
        foreach ([
            new ImageImportLimits(maxProfileBytes: 128),
            new ImageImportLimits(maxProfileTags: 1),
            new ImageImportLimits(maxCumulativeDecodedBytes: 1200),
            new ImageImportLimits(maxDecodedBytes: 486),
        ] as $limits) {
            try {
                (new PngImageDecoder($limits))->decode($file);
                self::fail('Expected the image/profile budget to reject before returning samples.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        // Counters belong to each decode call, not the long-lived decoder.
        $decoder = new PngImageDecoder();
        self::assertSame($decoder->decode($file)->samples->bytes, $decoder->decode($file)->samples->bytes);
    }

    private function reject(string $bytes): void
    {
        try {
            (new PngImageDecoder())->decode(new ImageFileBytes($bytes));
            self::fail('Expected malformed PNG/profile rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function header(int $width, int $height, int $type, int $depth, int $interlace = 0): string
    {
        return "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $type, 0, 0, $interlace));
    }

    private function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)) . $type . $bytes . hash('crc32b', $type . $bytes, true);
    }
}
