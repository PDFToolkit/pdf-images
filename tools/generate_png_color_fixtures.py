"""Original PNG colour declarations with independent LittleCMS reference profiles."""
import argparse
import base64
import ctypes
import ctypes.util
import hashlib
import json
from pathlib import Path
import struct

from generate_png_fixtures import chunk, make_case, profile
from build_srgb_profiles import profile as srgb_profile

ROOT = Path(__file__).resolve().parents[1]
STANDARD = [31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000]
ADOBE = [31270, 32900, 64000, 33000, 21000, 71000, 15000, 6000]


def calibrated_profile(gray, gamma, chromaticities):
    lib = ctypes.CDLL(ctypes.util.find_library('lcms2'))
    class xyY(ctypes.Structure):
        _fields_ = [('x', ctypes.c_double), ('y', ctypes.c_double), ('Y', ctypes.c_double)]
    class Primaries(ctypes.Structure):
        _fields_ = [('red', xyY), ('green', xyY), ('blue', xyY)]
    lib.cmsBuildGamma.argtypes = [ctypes.c_void_p, ctypes.c_double]
    lib.cmsBuildGamma.restype = ctypes.c_void_p
    lib.cmsFreeToneCurve.argtypes = [ctypes.c_void_p]
    lib.cmsCreateGrayProfile.argtypes = [ctypes.POINTER(xyY), ctypes.c_void_p]
    lib.cmsCreateGrayProfile.restype = ctypes.c_void_p
    lib.cmsCreateRGBProfile.argtypes = [ctypes.POINTER(xyY), ctypes.POINTER(Primaries), ctypes.POINTER(ctypes.c_void_p)]
    lib.cmsCreateRGBProfile.restype = ctypes.c_void_p
    lib.cmsSaveProfileToMem.argtypes = [ctypes.c_void_p, ctypes.c_void_p, ctypes.POINTER(ctypes.c_uint32)]
    lib.cmsCloseProfile.argtypes = [ctypes.c_void_p]
    xy = [xyY(chromaticities[i] / 100000, chromaticities[i + 1] / 100000, 1) for i in range(0, 8, 2)]
    curve = lib.cmsBuildGamma(None, 1 / gamma)
    if gray:
        handle = lib.cmsCreateGrayProfile(ctypes.byref(xy[0]), curve)
    else:
        curves = (ctypes.c_void_p * 3)(curve, curve, curve)
        handle = lib.cmsCreateRGBProfile(ctypes.byref(xy[0]), ctypes.byref(Primaries(*xy[1:])), curves)
    lib.cmsFreeToneCurve(curve)
    assert handle
    size = ctypes.c_uint32()
    assert lib.cmsSaveProfileToMem(handle, None, ctypes.byref(size))
    buffer = ctypes.create_string_buffer(size.value)
    assert lib.cmsSaveProfileToMem(handle, buffer, ctypes.byref(size))
    lib.cmsCloseProfile(handle)
    data = bytearray(buffer.raw)
    data[24:36] = struct.pack('>6H', 2026, 10, 28, 0, 0, 0)
    data[84:100] = b'\0' * 16
    return bytes(data)


def generate():
    cases = {}
    for name, kind, depth, gamma, chroma, intent, icc in [
        ('rgb-gamma-linear', 2, 8, 100000, None, None, None),
        ('rgb16-gamma-half', 2, 16, 50000, None, None, None),
        ('gray-gamma-half', 0, 8, 50000, None, None, None),
        ('gray16-gamma-linear', 0, 16, 100000, None, None, None),
        ('rgb-adobe-chrm-gamma', 2, 8, 45455, ADOBE, None, None),
        ('rgb16-adobe-chrm', 2, 16, None, ADOBE, None, None),
        ('indexed-chrm-gamma', 3, 4, 50000, STANDARD, None, None),
        ('rgba-chrm-gamma', 6, 8, 50000, STANDARD, None, None),
        ('rgb-srgb', 2, 8, None, None, 0, None),
        ('rgb16-srgb', 2, 16, 100000, ADOBE, 1, None),
        ('gray-srgb', 0, 8, None, None, 2, None),
        ('gray16-srgb', 0, 16, None, None, 3, None),
        ('rgb-icc-priority', 2, 8, 100000, ADOBE, 2, profile()),
        ('rgb-zero-gamma', 2, 8, 0, None, None, None),
    ]:
        png, record = make_case(kind, depth, 1, icc=icc)
        metadata = b''
        if gamma is not None:
            metadata += chunk('gAMA', struct.pack('>I', gamma))
        if chroma is not None:
            metadata += chunk('cHRM', struct.pack('>8I', *chroma))
        if intent is not None:
            metadata += chunk('sRGB', bytes([intent]))
        png = png[:33] + metadata + png[33:]
        gray = record['components'] == 1
        if icc:
            reference = icc
            family = 'ICCBased'
        elif intent is not None:
            reference = srgb_profile(gray)
            family = 'ICCBased'
        elif gamma or chroma:
            reference = calibrated_profile(gray, gamma / 100000 if gamma else 1 / 2.2, chroma or STANDARD)
            family = 'CalGray' if gray else 'CalRGB'
        else:
            reference = srgb_profile(False)
            family = 'DeviceRGB'
        record.update(sha256=hashlib.sha256(png).hexdigest(), family=family, intent=None if icc or intent is None else ['Perceptual', 'RelativeColorimetric', 'Saturation', 'AbsoluteColorimetric'][intent], gamma=gamma, chromaticities=chroma, referenceProfile=base64.b64encode(reference).decode())
        cases[name] = (png, record)
    return cases


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    destination = ROOT / 'tests/Fixtures/png-color'
    destination.mkdir(parents=True, exist_ok=True)
    records = {}
    for name, (png, record) in generate().items():
        path = destination / (name + '.png')
        if args.check:
            assert path.read_bytes() == png, name
        else:
            path.write_bytes(png)
        records[name] = record
    manifest = json.dumps({'schema': 1, 'generator': 'tools/generate_png_color_fixtures.py', 'cases': records}, indent=2) + '\n'
    path = destination / 'manifest.json'
    if args.check:
        assert path.read_text() == manifest
    else:
        path.write_text(manifest)
    print(f'Validated {len(records)} deterministic PNG colour fixtures')


if __name__ == '__main__':
    main()
