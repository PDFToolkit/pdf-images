<?php

declare(strict_types=1);

use PDF\Document\Images\ImageXObjectFactory;
use PDF\Document\ResourceDictionary;
use PDF\FileStructure;
use PDF\Graphics\ColourSpaces\ICCBased;
use PDF\Graphics\CoordinateSystems\Matrix;
use PDF\Images\ImageFileBytes;
use PDF\Images\JpegImageReader;
use PDF\Serialization\SerializationContext;
use PDF\Syntax\ContentStreamsResources\ContentStreams;
use PDF\Syntax\ContentStreamsResources\Operators\ConcatenateMatrix;
use PDF\Syntax\ContentStreamsResources\Operators\InvokeXObject;
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

require dirname(__DIR__) . '/vendor/autoload.php';

$destination = $argv[1] ?? throw new InvalidArgumentException('An output directory is required.');
if (!is_dir($destination) && !mkdir($destination, 0777, true)) {
    throw new RuntimeException('Could not create the fixture export directory.');
}
$fixtures = dirname(__DIR__) . '/tests/Fixtures/jpeg';
$manifest = json_decode(file_get_contents($fixtures . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$records = [];
foreach ($manifest['cases'] as $name => $expected) {
    $image = (new JpegImageReader())->read(new ImageFileBytes(file_get_contents($fixtures . '/' . $name . '.jpg')));
    $stream = (new ImageXObjectFactory())->create($image);
    $width = new NumericType($image->dimensions->width);
    $height = new NumericType($image->dimensions->height);
    $zero = new NumericType(0);
    $resources = new ResourceDictionary(new Entry(new NameType('XObject'), new DictionaryType(new Entry(new NameType('Image'), new IndirectReference($stream)))));
    $contents = new StreamObject(new ContentStreams(
        new ConcatenateMatrix(new Matrix($width, $zero, $zero, $height, $zero, $zero)),
        new InvokeXObject(new NameType('Image')),
    ));
    $page = new PageObject(new Rectangle($zero, $zero, $width, $height), $resources, $contents);
    $catalog = new DocumentCatalog((new PageTreeNode())->addKid($page));
    (new FileStructure(new FileHeader('1.7'), new FileBody($catalog), new IndirectReference($catalog)))
        ->writeTo($destination . '/' . $name . '.pdf');
    $space = $image->layout->colorSpace;
    $records[$name] = [
        'encodedSha256' => hash('sha256', $image->encodedBytes),
        'transform' => $image->colorTransform,
        'inverted' => $image->invertedComponents,
        'profileSha256' => $space instanceof ICCBased ? hash('sha256', $space->profile->bytes(new SerializationContext())) : null,
    ];
}
echo json_encode(['cases' => $records], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
