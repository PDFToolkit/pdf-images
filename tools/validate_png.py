"""Compare all PNG samples and ICC bytes with Pillow, pypdf and actual Poppler rendering."""
from __future__ import annotations
import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tempfile

import PIL
from PIL import Image, ImageCms
from pypdf import PdfReader

ROOT = Path(__file__).resolve().parents[1]


def require(condition, message):
    if not condition:
        raise ValueError(message)


def unpack_rows(data, width, height, components, depth):
    row_size=(width*components*depth+7)//8
    pixels=[]
    for y in range(height):
        row=[]
        for x in range(width):
            values=[]
            for component in range(components):
                bit=(x*components+component)*depth
                pos=y*row_size+bit//8
                if depth==16:
                    value=int.from_bytes(data[pos:pos+2],'big')
                elif depth==8:
                    value=data[pos]
                else:
                    value=(data[pos]>>(8-depth-bit%8))&((1<<depth)-1)
                values.append(value)
            row.append(values)
        pixels.append(row)
    return pixels


def reference_image(path, record, samples, alpha):
    image=Image.open(path)
    image.load()
    width,height=image.size
    maximum=(1<<record['colorDepth'])-1
    # Pillow preserves 16-bit grayscale, while its RGB decoder returns high bytes.
    if record['components']==1 and record['colorDepth']==16:
        if record['colorType']==0:
            require(list(image.get_flattened_data())==[p[0] for row in samples for p in row], path.name+': Pillow grayscale16 samples')
        color=Image.new('L',(width,height))
        color.putdata([round(p[0]*255/maximum) for row in samples for p in row])
    else:
        color=(image.convert('RGBA') if image.mode=='P' else image).convert('L' if record['components']==1 else 'RGB')
        actual=list(color.get_flattened_data())
        expected=[]
        for row in samples:
            for pixel in row:
                converted=[v>>8 if record['colorDepth']==16 else round(v*255/maximum) for v in pixel]
                expected.append(converted[0] if len(converted)==1 else tuple(converted))
        require(actual==expected,path.name+': Pillow colour samples differ')
    if record['profileSha256']:
        profile=image.info.get('icc_profile')
        require(profile is not None and hashlib.sha256(profile).hexdigest()==record['profileSha256'],path.name+': Pillow ICC bytes differ')
        color=ImageCms.profileToProfile(color,ImageCms.ImageCmsProfile(io.BytesIO(profile)),ImageCms.createProfile('sRGB'),renderingIntent=1,outputMode='RGB')
    else:
        color=color.convert('RGB')
    if alpha is not None:
        maximum_alpha=(1<<record['alphaDepth'])-1
        opacity=Image.new('L',(width,height))
        opacity.putdata([round(p[0]*255/maximum_alpha) for row in alpha for p in row])
        # PNG decoders independently observe the alpha, including indexed tRNS.
        pillow_alpha=list(image.convert('RGBA').getchannel('A').get_flattened_data())
        expected_alpha=[v[0]>>8 if record['alphaDepth']==16 else v[0] for row in alpha for v in row]
        if not (record['depth']!=8 and record['colorType'] in (0,2)):
            require(pillow_alpha==expected_alpha,path.name+': Pillow opacity differs')
        # Pillow does not preserve every non-8-bit tRNS conversion; ImageMagick/libpng checks it below.
        color=Image.composite(color,Image.new('RGB',color.size,'white'),opacity)
    return color


def libpng_samples(executable, path, samples, depth, alpha=False):
    channels=len(samples[0][0])
    width,height=len(samples[0]),len(samples)
    command=[executable,str(path)]+(['-alpha','extract'] if alpha else ['-alpha','off'])
    command+=['-depth','16','-endian','MSB',('gray' if channels==1 else 'rgb')+':-']
    completed=subprocess.run(command,check=True,capture_output=True,timeout=30)
    body=completed.stdout
    require(len(body)==width*height*channels*2,path.name+': ImageMagick/libpng output length')
    expected=[value for row in samples for pixel in row for value in pixel]
    source_max=(1<<depth)-1
    for index,value in enumerate(expected):
        actual=int.from_bytes(body[index*2:index*2+2],'big')
        require(actual*source_max==value*65535,path.name+': ImageMagick/libpng sample differs')


