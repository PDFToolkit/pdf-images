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
composer require "pdftoolkit/pdf-images:^0.2"
```

The module uses `roukmoute/pdf:^0.12`, installed
automatically by Composer. PHP 8.3 or newer on a 64-bit platform and the `ctype`,
`iconv` and `zlib` extensions are required for this combination. The application's
`composer.lock` records the selected versions and revisions. Installing the
core alone does not install any converters.
Shared documentation is available at [PDFToolkit](https://pdftoolkit.github.io/).
The [image module documentation](https://pdftoolkit.github.io/modules/images/)
contains its guides and API reference.

Version 0.2 requires core 0.12 to retain PNG rendering intents and JPEG CMYK
ICC colour spaces. Selecting the PDF output version and supplying metadata,
tagging or security objects belong to the core writer. Applications retaining
an earlier core can stay on Images 0.1.

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

- Static PNG with every legal colour/depth combination: greyscale 1/2/4/8/16,
  palette 1/2/4/8, and RGB, greyscale with alpha or RGBA 8/16 bits. Both
  non-interlaced and Adam7 images reconstruct all five scanline filters.
  Palettes expand to RGB; `tRNS` compares full-precision values and becomes
  an alpha mask. Sixteen-bit colour and alpha stay sixteen-bit. Embedded
  grayscale/RGB ICC profiles are bounded, structurally checked and retained
  byte for byte in an explicit PDF ICCBased colour space. `gAMA`/`cHRM`
  produce calibrated PDF spaces; `sRGB` uses an exact piecewise transfer
  curve and retains its rendering intent. [Colour policy](docs/png-colour.md)
  describes precedence and explicit defaults for incomplete metadata.
- Baseline or progressive Huffman DCT JPEG with eight bits per component:
  greyscale, RGB, Adobe CMYK and YCCK. Gray/RGB/CMYK ICC profiles are
  reassembled from APP2 parts and preserved exactly. Required tables, frames
  and markers are checked; the PDF viewer decodes the DCT coefficients.

Animated PNGs, HDR colour metadata and
ambiguous colour semantics are explicitly rejected. EXIF metadata does
not cause automatic rotation. Untagged PNG samples remain device-dependent.

## Checks and examples

The suite verifies expected samples and alpha, preserved JPEG bytes, generated
PDF streams, CRCs, truncation and dimension, chunk, profile and cumulative decompression limits. The extended
PNG corpus compares exact output samples and ICC bytes with pypdf, decodes with
Pillow and ImageMagick/libpng, and checks rendered colour and opacity with Poppler.
The [contracts and limits](docs/architecture.md) describe each object.

The [complete example](examples/image-files.php) builds a page containing four
images and checkerboards that reveal transparency. Its original patterns are
distributed under the MIT licence. Their Pillow generator is used only for
development; the PHP package does not depend on Pillow or GD.

```sh
php examples/image-files.php output.pdf
```

See the [changelog](CHANGELOG.md) and [security policy](SECURITY.md).
