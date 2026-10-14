# Changelog

English | [Français](CHANGELOG.fr.md)

## Unreleased

## 0.1.6 — 2026-10-14

### Bilingual documentation

- Makes English the default language for the README, guides and complete release history.
- Adds complete French counterparts with reciprocal language links and French documentation entrypoints.
- Translates explanatory example comments while preserving executable tokens and output data.
- Locks validation to published core 0.5.4 without changing other dependency versions or commits.
- Preserves API identifiers, original fixtures and runtime behaviour.
## 0.1.5 — 2026-10-14

### Installation and publication

- Publishes the package on Packagist and reduces installation to one
  `composer require` command in an application.
- Uses default Composer repositories and connects package metadata to its
  documentation at `https://pdftoolkit.github.io/`.
- Locks checks to stable core `roukmoute/pdf:0.5.3` without changing development
  tool versions.
- Verifies indexing of published versions and installation without VCS or
  local repositories. Preserves the PHP API, existing mechanisms and limits.

## 0.1.4 — 2026-10-14

### Documentation

- Connects shared documentation to `https://pdftoolkit.github.io/` and adds
  the stable images entrypoint at `/modules/images/`.
- Entrusts shared guides and site publication to
  `PDFToolkit/pdftoolkit.github.io`, with module sources pinned to their
  published versions and exact commits.
- Corrects README compatibility information to reflect the existing
  `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5` constraint.
- Preserves the API, conversions, dependencies and their locked versions;
  this release changes documentation only.

## 0.1.3 — 2026-10-13

### Compatibility

- Accepts stable core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5`.
- Locks checks to published core `0.4.0` under PHP 8.3, 8.4 and 8.5.
- Preserves the images API and limits; no additional image-format processing
  is added by this compatibility patch.

## 0.1.2 — 2026-10-13

### Compatibility

- Accepts stable core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4`.
- Preserves the module API and external-data processing boundary.
- Checks stable core 0.3.0 and new core 0.4 objects in development
  under PHP 8.3, 8.4 and 8.5.

## 0.1.1 — 2026-10-13

### Compatibility

- Accepts core `roukmoute/pdf:^0.2 || ^0.3`, allowing installation with new
  PDF objects in the 0.3 series without changing the module API.
- Revalidates tests and independent PDFs with stable core `0.2.1`.
  No reading or conversion mechanism is added by this patch.
## 0.1.0 — 2026-10-13

First release of `pdftoolkit/pdf-images`, under `PDF\Images`.
The module depends on core `roukmoute/pdf:^0.2`, which retains normalised
PDF image objects; the core does not depend on image converters.

### Added

- Nine components to convert PNG or JPEG bytes into `SampledImage` and
  `DCTImage`, without implicit file or URL access.
- Decoding of static non-interlaced PNGs with eight bits per component:
  greyscale, RGB, palette, greyscale with alpha and RGBA.
- PNG signature, chunk order, size and CRC validation; reconstruction of all
  five scanline filters and palette expansion.
- Alpha separation, including `tRNS`, to construct the `/SMask` explicitly
  using core objects.
- Reading of markers, tables, frames and components in baseline or progressive
  eight-bit Huffman DCT JPEGs: greyscale, RGB and Adobe CMYK with transform zero.
- Preservation of original JPEG bytes and determination of PDF colour,
  transformation and inversion parameters.
- Per-instance limits for encoded and decoded data, dimensions, pixels and
  chunk or marker count; bounded PNG inflation.
- Four-image transparency example and original fixtures under the MIT licence.
- Tests of samples, alpha, preserved bytes, invalid structures and limits;
  checks on PHP 8.3, 8.4 and 8.5.

Animated or interlaced PNGs, other bit depths, embedded ICC profiles, YCCK JPEGs
and ambiguous colour semantics are rejected. EXIF metadata does not trigger
automatic rotation. The module does not perform entropy decoding of JPEG
coefficients; the PDF viewer renders them.
