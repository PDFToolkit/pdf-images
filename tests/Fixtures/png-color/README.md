# PNG colour fixtures

English | [Français](README.fr.md)

Fourteen original Adam7 images exercise gray/RGB 8/16-bit samples, indexed
colour, alpha, gAMA/cHRM, all four sRGB intents, ICC priority and ignored zero
gamma. `manifest.json` records input hashes, exact colour/alpha bytes and
independent LittleCMS reference profiles. These reference profiles are not
inserted into the source PNGs, except for the explicit iCCP priority fixture.

`tools/generate_png_color_fixtures.py` creates the inputs using an independent
Python encoder. LittleCMS 2.19 creates the reference profiles directly from
chromaticities and transfer curves. Dates/IDs are normalized. Regeneration
with a different LittleCMS version can change profile metadata; inspect that
change before refreshing expected hashes. `--check` checks exact reproduction.
The synthetic images and generated profiles use the repository MIT licence;
the profiles themselves carry LittleCMS's unrestricted-use copyright notice.

`tools/validate_png_color.py` independently verifies PNG samples, calibrated
PDF parameters, exact input ICC provenance and Poppler rendering. Its explicit
eight-bit rendering-precision limitation is explained in
[the colour guide](../../../docs/png-colour.md).
