"""Independently inspect JPEG bytes and every synthetic PNG colour/alpha sample with pypdf."""

import argparse
from pathlib import Path

from pypdf import PdfReader


ROOT = Path(__file__).resolve().parents[1]
WIDTH, HEIGHT = 48, 36
PALETTE = [(244, 93, 80), (255, 199, 93), (21, 167, 150), (61, 128, 220)]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def validate(path):
    reader = PdfReader(path, strict=True)
    require(len(reader.pages) == 1, "Image example must have exactly one page")
    images = reader.pages[0]["/Resources"]["/XObject"]
    require(set(images) == {"/JpegRGB", "/JpegCMYK", "/PngRGBA", "/PngPalette"}, "Example image resources changed")
    for name, fixture, space, transform in [("/JpegRGB", "rgb.jpg", "/DeviceRGB", 1),
                                             ("/JpegCMYK", "cmyk.jpg", "/DeviceCMYK", 0)]:
        image = images[name]
        require(image["/Subtype"] == "/Image" and image["/Width"] == WIDTH and image["/Height"] == HEIGHT,
                "Incorrect JPEG dimensions/subtype")
        require(image["/Filter"] == "/DCTDecode" and image["/ColorSpace"] == space and image["/BitsPerComponent"] == 8,
                "Incorrect JPEG filter, colour space or depth")
        require(image["/DecodeParms"]["/ColorTransform"] == transform, "Incorrect JPEG colour transform")
        require(image.get_data() == (ROOT / "examples/assets/images" / fixture).read_bytes(), "JPEG bytes changed")
    require(list(images["/JpegCMYK"]["/Decode"]) == [1, 0] * 4, "Adobe CMYK inversion is missing")
    rgb, rgba_alpha, palette_alpha = bytearray(), bytearray(), bytearray()
    for y in range(HEIGHT):
        for x in range(WIDTH):
            index = (x // 12 + y // 9) % 4
            rgb.extend(PALETTE[index])
            rgba_alpha.append(round(255 * x / (WIDTH - 1)))
            palette_alpha.append([0, 85, 170, 255][index])
    for name, expected_alpha in [("/PngRGBA", rgba_alpha), ("/PngPalette", palette_alpha)]:
        image = images[name]
        require(image["/Subtype"] == "/Image" and image["/Width"] == WIDTH and image["/Height"] == HEIGHT,
                "Incorrect PNG dimensions/subtype")
        require(image["/Filter"] == "/FlateDecode" and image["/ColorSpace"] == "/DeviceRGB" and image["/BitsPerComponent"] == 8,
                "Incorrect PNG filter, colour space or depth")
        require(image.get_data() == bytes(rgb), "PNG RGB samples differ from the original synthetic motif")
        mask = image["/SMask"]
        require(mask["/Subtype"] == "/Image" and mask["/Width"] == WIDTH and mask["/Height"] == HEIGHT,
                "Alpha dimensions/subtype differ")
        require(mask["/Filter"] == "/FlateDecode" and mask["/ColorSpace"] == "/DeviceGray" and mask["/BitsPerComponent"] == 8,
                "Incorrect alpha filter, colour space or depth")
        require(mask.get_data() == bytes(expected_alpha), "PNG alpha samples differ from the original motif")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pdf", type=Path)
    validate(parser.parse_args().pdf)
    print("Independent image checks passed: intact JPEG bytes, CMYK/YCbCr interpretation, every PNG RGB and alpha sample.")
