# Synthetic images

English | [Français](README.fr.md)

These patterns were created for this repository: no photograph, logo or external
image is used. They are distributed under the same MIT licence as the code.
`generate.py` rebuilds them with Pillow, used only during development; the PHP
library does not depend on it.

- `rgb.png` and `rgb.jpg`: original 48 × 36 pixel pattern with four colours.
- `progressive.jpg`: same pattern with progressive JPEG encoding.
- `gray.png` and `gray.jpg`: diagonal greyscale ramp.
- `rgba.png`: RGB pattern with opacity from left to right.
- `gray-alpha.png`: greyscale ramp with opacity from top to bottom.
- `palette.png`: four palette indices with opacity values 0, 85, 170 and 255.
- `cmyk.jpg`: CMYK conversion of the pattern, with the Pillow codec's Adobe marker.

JPEG pixel differences are expected: its DCT encoding is lossy.
