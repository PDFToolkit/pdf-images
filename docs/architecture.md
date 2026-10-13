# Contrats des convertisseurs d'images

Le package ne représente aucune structure PDF supplémentaire et ne modifie
pas le writer. Il transforme des octets de formats externes en valeurs du core.

| Objet | Responsabilité |
| --- | --- |
| `PDF\Images\ImageFileBytes` | Porte les octets fournis par l'application, sans accès aux fichiers ou au réseau. |
| `PDF\Images\ImageImportLimits` | Borne les données encodées, les échantillons décodés, les pixels, les dimensions et le nombre de chunks ou de marqueurs. |
| `PDF\Images\JpegImageReader` | Interprète les marqueurs, tables, trames et composantes JPEG pris en charge ; retourne un `PDF\Document\Images\DCTImage`. |
| `PDF\Images\JpegFrame` et `JpegComponent` | Représentent la trame JPEG inspectée et ses composantes. |
| `PDF\Images\PngImageDecoder` | Vérifie la structure PNG et reconstruit couleurs et alpha ; retourne un `PDF\Document\Images\SampledImage`. |
| `PDF\Images\PngChunk` | Valide le nom et le CRC d'un chunk. |
| `PDF\Images\PngColorType` | Représente les codes couleur PNG, distincts des espaces de couleur PDF. |
| `PDF\Images\PngScanlineDecoder` | Reconstruit les cinq filtres PNG sur des échantillons alignés sur l'octet. |

Le résultat est ensuite donné à `PDF\Document\Images\ImageXObjectFactory`.
Ce compositeur du core accepte aussi des échantillons ou des données DCT
produits autrement. Il n'inspecte ni signature PNG ni marqueur JPEG.

## Traitement PNG

1. La signature, l'ordre et les tailles des chunks ainsi que chaque CRC sont
   contrôlés avant interprétation.
2. L'en-tête est confronté aux limites avant allocation des scanlines.
3. L'inflation zlib consomme des fragments comprimés de 64 octets et rejette
   les données dépassant la taille attendue, tronquées ou suivies de données.
4. Les filtres sont inversés, puis les indices de palette développés et les
   échantillons alpha séparés.
5. Le core reçoit les échantillons et choisit leur encodage PDF avec FlateDecode.

Les limites par défaut sont 16 MiB encodés, 16 MiB décodés, 4 194 304 pixels,
32 768 pixels par dimension et 65 536 chunks. Elles sont des limites par image ;
l'application fixe également ses budgets de requête et son nombre d'images.

## Traitement JPEG

Le lecteur conserve les octets originaux. Il contrôle les bornes des segments,
les tables de quantification et Huffman, les composantes, les paramètres de
scan et la présence de la fin de fichier. Il détermine les paramètres PDF
`/ColorSpace`, `/ColorTransform` et, pour le CMYK Adobe inversé, `/Decode`.

Il n'exécute pas le décodage entropique et ne prétend donc pas vérifier chaque
coefficient DCT. Le décodeur JPEG du lecteur PDF reste responsable du rendu.
Les données ambiguës ou les encodages non pris en charge sont refusés.

## Intégration

Les convertisseurs et les valeurs retournées sont des objets construits
explicitement. Ils n'ouvrent jamais un chemin ni une URL et n'ajoutent pas
d'images à une page. La ressource `/XObject`, la référence indirecte, la
matrice de placement et l'opérateur `Do` restent des objets du core choisis
par l'application. Le package est optionnel pour une génération à partir
d'échantillons déjà disponibles.
