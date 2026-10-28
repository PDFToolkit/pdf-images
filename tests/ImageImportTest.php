<?php

declare(strict_types=1);

namespace PDF\Images\Tests;

use PDF\Document\Images\DeviceColorSpace;
use PDF\Document\Images\ImageDimensions;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Document\Images\ImageXObjectFactory;
use PDF\Images\JpegImageReader;
use PDF\Images\PngColorType;
use PDF\Images\PngImageDecoder;
use PDF\Images\PngScanlineDecoder;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\StreamObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageImportTest extends TestCase
{
    public function testPngFixturesReconstructOriginalColorAndOpacitySamples(): void
    {
        $reader = new PngImageDecoder();
        $rgb = $reader->decode($this->fixture('rgb.png'));
        $rgba = $reader->decode($this->fixture('rgba.png'));
        $palette = $reader->decode($this->fixture('palette.png'));
        $gray = $reader->decode($this->fixture('gray.png'));
        $grayAlpha = $reader->decode($this->fixture('gray-alpha.png'));
        $colors = [[244, 93, 80], [255, 199, 93], [21, 167, 150], [61, 128, 220]];
        $expectedRgb = $expectedAlpha = $expectedPaletteAlpha = $expectedGray = $expectedGrayAlpha = '';
        for ($y = 0; $y < 36; ++$y) {
            for ($x = 0; $x < 48; ++$x) {
                $index = (intdiv($x, 12) + intdiv($y, 9)) % 4;
                $expectedRgb .= implode('', array_map(chr(...), $colors[$index]));
                $expectedAlpha .= chr((int) round(255 * $x / 47));
                $expectedPaletteAlpha .= chr($index * 85);
                $expectedGray .= chr((int) round(255 * ($x + $y) / 82, 0, PHP_ROUND_HALF_EVEN));
                $expectedGrayAlpha .= chr((int) round(255 * $y / 35, 0, PHP_ROUND_HALF_EVEN));
            }
        }
        self::assertSame($expectedRgb, $rgb->samples->bytes);
        self::assertSame($expectedRgb, $rgba->samples->bytes);
        self::assertSame($expectedRgb, $palette->samples->bytes);
        self::assertSame($expectedAlpha, $rgba->alpha?->bytes);
        self::assertSame($expectedPaletteAlpha, $palette->alpha?->bytes);
        self::assertSame($expectedGray, $gray->samples->bytes);
        self::assertSame($expectedGray, $grayAlpha->samples->bytes);
        self::assertSame($expectedGrayAlpha, $grayAlpha->alpha?->bytes);
        self::assertNull($rgb->alpha);
        self::assertSame(DeviceColorSpace::Gray, $gray->samples->layout->colorSpace);
    }

    public function testAllFivePngScanlineFiltersAndBoundaryPaethTie(): void
    {
        // Two RGB pixels per row; expected bytes are independently calculated.
        $decoder = new PngScanlineDecoder();
        $rows = "\x00\x0a\x14\x1e\x28\x32\x3c"
            . "\x01\x0b\x15\x1f\x1e\x1e\x1e"
            . "\x02\x01\x01\x01\x01\x01\x01"
            . "\x03\x08\x0d\x12\x10\x10\x10"
            . "\x04\x01\x01\x01\x01\x01\x01";
        self::assertSame(
            "\x0a\x14\x1e\x28\x32\x3c"
            . "\x0b\x15\x1f\x29\x33\x3d"
            . "\x0c\x16\x20\x2a\x34\x3e"
            . "\x0e\x18\x22\x2c\x36\x40"
            . "\x0f\x19\x23\x2d\x37\x41",
            $decoder->decode($rows, new ImageDimensions(2, 5), PngColorType::RGB),
        );
    }

    public function testConsecutiveIdatChunksAndIgnoredTextMetadata(): void
    {
        $compressed = gzcompress("\0\x11\x22\x33");
        self::assertIsString($compressed);
        $bytes = $this->pngHeader(1, 1, 2)
            . $this->chunk('tEXt', "Comment\0plain metadata")
            . $this->chunk('IDAT', substr($compressed, 0, 3))
            . $this->chunk('IDAT', substr($compressed, 3))
            . $this->chunk('IEND', '');
        self::assertSame("\x11\x22\x33", (new PngImageDecoder())->decode(new ImageFileBytes($bytes))->samples->bytes);
    }

    public function testTransparencyColorKeysBecomeExplicitAlphaSamples(): void
    {
        $gray = $this->pngHeader(2, 1, 0) . $this->chunk('tRNS', "\0\x33")
            . $this->chunk('IDAT', gzcompress("\0\x33\x44")) . $this->chunk('IEND', '');
        $rgb = $this->pngHeader(2, 1, 2) . $this->chunk('tRNS', "\0\x11\0\x22\0\x33")
            . $this->chunk('IDAT', gzcompress("\0\x11\x22\x33\x44\x55\x66")) . $this->chunk('IEND', '');
        self::assertSame("\0\xff", (new PngImageDecoder())->decode(new ImageFileBytes($gray))->alpha?->bytes);
        self::assertSame("\0\xff", (new PngImageDecoder())->decode(new ImageFileBytes($rgb))->alpha?->bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPngCases(): iterable
    {
        $test = new self('testRejectsMalformedAndUnsupportedPng');
        $valid = $test->pngHeader(1, 1, 2);
        $data = $test->chunk('IDAT', gzcompress("\0\x11\x22\x33"));
        $end = $test->chunk('IEND', '');
        yield 'signature' => ['not PNG'];
        yield 'crc' => [substr($valid, 0, -1) . "\0" . $data . $end];
        yield 'duplicate header' => [$valid . substr($valid, 8) . $data . $end];
        yield 'missing end' => [$valid . $data];
        yield 'trailing bytes' => [$valid . $data . $end . 'trailing'];
        yield 'unknown critical' => [$valid . $test->chunk('ABCD', '') . $data . $end];
        yield 'reserved chunk bit' => [$valid . $test->chunk('abcd', '') . $data . $end];
        yield 'animation' => [$valid . $test->chunk('acTL', pack('NN', 2, 0)) . $data . $end];
        yield 'icc' => [$valid . $test->chunk('iCCP', "name\0\0compressed") . $data . $end];
        yield 'reserved interlace' => [$test->pngHeader(1, 1, 2, 8, 2) . $data . $end];
        yield 'invalid colour depth' => [$test->pngHeader(1, 1, 2, 4) . $data . $end];
        yield 'non consecutive IDAT' => [$valid . $data . $test->chunk('tEXt', "k\0v") . $data . $end];
        yield 'trailing zlib' => [$valid . $test->chunk('IDAT', gzcompress("\0\x11\x22\x33") . 'garbage') . $end];
        yield 'short scanlines' => [$valid . $test->chunk('IDAT', gzcompress("\0\x11\x22")) . $end];
        yield 'extra scanlines' => [$valid . $test->chunk('IDAT', gzcompress("\0\x11\x22\x33\x44")) . $end];
        yield 'invalid filter' => [$valid . $test->chunk('IDAT', gzcompress("\x05\x11\x22\x33")) . $end];
        yield 'oversized declared chunk' => [$valid . pack('N', 0x7fffffff) . 'IDAT' . "\0\0\0\0"];
        yield 'palette index' => [$test->pngHeader(1, 1, 3) . $test->chunk('PLTE', "\xff\0\0")
            . $test->chunk('IDAT', gzcompress("\0\x01")) . $end];
        yield 'palette transparency overflow' => [$test->pngHeader(1, 1, 3) . $test->chunk('PLTE', "\xff\0\0")
            . $test->chunk('tRNS', "\0\xff") . $data . $end];
        yield 'alpha and transparency key' => [$test->pngHeader(1, 1, 6) . $test->chunk('tRNS', "\0\x01") . $data . $end];
    }

    #[DataProvider('invalidPngCases')]
    public function testRejectsMalformedAndUnsupportedPng(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PngImageDecoder())->decode(new ImageFileBytes($bytes));
    }

    public function testDecompressionBombIsRejectedBeforeBuildingSamples(): void
    {
        $bytes = $this->pngHeader(1, 1, 0) . $this->chunk('IDAT', gzcompress(str_repeat("\0", 1_000_000))) . $this->chunk('IEND', '');
        $this->expectException(\InvalidArgumentException::class);
        (new PngImageDecoder())->decode(new ImageFileBytes($bytes));
    }

    public function testImportLimitsAreAppliedToPixelsAndExpandedPalette(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PngImageDecoder(new ImageImportLimits(maxDecodedBytes: 48 * 36 * 3)))
            ->decode($this->fixture('palette.png'));
    }

    public function testEncodedSizeAndChunkCountLimits(): void
    {
        foreach ([new ImageImportLimits(maxEncodedBytes: 10), new ImageImportLimits(maxChunks: 1)] as $limits) {
            try {
                (new PngImageDecoder($limits))->decode($this->fixture('rgb.png'));
                self::fail('Expected image import limit rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testJpegPreservesBaselineAndProgressiveBytesAndMetadata(): void
    {
        $reader = new JpegImageReader();
        foreach (['rgb.jpg', 'progressive.jpg', 'gray.jpg', 'cmyk.jpg'] as $filename) {
            $file = $this->fixture($filename);
            $image = $reader->read($file);
            self::assertSame(48, $image->dimensions->width);
            self::assertSame(36, $image->dimensions->height);
            $stream = (new ImageXObjectFactory())->create($image);
            self::assertSame($file->bytes, $stream->bytes(new SerializationContext()));
            self::assertSame('DCTDecode', $stream->filters[0]->name()->value);
            if ($filename === 'cmyk.jpg') {
                self::assertTrue($image->invertedComponents);
                self::assertSame(DeviceColorSpace::CMYK, $image->layout->colorSpace);
                self::assertStringContainsString('/Decode [1 0 1 0 1 0 1 0]', $stream->toPdf(new SerializationContext()));
            } else {
                self::assertFalse($image->invertedComponents);
                self::assertSame($filename === 'gray.jpg' ? DeviceColorSpace::Gray : DeviceColorSpace::RGB, $image->layout->colorSpace);
            }
        }
    }

    public function testJpegRejectsTruncatedFramingAndUnsupportedCmykTransform(): void
    {
        $rgb = $this->fixture('rgb.jpg')->bytes;
        $cmyk = $this->fixture('cmyk.jpg')->bytes;
        $adobe = strpos($cmyk, 'Adobe');
        self::assertIsInt($adobe);
        $cmyk[$adobe + 11] = "\x03";
        $cases = [substr($rgb, 0, -2), $rgb . 'trailing', "\xff\xd8\xff\xe0\xff\xffx", $cmyk];
        foreach ($cases as $bytes) {
            try {
                (new JpegImageReader())->read(new ImageFileBytes($bytes));
                self::fail('Expected JPEG rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testJpegRejectsUnsupportedFramesMissingTablesAndDimensionLimits(): void
    {
        $rgb = $this->fixture('rgb.jpg')->bytes;
        $frame = strpos($rgb, "\xff\xc0");
        $table = strpos($rgb, "\xff\xdb");
        self::assertIsInt($frame);
        self::assertIsInt($table);
        $unsupportedFrame = $rgb;
        $unsupportedFrame[$frame + 1] = "\xc3";
        $unsupportedDepth = $rgb;
        $unsupportedDepth[$frame + 4] = "\x0c";
        $tableLength = ord($rgb[$table + 2]) * 256 + ord($rgb[$table + 3]);
        $missingTable = substr($rgb, 0, $table) . substr($rgb, $table + $tableLength + 2);
        foreach ([$unsupportedFrame, $unsupportedDepth, $missingTable] as $bytes) {
            try {
                (new JpegImageReader())->read(new ImageFileBytes($bytes));
                self::fail('Expected unsupported or incomplete JPEG framing rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        foreach ([new ImageImportLimits(maxPixels: 100), new ImageImportLimits(maxDimension: 32), new ImageImportLimits(maxEncodedBytes: 16)] as $limits) {
            try {
                (new JpegImageReader($limits))->read(new ImageFileBytes($rgb));
                self::fail('Expected JPEG size limit rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testSoftMaskIsAnIndirectGrayscaleImageAndRemainsInGraph(): void
    {
        $image = (new PngImageDecoder())->decode($this->fixture('rgba.png'));
        $stream = (new ImageXObjectFactory())->create($image);
        $reference = $stream->dictionary()->get('SMask');
        self::assertInstanceOf(IndirectReference::class, $reference);
        $mask = $reference->target();
        self::assertInstanceOf(StreamObject::class, $mask);
        self::assertEquals(new NameType('DeviceGray'), $mask->dictionary()->get('ColorSpace'));
        self::assertSame($image->alpha?->bytes, gzuncompress($mask->bytes(new SerializationContext())));
        $context = new SerializationContext();
        $context->prepare($stream);
        self::assertCount(2, $context->objects());
        self::assertStringContainsString('/SMask ', $stream->toPdf($context));
    }

    private function fixture(string $filename): ImageFileBytes
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/examples/assets/images/' . $filename);
        self::assertIsString($bytes);
        return new ImageFileBytes($bytes);
    }

    private function pngHeader(int $width, int $height, int $type, int $depth = 8, int $interlace = 0): string
    {
        return "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $type, 0, 0, $interlace));
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . hash('crc32b', $type . $data, true);
    }
}
