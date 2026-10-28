# PNG colour declarations

English | [Français](fr/png-colour.md)

`PngImageDecoder` retains the original colour samples and supplies the colour
space to the core. It does not apply gamma to alpha, rotate samples from EXIF,
open profile files or perform a device-specific colour conversion.

| Source declaration, in decreasing priority | PDF result |
| --- | --- |
| `iCCP` | Exact input profile in an `ICCBased` space, with matching gray/RGB components. |
| `sRGB` | A generated standard ICC profile with the piecewise sRGB transfer curve, plus `/Intent`. |
| `gAMA` and/or `cHRM` | `CalGray` or `CalRGB`, with inverse image gamma, normalized XYZ white point and, for RGB, a matrix derived from the declared primaries. |
| None, or only zero-valued `gAMA` | Existing device-dependent gray/RGB samples. |

`PngColorMetadata` holds declarations for one conversion. `PngChromaticities`
solves the RGB-to-XYZ matrix using explicit xy coordinates. Singular primaries,
non-finite or invalid coordinates and a white point outside the RGB triangle
cannot define a usable calibrated space and are rejected. Metadata chunks have
fixed lengths and must occur once before PLTE/IDAT. Lower-priority valid
declarations do not change a higher-priority profile.

Incomplete metadata requires an application policy. `PngColorDefaults` defaults
to an image gamma of `1 / 2.2` and D65/Rec.709 chromaticities. These assumptions
fill only missing values when at least one effective gAMA/cHRM declaration is
present. They do not invent a profile for wholly untagged images. An application
can supply another policy explicitly:

```php
use PDF\Images\PngChromaticities;
use PDF\Images\PngColorDefaults;
use PDF\Images\PngImageDecoder;

$decoder = new PngImageDecoder(colorDefaults: new PngColorDefaults(
    imageGamma: 0.5,
    chromaticities: new PngChromaticities(),
));
```

The `sRGB` values 0, 1, 2 and 3 select Perceptual, RelativeColorimetric,
Saturation and AbsoluteColorimetric respectively. The returned
`SampledImage::$intent` is propagated by the core factory. An explicit image
`/Intent` supplied through core options takes precedence. This propagation
requires core 0.12 or newer. An embedded iCCP profile remains byte-for-byte
unchanged, including its own header; it does not inherit a lower-priority sRGB
intent.

`PngSrgbProfile` embeds two small LittleCMS-generated profiles: RGB (588 bytes)
and grayscale (364 bytes). Their transfer curves include sRGB's linear segment;
they are not a gamma-2.2 approximation. `tools/build_srgb_profiles.py` recreates
them using LittleCMS 2.19. Creation dates and optional profile identifiers are
normalized; the per-image header intent is the only patched field. Their
embedded copyright notice permits unrestricted use. No external profile file,
CMS library or native extension is needed in production. Profile sizes, tag
counts and both decoding/patch buffers consume the normal import limits.

The implementation follows [PNG Third Edition](https://www.w3.org/TR/2025/REC-png-3-20250624/),
§4.3/table 1, §§11.3.2.1–3, §11.3.2.5 and §§13.13–14. The two compatibility
corrections also follow §11.3.1.1 (mask unused high transparency-key bits) and
§11.2.3 (ignore unused bytes after the last IDAT zlib stream). PDF mappings use
the retained `ISO_32000-2_sponsored_EC3.pdf`, physical pages 201–203, tables 62–63
for CalGray/CalRGB, and pages 206–208 for ICCBased. Unsupported animation and
HDR declarations remain explicit errors; this is not a claim of all PNG 3
features.

## Independent validation

`PngColorMetadataTest` checks precedence, matrix values, all four intents,
invalid declarations, limits and reuse of a decoder without state leakage.
`tools/validate_png_color.py` checks 14 original fixtures, alongside the 35
depth/Adam7 fixtures in `tools/validate_png.py`:

- Pillow and ImageMagick/libpng independently decode the input samples.
- qpdf checks PDF structure; pypdf compares all colour/alpha bytes at their
  original precision, ICC provenance, XYZ/gamma declarations and `/Intent`.
- LittleCMS builds reference transforms independently from the source
  declarations. Poppler-rendered pixel centres differ by at most three levels
  per eight-bit channel, using NOOPTIMIZE and BLACKPOINTCOMPENSATION.

The Poppler Splash path reduces 16-bit image samples to their high byte before
colour conversion, as implemented by
[`ImageStream::getLine`](https://gitlab.freedesktop.org/poppler/poppler/-/blob/master/poppler/Stream.cc).
The rendering reference uses the same input precision. Full 16-bit retention is
proven separately by byte comparisons, not inferred from an eight-bit screen
image. LittleCMS and Poppler are development oracles, not PHP dependencies.

```sh
python tools/validate_png_color.py --report _build/png-colour.json
```

See the [complete contracts and budgets](architecture.md).
