<?php

declare(strict_types=1);

namespace PDF\Images\Tests;

use PDF\Document\Images\DeviceColorSpace;
use PDF\Graphics\ColourSpaces\CalGray;
use PDF\Graphics\ColourSpaces\CalRGB;
use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Graphics\GraphicsState\RenderingIntent;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Images\PngColorDefaults;
use PDF\Images\PngImageDecoder;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\Object\NumericType;
use PHPUnit\Framework\TestCase;

final class PngColorMetadataTest extends TestCase
{
    public function testGammaAndPrimariesProduceCalibratedRgbWithoutChangingSamples(): void
    {
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($this->chunk('gAMA', pack('N', 50000)) . $this->chunk('cHRM', $this->chromaticities()))));
        self::assertSame("\x11\x22\x33", $image->samples->bytes);
        $space = $image->samples->layout->colorSpace;
        self::assertInstanceOf(CalRGB::class, $space);
        self::assertSame(2.0, $space->gamma->red->value);
        self::assertEqualsWithDelta(0.950455927, $space->whitePoint->x->value, 1.0e-8);
        $red = $space->matrix->transform(new NumericType(1), new NumericType(0), new NumericType(0));
        self::assertEqualsWithDelta(0.412390799, $red->x->value, 1.0e-8);
        self::assertEqualsWithDelta(0.212639006, $red->y->value, 1.0e-8);
        self::assertEqualsWithDelta(0.019330819, $red->z->value, 1.0e-8);
    }

    public function testGrayGammaIsCalibratedAndMissingGammaUsesExplicitPolicy(): void
    {
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($this->chunk('gAMA', pack('N', 100000)), true)));
        self::assertInstanceOf(CalGray::class, $image->samples->layout->colorSpace);
        self::assertSame(1.0, $image->samples->layout->colorSpace->gamma->value);
        $decoder = new PngImageDecoder(colorDefaults: new PngColorDefaults(imageGamma: 0.5));
        $image = $decoder->decode(new ImageFileBytes($this->png($this->chunk('cHRM', $this->chromaticities()))));
        self::assertInstanceOf(CalRGB::class, $image->samples->layout->colorSpace);
        self::assertSame(2.0, $image->samples->layout->colorSpace->gamma->red->value);
    }

    public function testZeroYPrimaryDoesNotRequireDivisionByItsLuminance(): void
    {
        $metadata = $this->chunk('cHRM', pack('N8', 31270, 32900, 100000, 0, 0, 100000, 0, 0));
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($metadata)));
        $space = $image->samples->layout->colorSpace;
        self::assertInstanceOf(CalRGB::class, $space);
        $white = $space->matrix->transform(new NumericType(1), new NumericType(1), new NumericType(1));
        self::assertEqualsWithDelta($space->whitePoint->x->value, $white->x->value, 1e-12);
        self::assertEqualsWithDelta(1, $white->y->value, 1e-12);
        self::assertEqualsWithDelta($space->whitePoint->z->value, $white->z->value, 1e-12);
    }

    public function testZeroGammaIsIgnoredAndUntaggedSamplesStayDeviceDependent(): void
    {
        foreach (['', $this->chunk('gAMA', pack('N', 0))] as $metadata) {
            $image = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($metadata)));
            self::assertSame(DeviceColorSpace::RGB, $image->samples->layout->colorSpace);
        }
    }

    public function testColorMetadataAndBudgetsBelongToOneDecodeCall(): void
    {
        $decoder = new PngImageDecoder(new ImageImportLimits(maxCumulativeDecodedBytes: 1300));
        for ($index = 0; $index < 2; ++$index) {
            $tagged = $decoder->decode(new ImageFileBytes($this->png($this->chunk('sRGB', "\1"))));
            self::assertInstanceOf(ICCBased::class, $tagged->samples->layout->colorSpace);
            self::assertSame(RenderingIntent::RelativeColorimetric, $tagged->intent);
            $plain = $decoder->decode(new ImageFileBytes($this->png('')));
            self::assertSame(DeviceColorSpace::RGB, $plain->samples->layout->colorSpace);
            self::assertNull($plain->intent);
        }
    }

    public function testSrgbOverridesGammaAndPreservesEveryRenderingIntent(): void
    {
        $expected = [RenderingIntent::Perceptual, RenderingIntent::RelativeColorimetric, RenderingIntent::Saturation, RenderingIntent::AbsoluteColorimetric];
        foreach ($expected as $value => $intent) {
            foreach ([false, true] as $gray) {
                $image = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($this->chunk('sRGB', chr($value)) . $this->chunk('gAMA', pack('N', 100000)), $gray)));
                $space = $image->samples->layout->colorSpace;
                self::assertInstanceOf(ICCBased::class, $space);
                self::assertSame($gray ? 1 : 3, $space->componentCount());
                self::assertSame($intent, $image->intent);
                $bytes = $space->profile->bytes(new SerializationContext());
                self::assertSame(pack('N', $value), substr($bytes, 64, 4));
                self::assertStringContainsString('para', $bytes);
                self::assertSame($gray ? "\x11" : "\x11\x22\x33", $image->samples->bytes);
            }
        }
    }

    public function testEmbeddedProfileTakesPrecedenceAndKeepsExactInputBytes(): void
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/png/rgb.icc');
        $metadata = $this->chunk('sRGB', "\2") . $this->chunk('gAMA', pack('N', 100000)) . $this->chunk('iCCP', "input\0\0" . gzcompress($bytes));
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($metadata)));
        self::assertInstanceOf(ICCBased::class, $image->samples->layout->colorSpace);
        self::assertSame($bytes, $image->samples->layout->colorSpace->profile->bytes(new SerializationContext()));
        self::assertNull($image->intent);
    }

    public function testColorChunksValidateLengthMultiplicityOrderingAndSemanticValues(): void
    {
        foreach ([
            $this->chunk('gAMA', 'bad'),
            $this->chunk('cHRM', 'bad'),
            $this->chunk('sRGB', ''),
            $this->chunk('sRGB', "\4"),
            $this->chunk('gAMA', pack('N', 100000)) . $this->chunk('gAMA', pack('N', 50000)),
            $this->chunk('cHRM', pack('N8', 31270, 32900, 64000, 33000, 64000, 33000, 64000, 33000)),
            $this->chunk('cHRM', pack('N8', 31270, 0, 64000, 33000, 30000, 60000, 15000, 6000)),
            $this->chunk('PLTE', "\0\0\0") . $this->chunk('sRGB', "\0"),
        ] as $metadata) {
            $this->reject($this->png($metadata));
        }
        $this->reject($this->png('', suffix: $this->chunk('gAMA', pack('N', 50000))));
    }

    public function testBuiltInProfilesRespectProfileAndCumulativeLimits(): void
    {
        foreach ([new ImageImportLimits(maxProfileBytes: 128), new ImageImportLimits(maxProfileTags: 1), new ImageImportLimits(maxCumulativeDecodedBytes: 100)] as $limits) {
            try {
                (new PngImageDecoder($limits))->decode(new ImageFileBytes($this->png($this->chunk('sRGB', "\0"))));
                self::fail('The configured profile budget was bypassed.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testTransparencyIgnoresUnusedHighBitsAndIdatAllowsTrailingBufferBytes(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', pack('NNCCCCC', 2, 1, 2, 0, 0, 0, 0))
            . $this->chunk('tRNS', "\xff\xfc") . $this->chunk('IDAT', gzcompress("\0\x10") . 'unused buffer') . $this->chunk('IEND', '');
        $image = (new PngImageDecoder())->decode(new ImageFileBytes($png));
        self::assertSame("\x10", $image->samples->bytes);
        self::assertSame("\0\xff", $image->alpha?->bytes);
        $rgb = (new PngImageDecoder())->decode(new ImageFileBytes($this->png($this->chunk('tRNS', "\xff\x11\xff\x22\xff\x33"))));
        self::assertSame("\0", $rgb->alpha?->bytes);
    }

    private function chromaticities(): string
    {
        return pack('N8', 31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000);
    }

    private function png(string $metadata, bool $gray = false, string $suffix = ''): string
    {
        return "\x89PNG\r\n\x1a\n" . $this->chunk('IHDR', pack('NNCCCCC', 1, 1, 8, $gray ? 0 : 2, 0, 0, 0))
            . $metadata . $this->chunk('IDAT', gzcompress($gray ? "\0\x11" : "\0\x11\x22\x33")) . $suffix . $this->chunk('IEND', '');
    }

    private function reject(string $bytes): void
    {
        try {
            (new PngImageDecoder())->decode(new ImageFileBytes($bytes));
            self::fail('Invalid PNG colour metadata was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }

    private function chunk(string $type, string $bytes): string
    {
        return pack('N', strlen($bytes)) . $type . $bytes . hash('crc32b', $type . $bytes, true);
    }
}
