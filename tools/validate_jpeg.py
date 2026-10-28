"""Validate preserved JPEG/ICC bytes, PDF colour contracts and rendered pixels with independent engines."""
from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile

from PIL import Image, ImageCms
from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, DecodedStreamObject, DictionaryObject, NameObject, NumberObject

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = ROOT / 'tests/Fixtures/jpeg'


def run(command):
    result = subprocess.run(list(map(str, command)), capture_output=True)
    assert result.returncode == 0, result.stdout.decode(errors='replace') + result.stderr.decode(errors='replace')
    return result.stdout


def reference_pdf(path, data, case, profile):
    writer = PdfWriter()
    page = writer.add_blank_page(case['width'], case['height'])
    image = DecodedStreamObject()
    image.set_data(data)
    image.update({NameObject('/Type'):NameObject('/XObject'), NameObject('/Subtype'):NameObject('/Image'),
        NameObject('/Width'):NumberObject(case['width']), NameObject('/Height'):NumberObject(case['height']),
        NameObject('/BitsPerComponent'):NumberObject(8), NameObject('/Filter'):NameObject('/DCTDecode'),
        NameObject('/DecodeParms'):DictionaryObject({NameObject('/ColorTransform'):NumberObject(case['transform'])})})
    if profile:
        icc = DecodedStreamObject(); icc.set_data(profile)
        icc[NameObject('/N')] = NumberObject(case['components'])
        space = ArrayObject([NameObject('/ICCBased'), writer._add_object(icc)])
    else:
        space = NameObject('/DeviceCMYK')
    image[NameObject('/ColorSpace')] = space
    if case['inverted']:
        image[NameObject('/Decode')] = ArrayObject([NumberObject(n) for n in (1,0)*4])
    page[NameObject('/Resources')] = DictionaryObject({NameObject('/XObject'):DictionaryObject({NameObject('/Image'):writer._add_object(image)})})
    content = DecodedStreamObject(); content.set_data(f'{case["width"]} 0 0 {case["height"]} 0 0 cm /Image Do'.encode())
    page[NameObject('/Contents')] = writer._add_object(content)
    writer.write(path)


def raster(path, args):
    prefix = path.with_suffix('')
    run([args.pdftoppm, '-r', '72', '-singlefile', '-png', path, prefix])
    return Image.open(prefix.with_suffix('.png')).convert('RGB')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--bootstrap', type=Path)
    parser.add_argument('--qpdf', default='qpdf')
    parser.add_argument('--pdftoppm', default='pdftoppm')
    parser.add_argument('--work-dir', type=Path)
    parser.add_argument('--report', type=Path)
    args = parser.parse_args()
    # Remains inside the checkout for PHP container wrappers; callers can provide an explicit directory.
    (ROOT/'_build').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='jpeg-', dir=ROOT/'_build') as temporary:
        directory = (args.work_dir or Path(temporary)).absolute()
        directory.mkdir(parents=True, exist_ok=True)
        php = [args.php]
        if args.bootstrap:
            php += ['-d', 'auto_prepend_file='+str(args.bootstrap.absolute())]
        actual = json.loads(run([*php, ROOT/'tools/report-jpeg.php', directory]))['cases']
        manifest = json.loads((FIXTURES/'manifest.json').read_text())
        reports = []
        for name, case in manifest['cases'].items():
            data = (FIXTURES/(name+'.jpg')).read_bytes()
            source = Image.open(io.BytesIO(data)); source.load()
            profile = source.info.get('icc_profile')
            assert hashlib.sha256(data).hexdigest() == case['sha256'] == actual[name]['encodedSha256']
            path = directory/(name+'.pdf')
            run([args.qpdf, '--suppress-recovery', '--check', path])
            reader = PdfReader(path)
            stream = reader.pages[0]['/Resources']['/XObject']['/Image']
            assert stream._data == data
            assert stream['/Filter'] == '/DCTDecode'
            assert stream['/DecodeParms']['/ColorTransform'] == case['transform'] == actual[name]['transform']
            assert actual[name]['inverted'] == case['inverted']
            assert (list(stream['/Decode']) if '/Decode' in stream else None) == ([1,0]*4 if case['inverted'] else None)
            if profile:
                assert stream['/ColorSpace'][0] == '/ICCBased'
                icc = stream['/ColorSpace'][1].get_object()
                assert icc['/N'] == case['components']
                assert icc.get_data() == profile
                assert hashlib.sha256(profile).hexdigest() == case['profileSha256'] == actual[name]['profileSha256']
            else:
                assert stream['/ColorSpace'] == '/DeviceCMYK'
            reference = directory/(name+'-reference.pdf')
            reference_pdf(reference, data, case, profile)
            pixels = raster(path,args)
            independent = raster(reference,args)
            assert pixels.size == (case['width'],case['height'])
            assert pixels.tobytes() == independent.tobytes(), name+' differs from independent PDF producer'
            maximum = None
            checked_pixels = 0
            if profile:
                # Match Poppler's declared LCMS_FLAGS: avoid the approximate
                # precalculated CLUT and enable black-point compensation.
                transform = ImageCms.buildTransform(ImageCms.ImageCmsProfile(io.BytesIO(profile)),
                    ImageCms.createProfile('sRGB'), source.mode, 'RGB', renderingIntent=1,
                    flags=ImageCms.Flags.NOOPTIMIZE | ImageCms.Flags.BLACKPOINTCOMPENSATION)
                expected = ImageCms.applyTransform(source, transform)
                # Poppler's image resampling smooths sharp JPEG motif boundaries. Compare
                # colour management on independently detected flat interiors, while the full
                # raster above must still exactly match the independent PDF producer.
                differences = []
                for y in range(2, expected.height-2):
                    for x in range(2, expected.width-2):
                        center = expected.getpixel((x,y))
                        neighbors = [expected.getpixel((x+dx,y+dy)) for dx in range(-2,3) for dy in range(-2,3)]
                        if max(abs(a-b) for neighbor in neighbors for a,b in zip(center,neighbor)) <= 2:
                            observed = pixels.getpixel((x,y))
                            differences.extend(abs(a-b) for a,b in zip(observed,center))
                            assert max(abs(a-b) for a,b in zip(observed,center)) <= 3, (name,x,y,observed,center)
                            checked_pixels += 1
                assert checked_pixels >= 12, (name,checked_pixels)
                maximum = max(differences)
            reports.append({'file':name+'.pdf','jpegBytesPreserved':True,'iccBytesPreserved':bool(profile),
                'independentPdfPixelsExact':True,'flatInteriorPixelsCompared':checked_pixels,'maxInteriorDifferenceFromPillowLittleCms':maximum})
        report = {'cases':reports,'producers':['Pillow/libjpeg','ImageMagick/libjpeg','pypdf'],
            'validators':['qpdf','pypdf','Poppler','Pillow/LittleCMS'],
            'note':'CMYK profile is an original synthetic fixture, not a calibrated printer profile.'}
        if args.report:
            args.report.write_text(json.dumps(report,indent=2)+'\n')
        print(json.dumps(report,indent=2))


if __name__ == '__main__':
    main()
