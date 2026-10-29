# Image converter contracts

English | [Français](fr/architecture.md)

The 0.2 series requires stable core `roukmoute/pdf:^0.12` for image rendering
intents and inverted four-component ICC DCT images. PNG/JPEG conversion remains
separate from PDF 2.0 metadata, tagging and cryptographic mechanisms, which the
application composes using core objects.

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

`PngHeader` validates the combinations in PNG table 12: grayscale at 1/2/4/8/16
bits, indexed colour at 1/2/4/8 bits, and RGB, grayscale+alpha and RGBA at 8/16
bits. `PngScanlineDecoder` accepts an optional fourth bit-depth argument
(8 by default), preserving existing calls.

1. The signature, chunk order and sizes, and every CRC are checked before
   interpretation.
2. The header is checked against the limits before scanlines are allocated.
3. zlib inflation consumes 64-byte compressed fragments and rejects data that
   exceeds the expected decoded size or is truncated. Unused encoded bytes after
   the final IDAT zlib stream are ignored as PNG permits; iCCP trailing data
   remains invalid.
4. Filters are reversed independently for each Adam7 pass before scattering
   pixels into row-padded output. Empty passes contain no filter byte. Packed
   grayscale retains its depth; indexed samples expand to eight-bit RGB.
   Sixteen-bit colour and intrinsic alpha stay sixteen-bit, in network order.
5. Transparency keys (`tRNS`) mask unused high bits, then compare every original
   sample bit before producing an eight-bit opacity mask. An ICC profile
   remains unchanged in an explicit ICCBased space with a matching component
   count.
6. The core receives the samples and selects their PDF encoding with FlateDecode.

Default limits are 16 MiB encoded, 16 MiB decoded, 4,194,304 pixels, 32,768
pixels per dimension and 65,536 chunks. Additional defaults in
`ImageImportLimits` are `maxCumulativeDecodedBytes` (64 MiB of cumulative
decoded-buffer allocations), `maxProfileBytes` (4 MiB per ICC profile) and
`maxProfileTags` (4,096 ICC tags).
The profile, filtered pass slices, reconstruction buffers and final colour/alpha
share one `ImageImportBudget` created per decode call. The combined returned
colour and alpha also fit `maxDecodedBytes`. Limits are checked before
allocations and reset for the next call; they are not a process-memory guarantee.
The application also sets request budgets and an image count limit.

PNG rules follow [PNG Third Edition](https://www.w3.org/TR/2025/REC-png-3-20250624/),
sections 8.1, 9, 11.2.1 (table 12), 11.3.1.1 and 11.3.2.3. PDF image precision
and ICC embedding follow the retained `ISO_32000-2_sponsored_EC3.pdf`, physical
pages 206–208 and 273–276. ICC checks cover framing, component compatibility
and bounded tag extents, not all ICC semantics; colour-transform evaluation
belongs to the PDF viewer.
The [PNG colour policy](png-colour.md) covers gAMA/cHRM calibration and exact
sRGB profiles, including declaration priority and rendering intent. Animation
and HDR colour metadata remain unsupported. EXIF does not rotate imported samples.

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
for inverted Adobe CMYK or YCCK, `/Decode`. Adobe transform 2 selects
PDF `/ColorTransform 1` with four inverted component ranges; transform 0
retains CMYK without a colour transform.

`JpegProfileParts` assembles ICC APP2 chunks by sequence number, including
physically shuffled chunks, with explicit profile and cumulative allocation
bounds. Missing, duplicate or inconsistent chunks are rejected.
`ImageIccProfile` checks Gray/RGB/CMYK compatibility and preserves every
profile byte in an ICCBased space. Marker position does not replace sequence
numbering: complete ICC chunks after a scan are accepted too.

The contracts follow the retained `ISO_32000-2_sponsored_EC3.pdf`, physical
pages 63–65, and [ICC.1:2004-04](https://www.color.org/ICC1V42old.pdf),
Annex B.5, physical page 81. `tools/validate_jpeg.py` checks nine original
baseline/progressive/CMYK/YCCK fixtures with qpdf, pypdf, Poppler and
Pillow/LittleCMS. Encoded JPEG and ICC bytes are exact; every raster pixel
must match an independently assembled pypdf document. Flat interior colours
are also compared against independently decoded JPEG samples and LittleCMS
with at most three eight-bit levels per channel. The oracle disables the
approximate LittleCMS precalculated LUT and enables black-point compensation,
matching Poppler settings. The synthetic CMYK profile is a test fixture,
not a calibrated printer profile.

It does not perform entropy decoding and therefore does not claim to validate
every DCT coefficient. The PDF viewer's JPEG decoder remains responsible for
rendering. Ambiguous data and unsupported encodings are rejected.

## Integration

Converters and returned values are explicitly constructed objects. They never
open a path or URL and do not add images to a page. The `/XObject` resource,
indirect reference, placement matrix and `Do` operator remain core objects
chosen by the application. The package is optional when generating from
samples that are already available.
