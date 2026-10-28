"""Generate original PNG depth/Adam7 motifs; Pillow/libpng independently verifies them in validate_png.py."""
from __future__ import annotations
import argparse
import base64
import ctypes
import ctypes.util
import hashlib
import json
from pathlib import Path
import struct
import zlib

ROOT = Path(__file__).resolve().parents[1]
GEOMETRY = [(0,0,8,8),(4,0,8,8),(0,4,4,8),(2,0,4,4),(0,2,2,4),(1,0,2,2),(0,1,1,2)]
DEPTHS = {0:[1,2,4,8,16], 2:[8,16], 3:[1,2,4,8], 4:[8,16], 6:[8,16]}
COMPONENTS = {0:1,2:3,3:1,4:2,6:4}


def chunk(name, data):
    kind = name.encode('ascii')
    return struct.pack('>I',len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind+data))


def packed(values, depth):
    if depth == 16:
        return b''.join(struct.pack('>H',v) for v in values)
    if depth == 8:
        return bytes(values)
    result = bytearray((len(values)*depth+7)//8)
    for i,v in enumerate(values):
        result[i*depth//8] |= v << (8-depth-(i*depth)%8)
    return bytes(result)


def paeth(a,b,c):
    p=a+b-c
    distances=[abs(p-a),abs(p-b),abs(p-c)]
    return [a,b,c][distances.index(min(distances))]


def filter_row(row, previous, stride, kind):
    output=bytearray()
    for i,byte in enumerate(row):
        a=row[i-stride] if i>=stride else 0
        b=previous[i]
        c=previous[i-stride] if i>=stride else 0
        predict=[0,a,b,(a+b)//2,paeth(a,b,c)][kind]
        output.append((byte-predict)&255)
    return bytes([kind])+output


def profile(gray=False):
    lib=ctypes.CDLL(ctypes.util.find_library('lcms2'))
    lib.cmsCreate_sRGBProfile.restype=ctypes.c_void_p
    lib.cmsSaveProfileToMem.argtypes=[ctypes.c_void_p,ctypes.c_void_p,ctypes.POINTER(ctypes.c_uint32)]
    lib.cmsCloseProfile.argtypes=[ctypes.c_void_p]
    if gray:
        class White(ctypes.Structure):
            _fields_=[('x',ctypes.c_double),('y',ctypes.c_double),('Y',ctypes.c_double)]
        lib.cmsBuildGamma.argtypes=[ctypes.c_void_p,ctypes.c_double]
        lib.cmsBuildGamma.restype=ctypes.c_void_p
        lib.cmsCreateGrayProfile.argtypes=[ctypes.POINTER(White),ctypes.c_void_p]
        lib.cmsCreateGrayProfile.restype=ctypes.c_void_p
        lib.cmsFreeToneCurve.argtypes=[ctypes.c_void_p]
        gamma=lib.cmsBuildGamma(None,2.2)
        handle=lib.cmsCreateGrayProfile(ctypes.byref(White(0.3457,0.3585,1.0)),gamma)
        lib.cmsFreeToneCurve(gamma)
    else:
        handle=lib.cmsCreate_sRGBProfile()
    if not handle:
        raise ValueError('LittleCMS profile construction failed')
    size=ctypes.c_uint32()
    lib.cmsSaveProfileToMem(handle,None,ctypes.byref(size))
    buffer=ctypes.create_string_buffer(size.value)
    lib.cmsSaveProfileToMem(handle,buffer,ctypes.byref(size))
    lib.cmsCloseProfile(handle)
    data=bytearray(buffer.raw)
    data[24:36]=struct.pack('>6H',2026,10,28,0,0,0)
    data[84:100]=b'\0'*16
    return bytes(data)


def make_case(kind, depth, interlace, width=9,height=9, icc=None, transparent=False):
    maximum=(1<<depth)-1
    palette=[((i*37)%256,(i*71)%256,(i*109)%256) for i in range(min(16,1<<depth))]
    pixels=[]
    for y in range(height):
        row=[]
        for x in range(width):
            if kind==3:
                pixel=[(x+3*y)%len(palette)]
            else:
                pixel=[(x*719+y*131+c*11003)&maximum for c in range(COMPONENTS[kind])]
            row.append(pixel)
        pixels.append(row)
    header=struct.pack('>IIBBBBB',width,height,depth,kind,0,0,interlace)
    before=b''
    if icc is not None:
        before+=chunk('iCCP',b'Original fixture profile\0\0'+zlib.compress(icc,9))
    alpha_table=bytes(round(i*255/max(1,len(palette)-1)) for i in range(len(palette)))
    if kind==3:
        before+=chunk('PLTE',bytes(v for color in palette for v in color))
        before+=chunk('tRNS',alpha_table)
    transparent_color=pixels[0][1]
    if transparent:
        before+=chunk('tRNS',b''.join(struct.pack('>H',v) for v in transparent_color))
    filtered=bytearray()
    for pass_index,(x0,y0,dx,dy) in enumerate(GEOMETRY if interlace else [(0,0,1,1)]):
        if x0>=width or y0>=height:
            continue
        previous=None
        for row_index,y in enumerate(range(y0,height,dy)):
            row=packed([v for x in range(x0,width,dx) for v in pixels[y][x]],depth)
            previous=previous if previous is not None else bytes(len(row))
            filtered.extend(filter_row(row,previous,max(1,(COMPONENTS[kind]*depth+7)//8),(pass_index+row_index)%5))
            previous=row
    png=b'\x89PNG\r\n\x1a\n'+chunk('IHDR',header)+before+chunk('IDAT',zlib.compress(bytes(filtered),9))+chunk('IEND',b'')
    colors,alpha=bytearray(),bytearray()
    color_depth=8 if kind==3 else depth
    alpha_depth=depth if kind in (4,6) else 8
    for row in pixels:
        color_values=[]
        alpha_values=[]
        for pixel in row:
            if kind==3:
                color_values.extend(palette[pixel[0]])
                alpha_values.append(alpha_table[pixel[0]])
            elif kind in (4,6):
                color_values.extend(pixel[:-1]);alpha_values.append(pixel[-1])
            else:
                color_values.extend(pixel)
                if transparent:
                    alpha_values.append(0 if pixel==transparent_color else 255)
        colors.extend(packed(color_values,color_depth))
        if alpha_values:
            alpha.extend(packed(alpha_values,alpha_depth))
    return png, {'width':width,'height':height,'colorType':kind,'depth':depth,'interlace':interlace,
        'colorDepth':color_depth,'alphaDepth':alpha_depth if alpha else None,
        'components':1 if kind in (0,4) else 3,
        'samples':base64.b64encode(colors).decode(), 'alpha':base64.b64encode(alpha).decode() if alpha else None,
        'profileSha256':hashlib.sha256(icc).hexdigest() if icc else None}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output',type=Path,default=ROOT/'tests/Fixtures/png')
    args=parser.parse_args();args.output.mkdir(parents=True,exist_ok=True)
    profiles={'rgb.icc':profile(),'gray.icc':profile(True)}
    for name,data in profiles.items():
        (args.output/name).write_bytes(data)
    cases={}
    variants=[(f'type-{kind}-depth-{depth}-adam7-{interlace}',kind,depth,interlace,False,None)
        for kind,depths in DEPTHS.items() for depth in depths for interlace in (0,1)]
    variants += [('gray16-key',0,16,1,True,None),('rgb16-key',2,16,1,True,None),
        ('gray2-key',0,2,1,True,None),('rgb-icc',2,16,1,False,profiles['rgb.icc']),
        ('gray-icc',0,16,1,False,profiles['gray.icc'])]
    for name,kind,depth,interlace,transparent,icc in variants:
        png,record=make_case(kind,depth,interlace,icc=icc,transparent=transparent)
        (args.output/(name+'.png')).write_bytes(png)
        record['sha256']=hashlib.sha256(png).hexdigest()
        cases[name]=record
    (args.output/'manifest.json').write_text(json.dumps({'schema':1,'generator':'tools/generate_png_fixtures.py','cases':cases},indent=2)+'\n')
    print(f'Generated {len(cases)} original PNG fixtures and two LittleCMS profiles.')


if __name__=='__main__':
    main()
