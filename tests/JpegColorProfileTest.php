<?php

declare(strict_types=1);

namespace PDF\Images\Tests;

use PDF\Document\Images\ImageXObjectFactory;
use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Images\JpegImageReader;
use PDF\Serialization\SerializationContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JpegColorProfileTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function cases(): iterable
    {
        $data = json_decode(file_get_contents(__DIR__ . '/Fixtures/jpeg/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['cases'] as $name => $case) {
            yield $name => [$name, $case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('cases')]
    public function testIndependentJpegsRetainProfilesTransformsAndExactEncodedData(string $name, array $case): void
    {
        $bytes = $this->fixture($name . '.jpg');
        self::assertSame($case['sha256'], hash('sha256', $bytes));
        $image = (new JpegImageReader())->read(new ImageFileBytes($bytes));
        self::assertSame($bytes, $image->encodedBytes);
        self::assertSame($case['transform'], $image->colorTransform);
        self::assertSame($case['inverted'], $image->invertedComponents);
        self::assertSame($case['components'], $image->layout->components());
        $stream = (new ImageXObjectFactory())->create($image);
        $context = new SerializationContext();
        $context->prepare($stream);
        self::assertSame($bytes, $stream->bytes($context));
        if ($case['profile'] !== null) {
            self::assertInstanceOf(ICCBased::class, $image->layout->colorSpace);
            self::assertSame($case['profileSha256'], hash('sha256', $image->layout->colorSpace->profile->bytes($context)));
            self::assertStringContainsString('/ICCBased ', $stream->toPdf($context));
        }
        if ($case['inverted']) {
            self::assertStringContainsString('/Decode [1 0 1 0 1 0 1 0]', $stream->toPdf($context));
        }
    }

    public function testIccSegmentsAreNotBoundToPhysicalMarkerOrderOrScanPosition(): void
    {
        $jpeg = $this->fixture('ycck.jpg');
        $profile = $this->fixture('cmyk.icc');
        $late = substr($jpeg, 0, -2) . $this->part(1, 1, $profile) . substr($jpeg, -2);
        $image = (new JpegImageReader())->read(new ImageFileBytes($late));
        self::assertInstanceOf(ICCBased::class, $image->layout->colorSpace);
        self::assertSame($profile, $image->layout->colorSpace->profile->bytes(new SerializationContext()));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedParts(): iterable
    {
        $self = new self('testMalformedProfilePartsAreRejected');
        $profile = $self->fixture('cmyk.icc');
        yield 'sequence zero' => [$self->part(0, 1, $profile)];
        yield 'count zero' => [$self->part(1, 0, $profile)];
        yield 'sequence beyond count' => [$self->part(2, 1, $profile)];
        yield 'missing part' => [$self->part(1, 2, $profile)];
        yield 'duplicate' => [$self->part(1, 1, $profile) . $self->part(1, 1, $profile)];
        yield 'inconsistent count' => [$self->part(1, 2, substr($profile, 0, 100)) . $self->part(2, 3, substr($profile, 100))];
        yield 'empty part' => [$self->part(1, 1, '')];
        yield 'wrong profile component count' => [$self->part(1, 1, file_get_contents(__DIR__ . '/Fixtures/png/rgb.icc'))];
        yield 'invalid profile bytes' => [$self->part(1, 1, 'not an ICC profile')];
        yield 'truncated header' => ["\xff\xe2\x00\x0fICC_PROFILE\0\x01"];
    }

    #[DataProvider('malformedParts')]
    public function testMalformedProfilePartsAreRejected(string $parts): void
    {
        $jpeg = $this->fixture('ycck.jpg');
        $this->expectException(\InvalidArgumentException::class);
        (new JpegImageReader())->read(new ImageFileBytes(substr($jpeg, 0, 2) . $parts . substr($jpeg, 2)));
    }

    public function testProfileRetentionIsBoundedBeforeAssembly(): void
    {
        $file = new ImageFileBytes($this->fixture('ycck-icc-shuffled.jpg'));
        foreach ([new ImageImportLimits(maxProfileBytes: 100), new ImageImportLimits(maxProfileTags: 2), new ImageImportLimits(maxCumulativeDecodedBytes: 1000)] as $limits) {
            try {
                (new JpegImageReader($limits))->read($file);
                self::fail('Expected an ICC profile bound rejection.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    private function part(int $number, int $count, string $bytes): string
    {
        $payload = "ICC_PROFILE\0" . chr($number) . chr($count) . $bytes;
        return "\xff\xe2" . pack('n', strlen($payload) + 2) . $payload;
    }

    private function fixture(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/Fixtures/jpeg/' . $name);
        self::assertIsString($bytes);
        return $bytes;
    }
}
