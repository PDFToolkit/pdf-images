"""Original JPEG motifs encoded by Pillow/libjpeg and ImageMagick; original deterministic ICC profiles."""
from __future__ import annotations

import argparse
import hashlib
import io
import itertools
import json
from pathlib import Path
import struct
import subprocess

from PIL import Image, ImageCms, features

ROOT = Path(__file__).resolve().parents[1]


def cmyk_profile():
    """A small original ICC v2 input profile: four-channel CMYK LUT to D50 XYZ, no printer calibration claim."""
    def fixed(value):
        return struct.pack('>i', round(value * 65536))
    white = (0.9642, 1.0, 0.8249)
    lut = b'mft2' + bytes(4) + bytes([4, 3, 2, 0])
    lut += b''.join(fixed(1.0 if i in (0, 4, 8) else 0.0) for i in range(9))
    lut += struct.pack('>HH', 2, 2) + struct.pack('>HH', 0, 65535) * 4
    for cyan, magenta, yellow, black in itertools.product((0.0, 1.0), repeat=4):
        rgb = ((1-cyan)*(1-black), (1-magenta)*(1-black), (1-yellow)*(1-black))
        for row in ((.4360747, .3850649, .1430804), (.2225045, .7168786, .0606169), (.0139322, .0971045, .7141733)):
            lut += struct.pack('>H', round(sum(a*b for a,b in zip(row, rgb)) * 32768))
    lut += struct.pack('>HH', 0, 65535) * 3
    description = b'PDFToolkit original synthetic CMYK fixture\0'
    tags = {
        b'desc': b'desc' + bytes(4) + struct.pack('>I', len(description)) + description + bytes(4+4+2+1+67),
        b'cprt': b'text' + bytes(4) + b'Original PDFToolkit fixture, MIT license.\0',
        b'wtpt': b'XYZ ' + bytes(4) + b''.join(fixed(x) for x in white),
        b'A2B0': lut,
    }
    header = bytearray(128)
    header[8:12] = bytes([2, 0x40, 0, 0])
    header[12:24] = b'scnrCMYKXYZ '
    header[24:36] = struct.pack('>6H', 2026, 10, 28, 0, 0, 0)
    header[36:40] = b'acsp'
    header[68:80] = b''.join(fixed(x) for x in white)
    header[80:84] = b'PDTK'
    offset = 132 + len(tags)*12
    table, body = bytearray(), bytearray()
    for signature, data in tags.items():
        table += signature + struct.pack('>II', offset + len(body), len(data))
        body += data
        body += bytes((-len(body)) % 4)
    result = header + struct.pack('>I', len(tags)) + table + body
    result[0:4] = struct.pack('>I', len(result))
    # LittleCMS independently accepts the profile and builds its actual CMYK -> sRGB transform.
    profile = ImageCms.ImageCmsProfile(io.BytesIO(result))
    ImageCms.buildTransform(profile, ImageCms.createProfile('sRGB'), 'CMYK', 'RGB')
    return bytes(result)


def embed_parts(jpeg, profile, order=(1, 2, 3)):
    parts = [profile[:100], profile[100:200], profile[200:]]
    markers = b''
    for index in order:
        data = b'ICC_PROFILE\0' + bytes([index, len(parts)]) + parts[index-1]
        markers += b'\xff\xe2' + struct.pack('>H', len(data)+2) + data
    return jpeg[:2] + markers + jpeg[2:]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=ROOT / 'tests/Fixtures/jpeg')
    parser.add_argument('--imagemagick', default='magick')
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    rgb = Image.new('RGB', (48, 32))
    rgb.putdata([((x//16)*127, (y//16)*255, ((x//16+y//16) % 3)*127) for y in range(32) for x in range(48)])
    cmyk = Image.new('CMYK', (48, 32))
    colors = [(0,0,0,0), (255,0,0,0), (0,255,0,0), (0,0,255,0), (0,0,0,255), (45,170,80,40)]
    cmyk.putdata([colors[x//16 + 3*(y//16)] for y in range(32) for x in range(48)])
    profile = cmyk_profile()
    (args.output / 'cmyk.icc').write_bytes(profile)
    profiles = {'rgb': (ROOT/'tests/Fixtures/png/rgb.icc').read_bytes(),
        'gray': (ROOT/'tests/Fixtures/png/gray.icc').read_bytes(), 'cmyk': profile}
    cases = {}
    for name, image in [('rgb', rgb), ('gray', rgb.convert('L')), ('cmyk', cmyk)]:
        for progressive in (False, True):
            stem = f'{name}-icc-' + ('progressive' if progressive else 'baseline')
            output = io.BytesIO()
            image.save(output, format='JPEG', quality=100, subsampling=0, progressive=progressive, icc_profile=profiles[name])
            path = args.output / f'{stem}.jpg'
            path.write_bytes(output.getvalue())
            cases[stem] = {'profile': name, 'transform': 0 if name in ('gray', 'cmyk') else 1, 'inverted': name=='cmyk'}
    cmyk_path = args.output / 'cmyk-input.tif'
    cmyk.save(cmyk_path)
    ycck_path = args.output / 'ycck.jpg'
    subprocess.run([args.imagemagick, str(cmyk_path), '-sampling-factor', '1x1', '-quality', '100', str(ycck_path)], check=True)
    cmyk_path.unlink()
    assert Image.open(ycck_path).info['adobe_transform'] == 2
    cases['ycck'] = {'profile': None, 'transform': 1, 'inverted': True}
    ycck = ycck_path.read_bytes()
    for stem, data, kind in [
        ('ycck-icc', embed_parts(ycck, profile), 'cmyk'),
        ('ycck-icc-shuffled', embed_parts(ycck, profile, (3,1,2)), 'cmyk')]:
        (args.output / f'{stem}.jpg').write_bytes(data)
        cases[stem] = {'profile': kind, 'transform': 1, 'inverted': True}
    for stem, record in cases.items():
        data = (args.output / f'{stem}.jpg').read_bytes()
        image = Image.open(io.BytesIO(data)); image.load()
        record.update(sha256=hashlib.sha256(data).hexdigest(), width=image.width, height=image.height,
            components=len(image.getbands()), profileSha256=hashlib.sha256(profiles[record['profile']]).hexdigest() if record['profile'] else None)
        assert image.info.get('icc_profile') == (profiles[record['profile']] if record['profile'] else None)
    manifest = {'generator':'tools/generate_jpeg_fixtures.py', 'pillow':Image.__version__,
        'libjpeg':features.version('jpg'), 'cases':cases}
    (args.output / 'manifest.json').write_text(json.dumps(manifest, indent=2)+'\n')
    print(f'Generated {len(cases)} original JPEG fixtures with independent encoders.')


if __name__ == '__main__':
    main()
