<?php

declare(strict_types=1);

use PDF\Document\Fonts\StandardFont;
use PDF\Document\Fonts\Type1Font;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Document\Images\ImageXObjectFactory;
use PDF\Images\JpegImageReader;
use PDF\Images\PngImageDecoder;
use PDF\Document\ResourceDictionary;
use PDF\Document\Resources\Font;
use PDF\Document\Resources\XObject;
use PDF\FileStructure;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\AppendRectangle;
use PDF\Syntax\ContentStreamsResources\Operators\BeginTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\EndTextObject;
use PDF\Syntax\ContentStreamsResources\Operators\FillPathNonzero;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
use PDF\Syntax\ContentStreamsResources\Operators\MoveTextPosition;
use PDF\Syntax\ContentStreamsResources\Operators\RestoreGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SaveGraphicsState;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingGray;
use PDF\Syntax\ContentStreamsResources\Operators\SetNonstrokingRGBColor;
use PDF\Syntax\ContentStreamsResources\Operators\SetTextFontAndSize;
use PDF\Syntax\ContentStreamsResources\Operators\ShowText;
use PDF\Syntax\DataStructure\Rectangle;
use PDF\Syntax\DocumentStructure\DocumentCatalog;
use PDF\Syntax\DocumentStructure\PageTree\PageObject;
use PDF\Syntax\DocumentStructure\PageTree\PageTreeNode;
use PDF\Syntax\FileStructure\FileBody;
use PDF\Syntax\FileStructure\FileHeader;
use PDF\Syntax\Object\DictionaryObject\Entry;
use PDF\Syntax\Object\DictionaryType;
use PDF\Syntax\Object\IndirectReference;
use PDF\Syntax\Object\NameType;
use PDF\Syntax\Object\NumericType;
use PDF\Syntax\Object\StreamObject;
use PDF\Syntax\Object\StringObject\LiteralString;

require dirname(__DIR__) . '/vendor/autoload.php';

// 1. The application chooses four known local files. Importers receive only
//    byte objects: no path or URL lets them open a resource.
//    The repository's original patterns are distributed under the MIT licence.
$files = [];
foreach (['rgb.jpg', 'rgba.png', 'cmyk.jpg', 'palette.png'] as $filename) {
    $bytes = file_get_contents(__DIR__ . '/assets/images/' . $filename);
    if ($bytes === false) {
        throw new RuntimeException('The example image fixture could not be read.');
    }
    $files[$filename] = new ImageFileBytes($bytes);
}

// 2. These limits bound encoded data, pixels and decoded scanlines.
//    Configure them per object, for example for an upload endpoint.
$limits = new ImageImportLimits(
    maxEncodedBytes: 1_048_576,
    maxDecodedBytes: 1_048_576,
    maxPixels: 262_144,
    maxDimension: 1024,
);
$jpegReader = new JpegImageReader($limits);
$pngDecoder = new PngImageDecoder($limits);
$rgb = $jpegReader->read($files['rgb.jpg']);
$rgba = $pngDecoder->decode($files['rgba.png']);
$cmyk = $jpegReader->read($files['cmyk.jpg']);
$palette = $pngDecoder->decode($files['palette.png']);

// 3. Composition builds the /Subtype /Image streams from table 89.
//    JPEG remains DCTDecode, byte for byte. PNG is reconstructed, then
//    compressed with FlateDecode; its alpha becomes a separate /SMask stream.
//    The writer's graph discovers the mask references.
$factory = new ImageXObjectFactory();
$images = [
    'JpegRGB' => $factory->create($rgb),
    'PngRGBA' => $factory->create($rgba),
    'JpegCMYK' => $factory->create($cmyk),
    'PngPalette' => $factory->create($palette),
];
$imageEntries = [];
foreach ($images as $name => $image) {
    $imageEntries[] = new Entry(new NameType($name), new IndirectReference($image));
}
$helvetica = new Type1Font(StandardFont::Helvetica);
$resources = new ResourceDictionary(
    new Font(new DictionaryType(new Entry(new NameType('F1'), new IndirectReference($helvetica)))),
    new XObject(new DictionaryType(...$imageEntries)),
);

