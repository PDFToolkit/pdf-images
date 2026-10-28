"""Generate deterministic sRGB/gray profiles with LittleCMS; PHP embeds them without runtime I/O."""
from pathlib import Path
import argparse
import base64
import ctypes
import ctypes.util
import hashlib
import json
import re
import struct

ROOT = Path(__file__).resolve().parents[1]


def profile(gray):
    lib = ctypes.CDLL(ctypes.util.find_library('lcms2'))
    lib.cmsCreate_sRGBProfile.restype = ctypes.c_void_p
    lib.cmsSaveProfileToMem.argtypes = [ctypes.c_void_p, ctypes.c_void_p, ctypes.POINTER(ctypes.c_uint32)]
    lib.cmsCloseProfile.argtypes = [ctypes.c_void_p]
    if gray:
        class White(ctypes.Structure):
            _fields_ = [('x', ctypes.c_double), ('y', ctypes.c_double), ('Y', ctypes.c_double)]
        lib.cmsBuildParametricToneCurve.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.POINTER(ctypes.c_double)]
        lib.cmsBuildParametricToneCurve.restype = ctypes.c_void_p
        lib.cmsCreateGrayProfile.argtypes = [ctypes.POINTER(White), ctypes.c_void_p]
        lib.cmsCreateGrayProfile.restype = ctypes.c_void_p
        lib.cmsFreeToneCurve.argtypes = [ctypes.c_void_p]
        params = (ctypes.c_double * 5)(2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045)
        curve = lib.cmsBuildParametricToneCurve(None, 4, params)
        handle = lib.cmsCreateGrayProfile(ctypes.byref(White(0.3127, 0.3290, 1.0)), curve)
        lib.cmsFreeToneCurve(curve)
    else:
        handle = lib.cmsCreate_sRGBProfile()
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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    path = ROOT / 'src/PngSrgbProfile.php'
    source = path.read_text()
    manifest = {}
    for name, gray in [('RGB', False), ('GRAY', True)]:
        data = profile(gray)
        encoded = base64.b64encode(data).decode()
        pattern = rf"private const {name} = '[^']*';"
        if args.check:
            assert re.search(pattern, source).group() == f"private const {name} = '{encoded}';", name
        else:
            source = re.sub(pattern, f"private const {name} = '{encoded}';", source)
        manifest[name] = {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}
    if not args.check:
        path.write_text(source)
    print(json.dumps(manifest, indent=2))


if __name__ == '__main__':
    main()
