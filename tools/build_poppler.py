"""Build the pinned independent image-rendering oracle (not a package dependency)."""
from __future__ import annotations

import argparse
import hashlib
from pathlib import Path
import subprocess
import tarfile
import urllib.request

VERSION = '26.10.0'
SHA256 = '6792cb7c69205007ad87d2e936cecc5b3a31fac29ab54ffc3175fdb6b2a6ce35'
URL = f'https://poppler.freedesktop.org/poppler-{VERSION}.tar.xz'


def build(root: Path) -> Path:
    root = root.absolute()
    root.mkdir(parents=True, exist_ok=True)
    archive = root / f'poppler-{VERSION}.tar.xz'
    if not archive.exists():
        with urllib.request.urlopen(URL, timeout=60) as response:
            archive.write_bytes(response.read())
    if hashlib.sha256(archive.read_bytes()).hexdigest() != SHA256:
        raise ValueError('Poppler source archive SHA-256 mismatch')
    source = root / f'poppler-{VERSION}'
    if not source.exists():
        with tarfile.open(archive) as bundle:
            bundle.extractall(root, filter='data')
    output = root / 'build'
    subprocess.run([
        'cmake', '-S', str(source), '-B', str(output), '-G', 'Ninja',
        '-DCMAKE_BUILD_TYPE=Release', '-DENABLE_BOOST=OFF',
        '-DENABLE_CPP=OFF', '-DENABLE_GLIB=OFF', '-DENABLE_QT5=OFF', '-DENABLE_QT6=OFF',
        '-DENABLE_NSS3=OFF', '-DENABLE_GPGME=OFF', '-DENABLE_LIBCURL=OFF',
        '-DENABLE_LIBTIFF=OFF', '-DENABLE_BROTLI=OFF', '-DENABLE_HARFBUZZ=OFF',
        '-DFONT_CONFIGURATION=generic', '-DBUILD_MANUAL_TESTS=OFF',
        '-DENABLE_LCMS=ON', '-DENABLE_LIBOPENJPEG=ON', '-DENABLE_LIBJPEG=ON',
    ], check=True)
    subprocess.run(['cmake', '--build', str(output), '--target', 'pdftoppm', '--parallel', '2'], check=True)
    binary = output / 'utils/pdftoppm'
    subprocess.run([str(binary), '-v'], check=True)
    return binary


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, default=Path('_build/poppler'))
    print(build(parser.parse_args().output))