$operators = [
    new SetNonstrokingRGBColor(new NumericType(0.08), new NumericType(0.18), new NumericType(0.28)),
    new BeginTextObject(),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(24)),
    new MoveTextPosition(new NumericType(52), new NumericType(732)),
    new ShowText(new LiteralString('JPEG et PNG : des octets aux objets PDF')),
    new SetTextFontAndSize(new NameType('F1'), new NumericType(11)),
    new MoveTextPosition(new NumericType(0), new NumericType(-26)),
    new ShowText(new LiteralString('Quatre images originales de 48 x 36 pixels. Taille sur la page : 216 x 162 points.')),
    new EndTextObject(),
];

// 4. Each PDF image occupies the unit square. The explicit matrix creates
//    the 216 × 162 rectangle, then translates it; there is no automatic placement.
//    A checkerboard behind the images reveals the two PNGs' alpha channels.
$panels = [
    ['JpegRGB', 52, 452, 'JPEG RGB', 'DCTDecode : les octets JPEG restent intacts.', 'Trois composantes, transformation YCbCr.'],
    ['PngRGBA', 328, 452, 'PNG avec alpha', 'FlateDecode + /SMask en niveaux de gris.', 'L\'opacite augmente de gauche a droite.'],
    ['JpegCMYK', 52, 190, 'JPEG Adobe CMYK', 'Quatre composantes et /Decode inverse.', 'Le marqueur Adobe explicite les couleurs.'],
    ['PngPalette', 328, 190, 'PNG palette et tRNS', 'Les indices sont developpes en RGB.', 'Quatre opacites : 0, 85, 170 et 255.'],
];
foreach ($panels as [$name, $x, $y, $title, $line1, $line2]) {
    $operators[] = new BeginTextObject();
    $operators[] = new SetTextFontAndSize(new NameType('F1'), new NumericType(16));
    $operators[] = new MoveTextPosition(new NumericType($x), new NumericType($y + 184));
    $operators[] = new ShowText(new LiteralString($title));
    $operators[] = new EndTextObject();
    for ($row = 0; $row < 6; ++$row) {
        for ($column = 0; $column < 8; ++$column) {
            $operators[] = new SetNonstrokingGray(new NumericType(($row + $column) % 2 === 0 ? 0.85 : 0.98));
            $operators[] = new AppendRectangle(new NumericType($x + $column * 27), new NumericType($y + $row * 27), new NumericType(27), new NumericType(27));
            $operators[] = new FillPathNonzero();
        }
    }
    $operators[] = new SaveGraphicsState();
    $operators[] = new ConcatenateMatrix(new Matrix(
        new NumericType(216),
        new NumericType(0),
        new NumericType(0),
        new NumericType(162),
        new NumericType($x),
        new NumericType($y),
    ));
    $operators[] = new InvokeXObject(new NameType($name));
    $operators[] = new RestoreGraphicsState();
    $operators[] = new SetNonstrokingRGBColor(new NumericType(0.08), new NumericType(0.18), new NumericType(0.28));
    $operators[] = new BeginTextObject();
    $operators[] = new SetTextFontAndSize(new NameType('F1'), new NumericType(10));
    $operators[] = new MoveTextPosition(new NumericType($x), new NumericType($y - 21));
    $operators[] = new ShowText(new LiteralString($line1));
    $operators[] = new MoveTextPosition(new NumericType(0), new NumericType(-17));
    $operators[] = new ShowText(new LiteralString($line2));
    $operators[] = new EndTextObject();
}

// 5. The document graph follows earlier tutorials: resources, contents, page,
//    page tree and catalog are all explicit objects.
$contents = new StreamObject(new ContentStreams(...$operators));
$page = new PageObject(
    new Rectangle(new NumericType(0), new NumericType(0), new NumericType(612), new NumericType(792)),
    $resources,
    $contents,
);
$pages = (new PageTreeNode())->addKid($page);
$catalog = new DocumentCatalog($pages);
$file = new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog));
$path = $argv[1] ?? __DIR__ . '/image-files.pdf';
$file->writeTo($path);
echo $path . PHP_EOL;