def validate(output, php, pdftoppm, qpdf, imagemagick):
    fixtures=ROOT/'tests/Fixtures/png'
    manifest=json.loads((fixtures/'manifest.json').read_text())
    output.mkdir(parents=True,exist_ok=True)
    completed=subprocess.run([php,str(ROOT/'tools/report-png.php'),str(output)],cwd=ROOT,check=True,capture_output=True,text=True,timeout=90)
    report=json.loads(completed.stdout)
    (output/'native.json').write_text(completed.stdout)
    checks=[]
    for name,record in manifest['cases'].items():
        path=fixtures/(name+'.png')
        require(hashlib.sha256(path.read_bytes()).hexdigest()==record['sha256'],name+': source hash')
        for key in ['samples','alpha','colorDepth','alphaDepth','profileSha256']:
            require(report['cases'][name][key]==record[key],name+': native '+key)
        pdf=output/(name+'.pdf')
        subprocess.run([qpdf,'--check',str(pdf)],check=True,capture_output=True,timeout=30)
        reader=PdfReader(pdf,strict=True)
        require(len(reader.pages)==1,name+': page count')
        image=reader.pages[0]['/Resources']['/XObject']['/Image']
        expected=base64.b64decode(record['samples'])
        require(image.get_data()==expected,name+': PDF samples changed')
        require(image['/BitsPerComponent']==record['colorDepth'],name+': PDF sample precision')
        space=image['/ColorSpace']
        if record['profileSha256']:
            require(space[0]=='/ICCBased',name+': ICCBased missing')
            profile=space[1].get_object()
            require(profile['/N']==record['components'],name+': ICC component count')
            require(hashlib.sha256(profile.get_data()).hexdigest()==record['profileSha256'],name+': PDF ICC bytes changed')
        else:
            require(space==('/DeviceGray' if record['components']==1 else '/DeviceRGB'),name+': PDF colour space')
        samples=unpack_rows(expected,record['width'],record['height'],record['components'],record['colorDepth'])
        alpha=None
        if record['alpha'] is not None:
            mask=image['/SMask']
            alpha_data=base64.b64decode(record['alpha'])
            require(mask.get_data()==alpha_data and mask['/BitsPerComponent']==record['alphaDepth'],name+': PDF alpha samples/precision')
            alpha=unpack_rows(alpha_data,record['width'],record['height'],1,record['alphaDepth'])
        else:
            require('/SMask' not in image,name+': unexpected alpha')
        libpng_samples(imagemagick,path,samples,record['colorDepth'])
        if alpha is not None:
            libpng_samples(imagemagick,path,alpha,record['alphaDepth'],alpha=True)
        reference=reference_image(path,record,samples,alpha)
        prefix=output/(name+'-render')
        subprocess.run([pdftoppm,'-singlefile','-r','720','-png',str(pdf),str(prefix)],check=True,capture_output=True,timeout=30)
        rendered=Image.open(prefix.with_suffix('.png')).convert('RGB')
        require(rendered.size==(record['width']*10,record['height']*10),name+': render dimensions')
        difference=0
        for y in range(record['height']):
            for x in range(record['width']):
                actual=rendered.getpixel((x*10+5,y*10+5))
                target=reference.getpixel((x,y))
                difference=max(difference,max(abs(a-b) for a,b in zip(actual,target)))
        require(difference<=3,name+f': rendered samples differ by {difference} (per-channel tolerance 3)')
        checks.append({'case':name,'samples':'exact','profile':'exact' if record['profileSha256'] else None,'maximumRenderChannelDifference':difference})
    return {'schema':1,'cases':len(checks),'checksPerCase':['source hash','native samples and precision','qpdf structure','pypdf samples and alpha','ICC byte provenance','Pillow decoding','libpng exact normalized colour/alpha samples','Poppler rendered colours/opacity'], 'pillowVersion':PIL.__version__,'checks':checks}


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php',default='php')
    parser.add_argument('--pdftoppm',default='pdftoppm')
    parser.add_argument('--qpdf',default='qpdf')
    parser.add_argument('--imagemagick',default='convert')
    parser.add_argument('--output',type=Path)
    parser.add_argument('--report',type=Path)
    args=parser.parse_args()
    (ROOT / '_build').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='png-', dir=ROOT / '_build') as temporary:
        report=validate(args.output or Path(temporary),args.php,args.pdftoppm,args.qpdf,args.imagemagick)
    if args.report:
        args.report.parent.mkdir(parents=True,exist_ok=True)
        args.report.write_text(json.dumps(report,indent=2)+'\n')
    print(f"Independent PNG checks passed: {report['cases']} formats, exact samples/ICC, Pillow and Poppler.")


if __name__=='__main__':
    main()
