"""Regenerate the original synthetic image fixtures (development-only Pillow)."""

from pathlib import Path
from PIL import Image

DESTINATION = Path(__file__).resolve().parent
SIZE = (48, 36)
rgb = Image.new("RGB", SIZE)
rgba = Image.new("RGBA", SIZE)
gray = Image.new("L", SIZE)
gray_alpha = Image.new("LA", SIZE)
indexed = Image.new("P", SIZE)
palette = [244, 93, 80, 255, 199, 93, 21, 167, 150, 61, 128, 220]
indexed.putpalette(palette + [0] * (768 - len(palette)))
for y in range(SIZE[1]):
    for x in range(SIZE[0]):
        index = (x // 12 + y // 9) % 4
        color = tuple(palette[index * 3 : index * 3 + 3])
        rgb.putpixel((x, y), color)
        rgba.putpixel((x, y), color + (round(255 * x / (SIZE[0] - 1)),))
        value = round(255 * (x + y) / (SIZE[0] + SIZE[1] - 2))
        gray.putpixel((x, y), value)
        gray_alpha.putpixel((x, y), (value, round(255 * y / (SIZE[1] - 1))))
        indexed.putpixel((x, y), index)
rgb.save(DESTINATION / "rgb.png")
rgba.save(DESTINATION / "rgba.png")
gray.save(DESTINATION / "gray.png")
gray_alpha.save(DESTINATION / "gray-alpha.png")
indexed.save(DESTINATION / "palette.png", transparency=bytes([0, 85, 170, 255]), bits=8)
rgb.save(DESTINATION / "rgb.jpg", quality=95, subsampling=0)
rgb.save(DESTINATION / "progressive.jpg", quality=95, subsampling=0, progressive=True)
gray.save(DESTINATION / "gray.jpg", quality=95)
rgb.convert("CMYK").save(DESTINATION / "cmyk.jpg", quality=95)
