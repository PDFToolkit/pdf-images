# PDFToolkit Images

Convertisseurs optionnels de fichiers PNG et JPEG vers les objets image PDF de
[PDFToolkit Core](https://github.com/PDFToolkit/pdf), pour PHP 8.3 à 8.5.

Le core représente les images PDF, leurs espaces de couleur, les échantillons,
le filtre `/DCTDecode` et les masques de transparence décrits par ISO 32000-1.
Ce package interprète les formats de fichiers externes : chunks et CRC PNG,
scanlines, palettes, marqueurs JPEG et composantes de leurs trames.
Une application qui génère ses propres échantillons peut utiliser le core seul.

## Installation

Les packages ne sont pas encore publiés sur Packagist. Dans le projet
consommateur, déclarez les deux dépôts VCS : Composer ne reprend pas les dépôts
configurés dans les dépendances.

```sh
composer config repositories.pdf vcs https://github.com/PDFToolkit/pdf
composer config repositories.pdf-images vcs https://github.com/PDFToolkit/pdf-images
composer require "pdftoolkit/pdf-images:^0.1"
```

Le module utilise le core `roukmoute/pdf:^0.2 || ^0.3`, installé automatiquement par
Composer. Le fichier `composer.lock` du projet consommateur fixe les versions
et les révisions utilisées. Installer le core seul n’installe aucun convertisseur.
La documentation commune est sur [PDFToolkit](https://pdftoolkit.github.io/pdf/).

## Construction explicite

```php
use PDF\Document\Images\ImageXObjectFactory;
use PDF\Images\ImageFileBytes;
use PDF\Images\ImageImportLimits;
use PDF\Images\JpegImageReader;
use PDF\Images\PngImageDecoder;

// L'application obtient les octets. Aucun importeur n'ouvre de chemin ou d'URL.
$pngFile = new ImageFileBytes($pngBytes);
$jpegFile = new ImageFileBytes($jpegBytes);

// Chaque instance possède ses propres limites, adaptées à l'application.
$limits = new ImageImportLimits(
    maxEncodedBytes: 1_048_576,
    maxDecodedBytes: 4_194_304,
    maxPixels: 1_048_576,
    maxDimension: 2048,
);

// Le PNG devient un SampledImage du core : couleurs et alpha séparés.
$samples = (new PngImageDecoder($limits))->decode($pngFile);

// Le JPEG devient un DCTImage du core : données encodées et paramètres PDF.
$dct = (new JpegImageReader($limits))->read($jpegFile);

// Le core compose ensuite les flux /Subtype /Image de la table 89.
$factory = new ImageXObjectFactory();
$pngObject = $factory->create($samples);
$jpegObject = $factory->create($dct);
```

Ces flux se placent explicitement dans les ressources `/XObject` d'une page,
puis se peignent avec un opérateur `InvokeXObject` et une matrice.
Le masque alpha est un autre objet image PDF en niveaux de gris, référencé
par `/SMask`. Les octets JPEG restent intacts avec le filtre `/DCTDecode`.
Le writer et le graphe de document appartiennent au core ; les convertisseurs
n'ont pas de singleton, de registre global ou de façade qui assemble une page.

## Formats pris en charge

- PNG statique, non entrelacé, huit bits par composante : gris, RGB, palette,
  gris avec alpha et RGBA. Les cinq filtres de scanline sont reconstruits.
  Les palettes sont développées en RGB ; `tRNS` devient un masque alpha.
- JPEG Huffman DCT, baseline ou progressif, huit bits par composante : gris,
  RGB et CMYK Adobe avec transformée zéro. Les tables, trames et marqueurs
  nécessaires sont contrôlés ; le lecteur PDF décode les coefficients DCT.

Les PNG animés, entrelacés et d'autres profondeurs, les profils ICC intégrés,
les JPEG YCCK et les sémantiques couleur ambiguës sont rejetés explicitement.
Les métadonnées EXIF ne produisent pas de rotation automatique. Les informations
PNG de gamma ou de chromaticité ne créent pas d'espace couleur calibré.

## Contrôles et exemples

La suite vérifie les échantillons et l'alpha attendus, la conservation des
octets JPEG, les flux PDF obtenus, les CRC, les troncatures et les limites
de dimensions, de chunks et de décompression.
Les [contrats et limites](docs/architecture.md) détaillent chaque objet.

[L'exemple complet](examples/image-files.php) construit une page avec quatre
images et des damiers qui rendent leur transparence visible. Ses motifs sont
originaux et distribués sous licence MIT. Leur générateur Pillow sert uniquement
au développement ; le package PHP ne dépend pas de Pillow ou de GD.

```sh
php examples/image-files.php output.pdf
```

Consulter le [changelog](CHANGELOG.md) et la [politique de sécurité](SECURITY.md).
