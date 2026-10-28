# PDFToolkit Images

[English](README.md) | Français

Convertisseurs optionnels de fichiers PNG et JPEG vers les objets image PDF de
[PDFToolkit Core](https://github.com/PDFToolkit/pdf), pour PHP 8.3 à 8.5.

Le core représente les images PDF, leurs espaces de couleur, les échantillons,
le filtre `/DCTDecode` et les masques de transparence décrits par ISO 32000-1.
Ce package interprète les formats de fichiers externes : chunks et CRC PNG,
scanlines, palettes, marqueurs JPEG et composantes de leurs trames.
Une application qui génère ses propres échantillons peut utiliser le core seul.

## Installation

Le package est disponible sur
[Packagist](https://packagist.org/packages/pdftoolkit/pdf-images). Dans le projet
consommateur :

```sh
composer require "pdftoolkit/pdf-images:^0.1"
```

Le module utilise le core `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7 || ^0.8 || ^0.9 || ^0.10 || ^0.11`,
installé automatiquement par Composer. PHP 8.3 ou supérieur sur une plateforme
64 bits et les extensions `ctype`, `iconv` et `zlib` sont requis pour cet ensemble.
Le fichier `composer.lock` du projet
consommateur fixe les versions et les révisions utilisées. Installer le core seul
n’installe aucun convertisseur.
La documentation commune est sur [PDFToolkit](https://pdftoolkit.github.io/fr/).
La [documentation du module images](https://pdftoolkit.github.io/fr/modules/images/)
présente ses guides et sa référence API.

Les mêmes convertisseurs sont compatibles avec les cores 0.6, 0.7, 0.8, 0.9 et 0.10.
La conversion PNG/JPEG conserve son périmètre. La sélection de la version de sortie
et les objets de métadonnées, de balisage ou de sécurité PDF 2.0 appartiennent
au writer du core.

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

- PNG statique avec toutes les combinaisons couleur/profondeur légales : gris
  1/2/4/8/16, palette 1/2/4/8, RGB, gris avec alpha ou RGBA 8/16 bits. Les images
  non entrelacées et Adam7 reconstruisent les cinq filtres de scanline.
  Les palettes deviennent RGB ; `tRNS` compare la valeur complète et devient
  un masque alpha. La couleur et l’alpha 16 bits conservent leur précision.
  Les profils ICC gris/RGB sont bornés, contrôlés structurellement et conservés
  octet pour octet dans un espace couleur PDF ICCBased explicite.
- JPEG Huffman DCT, baseline ou progressif, huit bits par composante : gris,
  RGB et CMYK Adobe avec transformée zéro. Les tables, trames et marqueurs
  nécessaires sont contrôlés ; le lecteur PDF décode les coefficients DCT.

Les PNG animés, les métadonnées couleur HDR, les profils ICC JPEG, les JPEG
YCCK et les sémantiques couleur ambiguës sont rejetés explicitement.
Les métadonnées EXIF ne produisent pas de rotation automatique. Les informations
PNG de gamma ou de chromaticité ne créent pas d'espace couleur calibré.

## Contrôles et exemples

La suite vérifie les échantillons et l'alpha attendus, la conservation des
octets JPEG, les flux PDF obtenus, les CRC, les troncatures et les limites
de dimensions, de chunks, de profils et de décompression cumulée. Le corpus PNG
étendu compare les échantillons et profils ICC PDF exacts avec pypdf, décode avec
Pillow et ImageMagick/libpng, puis vérifie le rendu couleur/alpha avec Poppler.
Les [contrats et limites](docs/fr/architecture.md) détaillent chaque objet.

[L'exemple complet](examples/image-files.php) construit une page avec quatre
images et des damiers qui rendent leur transparence visible. Ses motifs sont
originaux et distribués sous licence MIT. Leur générateur Pillow sert uniquement
au développement ; le package PHP ne dépend pas de Pillow ou de GD.

```sh
php examples/image-files.php output.pdf
```

Consulter le [changelog](CHANGELOG.fr.md) et la [politique de sécurité](SECURITY.fr.md).
