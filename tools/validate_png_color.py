"""Check PNG colour declarations, exact samples/profiles and independent LittleCMS/Poppler output."""
from __future__ import annotations
import argparse
import base64
import ctypes
import ctypes.util
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

import PIL
from PIL import Image, ImageCms
from pypdf import PdfReader

from validate_png import require, unpack_rows, reference_image, libpng_samples

ROOT = Path(__file__).resolve().parents[1]
STANDARD = [31270, 32900, 64000, 33000, 30000, 60000, 15000, 6000]


def reference_transform(profile, samples, depth, intent, flags):
    """Keep 16-bit linear/dark samples until colour conversion, rather than first quantizing to RGB8."""
    lib = ctypes.CDLL(ctypes.util.find_library('lcms2'))
    lib.cmsOpenProfileFromMem.argtypes = [ctypes.c_void_p, ctypes.c_uint32]
    lib.cmsOpenProfileFromMem.restype = ctypes.c_void_p
    lib.cmsCreate_sRGBProfile.restype = ctypes.c_void_p
    lib.cmsCreateTransform.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_void_p, ctypes.c_uint32, ctypes.c_uint32, ctypes.c_uint32]
    lib.cmsCreateTransform.restype = ctypes.c_void_p
    lib.cmsDoTransform.argtypes = [ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_uint32]
    lib.cmsDeleteTransform.argtypes = [ctypes.c_void_p]
    lib.cmsCloseProfile.argtypes = [ctypes.c_void_p]
    components = len(samples[0][0])
    width, height = len(samples[0]), len(samples)
    maximum = (1 << depth) - 1
    values = [round(value * 65535 / maximum) for row in samples for pixel in row for value in pixel]
    data = (ctypes.c_uint16 * len(values))(*values)
    output = ctypes.create_string_buffer(width * height * 3)
    source_profile = lib.cmsOpenProfileFromMem(profile, len(profile))
    target_profile = lib.cmsCreate_sRGBProfile()
    source_format = ((3 if components == 1 else 4) << 16) | (components << 3) | 2
    transform = lib.cmsCreateTransform(source_profile, source_format, target_profile, (4 << 16) | (3 << 3) | 1, intent, int(flags))
    require(bool(transform), 'LittleCMS could not create the independent reference transform')
    try:
        lib.cmsDoTransform(transform, data, output, width * height)
    finally:
        lib.cmsDeleteTransform(transform)
        lib.cmsCloseProfile(source_profile)
        lib.cmsCloseProfile(target_profile)
    return Image.frombytes('RGB', (width, height), output.raw)


def calibration(space, record, name):
    parameters = space[1]
    xy = [value / 100000 for value in (record['chromaticities'] or STANDARD)]
    white = [xy[0] / xy[1], 1, (1 - xy[0] - xy[1]) / xy[1]]
    require(max(abs(float(a) - b) for a, b in zip(parameters['/WhitePoint'], white)) < 1e-8, name + ': calibrated white point')
    gamma = 100000 / record['gamma'] if record['gamma'] else 2.2
    actual = parameters['/Gamma']
    actual = [actual] if record['components'] == 1 else actual
    require(max(abs(float(value) - gamma) for value in actual) < 1e-8, name + ': inverse image gamma')
    if record['components'] == 3:
        matrix = [float(value) for value in parameters['/Matrix']]
        # Recover source xy coordinates from PDF XYZ columns; independent of the PHP solver.
        for index in range(3):
            column = matrix[index * 3:index * 3 + 3]
            require(max(abs(column[j] / sum(column) - xy[2 + index * 2 + j]) for j in range(2)) < 1e-8, name + ': source primary xy')
        require(max(abs(sum(matrix[i::3]) - white[i]) for i in range(3)) < 1e-8, name + ': RGB white normalization')


