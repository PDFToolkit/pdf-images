# Original JPEG colour fixtures

English | [Français](README.fr.md)

The nine 48 × 32 images are original six-block motifs, distributed under the
repository's MIT licence. `tools/generate_jpeg_fixtures.py` records encoder
versions and SHA-256 hashes in `manifest.json`. Pillow/libjpeg encodes baseline
and progressive Gray/RGB/CMYK images. ImageMagick/libjpeg encodes Adobe YCCK;
the generator adds complete ICC APP2 chunks in normal and shuffled order.

RGB and Gray profiles reuse the original PNG fixture profiles. `cmyk.icc` is
an original deterministic ICC v2 input profile with a four-channel CMYK to D50
XYZ LUT and a simple subtractive model. LittleCMS independently opens it and
builds a colour transform. It is a test profile, not a printer calibration.
No third-party image or colour profile is redistributed here.

Run `python tools/generate_jpeg_fixtures.py` with Pillow and ImageMagick to
regenerate. Encoded JPEG bytes may depend on encoder versions, so committed
hashes and the manifest identify the exact independent corpus. Run
`python tools/validate_jpeg.py --report jpeg-validation.json` after Composer
installation to validate exact image/profile bytes, PDF dictionaries and
rendered output using qpdf, pypdf, Poppler and Pillow/LittleCMS. Colour comparison
uses Poppler's LittleCMS settings (no approximate LUT, black-point compensation)
and flat interiors to exclude raster resampling at block edges. The complete
raster must also equal one from an independently assembled pypdf document.
