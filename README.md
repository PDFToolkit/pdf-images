# PDFToolkit Images

English | [Français](README.fr.md)

Optional PNG and JPEG file converters for the PDF image objects in
[PDFToolkit Core](https://github.com/PDFToolkit/pdf), for PHP 8.3 to 8.5.

The core represents PDF images, their colour spaces, samples, the `/DCTDecode`
filter and transparency masks described by ISO 32000-1. This package interprets
external file formats: PNG chunks and CRCs, scanlines, palettes, JPEG markers
and frame components. An application that produces its own samples can use
the core alone.

## Installation

The package is available on
[Packagist](https://packagist.org/packages/pdftoolkit/pdf-images). In your
application:

```sh
composer require "pdftoolkit/pdf-images:^0.1"
```

The module uses `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7 || ^0.8 || ^0.9`, installed
automatically by Composer. PHP 8.3 or newer on a 64-bit platform and the `ctype`,
`iconv` and `zlib` extensions are required for this combination. The application's
`composer.lock` records the selected versions and revisions. Installing the
core alone does not install any converters.
Shared documentation is available at [PDFToolkit](https://pdftoolkit.github.io/).
The [image module documentation](https://pdftoolkit.github.io/modules/images/)
contains its guides and API reference.

The same converters are compatible with core 0.6, 0.7, 0.8 and 0.9. PNG/JPEG conversion
keeps its existing scope. Selecting the output version and supplying PDF 2.0
metadata, tagging or security objects belong to the core writer.

## Explicit construction

```php
use PDF\Document\Images\ImageXObjectFactory;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Images\JpegImageReader;
use PDF\Images\PngImageDecoder;

// The application supplies bytes. No importer opens a path or URL.
$pngFile = new ImageFileBytes($pngBytes);
$jpegFile = new ImageFileBytes($jpegBytes);

// Each instance has its own limits, chosen for the application.
$limits = new ImageImportLimits(
    maxEncodedBytes: 1_048_576,
    maxDecodedBytes: 4_194_304,
    maxPixels: 1_048_576,
    maxDimension: 2048,
);

// PNG becomes a core SampledImage: colour and alpha are separate.
$samples = (new PngImageDecoder($limits))->decode($pngFile);

// JPEG becomes a core DCTImage: encoded data and PDF parameters.
$dct = (new JpegImageReader($limits))->read($jpegFile);

// The core composes the /Subtype /Image streams from table 89.
$factory = new ImageXObjectFactory();
$pngObject = $factory->create($samples);
$jpegObject = $factory->create($dct);
```

Add these streams explicitly to a page's `/XObject` resources, then paint them
with an `InvokeXObject` operator and a matrix. The alpha mask is another
greyscale PDF image object, referenced by `/SMask`. JPEG bytes remain unchanged
with the `/DCTDecode` filter. The writer and document graph belong to the core;
the converters have no singleton, global registry or page-building facade.

## Supported formats

- Static, non-interlaced PNG with eight bits per component: greyscale, RGB,
  palette, greyscale with alpha and RGBA. All five scanline filters are
  reconstructed. Palettes are expanded to RGB; `tRNS` becomes an alpha mask.
- Baseline or progressive Huffman DCT JPEG with eight bits per component:
  greyscale, RGB and Adobe CMYK with transform zero. Required tables, frames
  and markers are checked; the PDF viewer decodes the DCT coefficients.

Animated or interlaced PNGs, other bit depths, embedded ICC profiles, YCCK JPEGs
and ambiguous colour semantics are explicitly rejected. EXIF metadata does
not cause automatic rotation. PNG gamma or chromaticity information does not
create a calibrated colour space.

## Checks and examples

The suite verifies expected samples and alpha, preserved JPEG bytes, generated
PDF streams, CRCs, truncation and dimension, chunk and decompression limits.
The [contracts and limits](docs/architecture.md) describe each object.

The [complete example](examples/image-files.php) builds a page containing four
images and checkerboards that reveal transparency. Its original patterns are
distributed under the MIT licence. Their Pillow generator is used only for
development; the PHP package does not depend on Pillow or GD.

```sh
php examples/image-files.php output.pdf
```

See the [changelog](CHANGELOG.md) and [security policy](SECURITY.md).