def validate(output, php, pdftoppm, qpdf, imagemagick):
    fixtures = ROOT / 'tests/Fixtures/png-color'
    manifest = json.loads((fixtures / 'manifest.json').read_text())
    output.mkdir(parents=True, exist_ok=True)
    completed = subprocess.run([php, str(ROOT / 'tools/report-png.php'), str(output), str(fixtures)], cwd=ROOT, check=True, capture_output=True, text=True, timeout=90)
    native = json.loads(completed.stdout)['cases']
    (output / 'native.json').write_text(completed.stdout)
    checks = []
    flags = ImageCms.Flags.NOOPTIMIZE | ImageCms.Flags.BLACKPOINTCOMPENSATION
    system_cms_version = ctypes.CDLL(ctypes.util.find_library('lcms2')).cmsGetEncodedCMMversion()
    for name, record in manifest['cases'].items():
        path = fixtures / (name + '.png')
        require(hashlib.sha256(path.read_bytes()).hexdigest() == record['sha256'], name + ': source hash')
        for key in ['samples', 'alpha', 'colorDepth', 'alphaDepth', 'family', 'intent']:
            require(native[name][key] == record[key], name + ': native ' + key)
        pdf = output / (name + '.pdf')
        subprocess.run([qpdf, '--check', str(pdf)], check=True, capture_output=True, timeout=30)
        reader = PdfReader(pdf, strict=True)
        require(len(reader.pages) == 1, name + ': page count')
        image = reader.pages[0]['/Resources']['/XObject']['/Image']
        raw = base64.b64decode(record['samples'])
        require(image.get_data() == raw and image['/BitsPerComponent'] == record['colorDepth'], name + ': PDF samples/precision')
        samples = unpack_rows(raw, record['width'], record['height'], record['components'], record['colorDepth'])
        space = image['/ColorSpace']
        require((space if isinstance(space, str) else space[0]) == '/' + record['family'], name + ': PDF colour family')
        require(image.get('/Intent') == (None if record['intent'] is None else '/' + record['intent']), name + ': PDF rendering intent')
        profile = base64.b64decode(record['referenceProfile'])
        if record['family'] == 'ICCBased':
            embedded = space[1].get_object()
            require(embedded['/N'] == record['components'], name + ': profile components')
            retained = embedded.get_data()
            if record['profileSha256']:
                source_profile = Image.open(path).info['icc_profile']
                require(retained == source_profile, name + ': exact input ICC provenance')
            else:
                require(retained[:64] + retained[68:] == profile[:64] + profile[68:], name + ': generated sRGB profile')
                intent = ['Perceptual', 'RelativeColorimetric', 'Saturation', 'AbsoluteColorimetric'].index(record['intent'])
                require(int.from_bytes(retained[64:68], 'big') == intent, name + ': profile rendering intent')
            require(hashlib.sha256(retained).hexdigest() == native[name]['profileSha256'], name + ': PDF profile bytes')
        elif record['family'].startswith('Cal'):
            calibration(space, record, name)
        alpha = None
        if record['alpha'] is not None:
            mask = image['/SMask']
            raw_alpha = base64.b64decode(record['alpha'])
            require(mask.get_data() == raw_alpha and mask['/BitsPerComponent'] == record['alphaDepth'], name + ': opacity samples/precision')
            alpha = unpack_rows(raw_alpha, record['width'], record['height'], 1, record['alphaDepth'])
        else:
            require('/SMask' not in image, name + ': unexpected opacity')
        libpng_samples(imagemagick, path, samples, record['colorDepth'], raw_color=True)
        if alpha is not None:
            libpng_samples(imagemagick, path, alpha, record['alphaDepth'], alpha=True, raw_color=True)
        reference_image(path, {**record, 'profileSha256': None}, samples, None)
        intent = 1 if record['intent'] is None else ['Perceptual', 'RelativeColorimetric', 'Saturation', 'AbsoluteColorimetric'].index(record['intent'])
        # Poppler's Splash image path converts 16-bit PDF samples to their high byte before CMS.
        # Exact full precision is checked above in both the native result and pypdf stream.
        rendering_samples = [[[value >> 8 for value in pixel] for pixel in row] for row in samples] if record['colorDepth'] == 16 else samples
        reference = reference_transform(profile, rendering_samples, 8 if record['colorDepth'] == 16 else record['colorDepth'], intent, flags)
        if alpha is not None:
            opacity = Image.new('L', reference.size)
            maximum = (1 << record['alphaDepth']) - 1
            opacity.putdata([round(pixel[0] * 255 / maximum) for row in alpha for pixel in row])
            reference = Image.composite(reference, Image.new('RGB', reference.size, 'white'), opacity)
        prefix = output / (name + '-render')
        subprocess.run([pdftoppm, '-singlefile', '-r', '720', '-png', str(pdf), str(prefix)], check=True, capture_output=True, timeout=30)
        rendered = Image.open(prefix.with_suffix('.png')).convert('RGB')
        require(rendered.size == (record['width'] * 10, record['height'] * 10), name + ': render dimensions')
        difference = max(abs(a - b) for y in range(record['height']) for x in range(record['width']) for a, b in zip(rendered.getpixel((x * 10 + 5, y * 10 + 5)), reference.getpixel((x, y))))
        require(difference <= 3, name + f': rendered difference {difference} exceeds three levels per channel')
        checks.append({'case': name, 'family': record['family'], 'intent': record['intent'], 'samples': 'exact', 'inputProfile': 'exact' if record['profileSha256'] else None, 'maximumRenderChannelDifference': difference})
    return {'schema': 1, 'cases': len(checks), 'pillowVersion': PIL.__version__, 'pillowLittleCmsVersion': ImageCms.core.littlecms_version, 'systemReferenceLittleCmsVersion': system_cms_version, 'referenceFlags': ['NOOPTIMIZE', 'BLACKPOINTCOMPENSATION'], 'renderPrecision': 'Poppler Splash uses the high eight bits of 16-bit samples before colour conversion; native and PDF full-precision samples are checked separately.', 'checksPerCase': ['source hash', 'exact native/PDF samples and opacity', 'qpdf structure', 'pypdf colour space and rendering intent', 'source ICC provenance or calibrated XYZ/gamma', 'Pillow/libpng source samples', 'independent LittleCMS profile rendering against Poppler'], 'checks': checks}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--imagemagick', default='convert')
    parser.add_argument('--output', type=Path)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    (ROOT / '_build').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='png-color-', dir=ROOT / '_build') as temporary:
        report = validate(args.output or Path(temporary), args.php, args.pdftoppm, args.qpdf, args.imagemagick)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2) + '\n')
    print(f"Independent PNG colour checks passed: {report['cases']} cases, exact colour declarations and bounded render differences.")


if __name__ == '__main__':
    main()
