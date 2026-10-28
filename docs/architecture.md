# Image converter contracts

English | [Français](fr/architecture.md)

Version 0.1.8 accepts core 0.7 alongside earlier supported releases. PNG/JPEG
conversion remains separate from PDF 2.0 metadata, tagging and cryptographic
mechanisms, which the application composes using core objects.

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
| `PDF\Images\PngScanlineDecoder` | Reconstructs all five PNG byte filters, including packed and sixteen-bit samples. |
| `PngHeader`, `PngPasses`, `PngPass`, `PngRasterDecoder`, `PngRaster` | Validate the legal colour/depth combinations and reconstruct non-interlaced or Adam7 rows without reducing precision. |
| `PngSampleConverter` | Separates colour/alpha and expands palettes; compares transparency keys at full precision. |
| `PngProfileReader`, `ImageIccProfile` | Bound ICC decompression, check name/header/tag extents and colour compatibility, and preserve profile bytes in core `ICCProfile`/`ICCBased`. |
| `ImageImportBudget`, `ImageDataInflater` | Accumulate decoded-buffer costs across profile inflation, image inflation, passes and returned samples. |

The result is then passed to `PDF\Document\Images\ImageXObjectFactory`.
This core composer also accepts samples or DCT data produced by other means.
It does not inspect PNG signatures or JPEG markers.

## PNG processing

1. The signature, chunk order and sizes, and every CRC are checked before
   interpretation.
2. The header is checked against the limits before scanlines are allocated.
3. zlib inflation consumes 64-byte compressed fragments and rejects data that
   exceeds the expected size, is truncated or has trailing data.
4. Filters are reversed independently for each Adam7 pass before scattering
   pixels into row-padded output. Empty passes contain no filter byte. Packed
   grayscale retains its depth; indexed samples expand to eight-bit RGB.
   Sixteen-bit colour and intrinsic alpha stay sixteen-bit, in network order.
5. Transparency keys compare every original sample bit before producing an
   eight-bit opacity mask. An ICC profile remains unchanged in an explicit
   ICCBased space with a matching component count.
6. The core receives the samples and selects their PDF encoding with FlateDecode.

Default limits are 16 MiB encoded, 16 MiB decoded, 4,194,304 pixels, 32,768
pixels per dimension and 65,536 chunks. Additional defaults are 64 MiB of
cumulative decoded-buffer allocations, 4 MiB per ICC profile and 4,096 ICC tags.
The profile, filtered pass slices, reconstruction buffers and final colour/alpha
share one `ImageImportBudget` created per decode call. The combined returned
colour and alpha also fit `maxDecodedBytes`. Limits are checked before
allocations and reset for the next call; they are not a process-memory guarantee.
The application also sets request budgets and an image count limit.

PNG rules follow [PNG Third Edition](https://www.w3.org/TR/2025/REC-png-3-20250624/),
sections 8.1, 9, 11.2.1 (table 12), 11.3.1.1 and 11.3.2.3. PDF image precision
and ICC embedding follow the retained `ISO_32000-2_sponsored_EC3.pdf`, physical
pages 206–208 and 273–276. ICC checks cover framing, component compatibility
and bounded tag extents; colour-transform evaluation belongs to the PDF viewer.
Animation, HDR colour metadata and gamma/chromaticity calibration remain
separate capabilities. EXIF does not rotate imported samples.

`tools/validate_png.py` verifies 35 original fixtures with pypdf, Pillow,
ImageMagick/libpng and Poppler. The PDF sample/profile bytes must match exactly;
rendered pixel centres allow at most three eight-bit levels per channel for
independent colour management and rounding. Pillow alone does not retain every
non-eight-bit transparency-key conversion, so ImageMagick/libpng independently
checks all colour and opacity samples at sixteen-bit output precision.

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
