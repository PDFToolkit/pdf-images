# Image converter contracts

English | [Français](fr/architecture.md)

The package adds no PDF structures and does not modify the writer. It
converts bytes from external formats into core values.

| Object | Responsibility |
| --- | --- |
| `PDF\Images\ImageFileBytes` | Carries bytes supplied by the application, without file or network access. |
| `PDF\Images\ImageImportLimits` | Bounds encoded data, decoded samples, pixels, dimensions and chunk or marker counts. |
| `PDF\Images\JpegImageReader` | Interprets supported JPEG markers, tables, frames and components; returns a `PDF\Document\Images\DCTImage`. |
| `PDF\Images\JpegFrame` and `JpegComponent` | Represent the inspected JPEG frame and its components. |
| `PDF\Images\PngImageDecoder` | Validates PNG structure and reconstructs colour and alpha; returns a `PDF\Document\Images\SampledImage`. |
| `PDF\Images\PngChunk` | Validates a chunk's name and CRC. |
| `PDF\Images\PngColorType` | Represents PNG colour codes, separate from PDF colour spaces. |
| `PDF\Images\PngScanlineDecoder` | Reconstructs all five PNG filters on byte-aligned samples. |

The result is then passed to `PDF\Document\Images\ImageXObjectFactory`.
This core composer also accepts samples or DCT data produced by other means.
It does not inspect PNG signatures or JPEG markers.

## PNG processing

1. The signature, chunk order and sizes, and every CRC are checked before
   interpretation.
2. The header is checked against the limits before scanlines are allocated.
3. zlib inflation consumes 64-byte compressed fragments and rejects data that
   exceeds the expected size, is truncated or has trailing data.
4. Filters are reversed, palette indices are expanded, and alpha samples are
   separated.
5. The core receives the samples and selects their PDF encoding with FlateDecode.

Default limits are 16 MiB encoded, 16 MiB decoded, 4,194,304 pixels, 32,768
pixels per dimension and 65,536 chunks. These limits apply to each image;
the application also sets request budgets and an image count limit.

## JPEG processing

The reader preserves the original bytes. It checks segment bounds,
quantisation and Huffman tables, components, scan parameters and the end-of-file
marker. It determines PDF `/ColorSpace` and `/ColorTransform` parameters and,
for inverted Adobe CMYK, `/Decode`.

It does not perform entropy decoding and therefore does not claim to validate
every DCT coefficient. The PDF viewer's JPEG decoder remains responsible for
rendering. Ambiguous data and unsupported encodings are rejected.

## Integration

Converters and returned values are explicitly constructed objects. They never
open a path or URL and do not add images to a page. The `/XObject` resource,
indirect reference, placement matrix and `Do` operator remain core objects
chosen by the application. The package is optional when generating from
samples that are already available.
