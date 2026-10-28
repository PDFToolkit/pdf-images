# Extended PNG fixtures

English | [Français](README.fr.md)

35 original synthetic images cover every legal PNG colour/depth combination,
non-interlaced and Adam7, all five byte filters, full-precision transparency
keys, and grayscale/RGB ICC profiles. `manifest.json` records exact input
SHA-256, expected colour/alpha bytes and precision, and profile SHA-256.

`tools/generate_png_fixtures.py` is an independent Python PNG encoder. It uses
system LittleCMS only to create the two original colour profiles; creation
time and optional profile IDs are normalized for reproducibility. The profiles
are not certificates or identities. All fixtures are distributed under the
repository MIT license. No third-party pictures or confidential metadata occur.

`tools/validate_png.py` independently decodes the PNGs with Pillow and
ImageMagick/libpng, checks emitted PDF sample/profile bytes with pypdf, and
renders every case with Poppler. ImageMagick Q16 supplies exact non-eight-bit
colour/alpha comparisons where Pillow's conversions lose precision or tRNS.
The seven Adam7 pass/filter states are not shared with the PHP implementation.

Regeneration needs Python and LittleCMS. Validation needs pinned Python packages,
PHP, qpdf, Poppler (`pdftoppm`) and ImageMagick Q16 (`convert` or `--imagemagick magick`).
