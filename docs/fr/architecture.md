# Contrats des convertisseurs d'images

[English](../architecture.md) | Français

La version 0.1.8 accepte le core 0.7 en plus des versions antérieures prises en
charge. La conversion PNG/JPEG reste distincte des métadonnées, du balisage et
des mécanismes cryptographiques PDF 2.0, composés à l’aide des objets du core.

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
| `PDF\Images\PngScanlineDecoder` | Reconstruit les cinq filtres PNG sur des octets, y compris les échantillons compactés et 16 bits. |

Le résultat est ensuite donné à `PDF\Document\Images\ImageXObjectFactory`.
Ce compositeur du core accepte aussi des échantillons ou des données DCT
produits autrement. Il n'inspecte ni signature PNG ni marqueur JPEG.

## Traitement PNG

1. La signature, l'ordre et les tailles des chunks ainsi que chaque CRC sont
   contrôlés avant interprétation.
2. L'en-tête est confronté aux limites avant allocation des scanlines.
3. L'inflation zlib consomme des fragments comprimés de 64 octets et rejette
   les données dépassant la taille décodée attendue ou tronquées. Les octets
   encodés inutilisés après le dernier flux zlib IDAT sont ignorés comme le
   permet PNG ; les données finales supplémentaires iCCP restent invalides.
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
`/ColorSpace`, `/ColorTransform` et, pour le CMYK Adobe ou YCCK inversé,
`/Decode`. Le marqueur Adobe de transformée 2 produit `/ColorTransform 1`
et quatre intervalles inversés ; la transformée 0 conserve le CMYK.

`JpegProfileParts` réassemble les segments ICC APP2 par numéro, même dans
un ordre physique différent, avec limites explicites de profil et de copies
cumulées. Les segments manquants, répétés ou incohérents sont rejetés.
`ImageIccProfile` contrôle la compatibilité gris/RGB/CMYK et conserve chaque
octet dans un espace ICCBased. Un segment complet situé après un scan
reste accepté : sa position ne remplace pas son numéro de séquence.

Ces contrats suivent `ISO_32000-2_sponsored_EC3.pdf`, pages physiques 63–65,
et [ICC.1:2004-04](https://www.color.org/ICC1V42old.pdf), annexe B.5,
page physique 81. `tools/validate_jpeg.py` vérifie neuf images originales
baseline/progressives/CMYK/YCCK avec qpdf, pypdf, Poppler et Pillow/LittleCMS.
Les octets JPEG et ICC sont exacts ; tous les pixels doivent égaler ceux
d’un PDF construit indépendamment avec pypdf. Les intérieurs uniformes
sont aussi comparés aux échantillons JPEG décodés indépendamment et
convertis par LittleCMS, avec au plus trois niveaux 8 bits par composante.
L’oracle désactive la LUT précalculée approximative de LittleCMS et active
la compensation du point noir, comme Poppler. Le profil CMYK synthétique
est un vecteur de test, pas un profil d’imprimante calibrée.

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

## Profondeurs PNG, Adam7 et profils ICC

`PngHeader` valide les combinaisons du tableau 12 PNG : gris 1/2/4/8/16 bits,
palette 1/2/4/8, RGB, gris+alpha et RGBA 8/16. `PngPasses` et `PngPass`
décrivent les passes ; `PngRasterDecoder` applique indépendamment les cinq
filtres à chaque passe Adam7 et replace les pixels dans les lignes finales.
Une passe vide ne consomme aucun octet de filtre. `PngRaster` conserve les
échantillons complets, y compris les valeurs 16 bits en ordre réseau.
`PngScanlineDecoder` accepte un quatrième argument de profondeur optionnel
(8 par défaut), sans casser ses appels existants.

`PngSampleConverter` développe les palettes en RGB 8 bits, sépare couleur et
alpha et conserve la précision 16 bits des canaux natifs. Une clé `tRNS`
masque les bits hauts inutilisés, puis compare tous les bits des échantillons
avant de créer un masque d’opacité
8 bits. Le gris compacté conserve sa profondeur.

`PngProfileReader` et `ImageIccProfile` bornent la décompression iCCP, vérifient
le nom, l’en-tête, la compatibilité gris/RGB et les limites des tags, puis
conservent les octets exacts via `ICCProfile`/`ICCBased` du core. L’évaluation
des transformations couleur appartient au lecteur PDF ; ces vérifications
structurelles ne prétendent pas valider toute la sémantique ICC.

Les paramètres supplémentaires de `ImageImportLimits` sont
`maxCumulativeDecodedBytes` (64 Mio), `maxProfileBytes` (4 Mio) et
`maxProfileTags` (4096). `ImageImportBudget` est créé pour chaque conversion ;
profil décompressé, données filtrées, copies de passes, reconstruction et
échantillons retournés consomment le même budget. `ImageDataInflater` traite
des fragments compressés de 64 octets et refuse troncature et dépassement.
Seuls les octets encodés inutilisés après le dernier flux IDAT sont ignorés ;
un profil iCCP doit consommer exactement ses données comprimées. Couleur et alpha retournés doivent aussi
respecter ensemble `maxDecodedBytes`. Ces plafonds précèdent les allocations ;
ils ne garantissent pas la mémoire totale du processus. L’application garde
ses budgets de requête et de nombre d’images.

Références : [PNG troisième édition](https://www.w3.org/TR/2025/REC-png-3-20250624/),
sections 8.1, 9, 11.2.1 (tableau 12), 11.3.1.1 et 11.3.2.3 ; source conservée
`ISO_32000-2_sponsored_EC3.pdf`, pages physiques 206–208 et 273–276 pour ICC et
les images PDF. La [politique couleur PNG](png-colour.md) couvre la calibration
gAMA/cHRM et les profils sRGB exacts, avec priorité des déclarations et intention
de rendu. Animation et métadonnées HDR restent non prises en charge. EXIF ne
provoque aucune rotation.

`tools/validate_png.py` vérifie 35 fixtures originales avec pypdf, Pillow,
ImageMagick/libpng et Poppler : échantillons et profils PDF exacts, puis
rendu au centre des pixels avec une tolérance de trois niveaux 8 bits par
canal pour l’arrondi et la gestion des couleurs indépendants. Pillow seul
ne conserve pas toutes les conversions de clés de transparence non 8 bits ;
ImageMagick/libpng vérifie donc indépendamment tous les échantillons couleur
et opacité en sortie 16 bits.
