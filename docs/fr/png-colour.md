# Déclarations couleur PNG

[English](../png-colour.md) | Français

`PngImageDecoder` conserve les échantillons couleur originaux et fournit leur
espace au core. Il n’applique pas de gamma à l’alpha, ne tourne pas l’image
selon EXIF, n’ouvre pas de profil externe et ne convertit pas les couleurs pour
un périphérique particulier.

| Déclaration source, par priorité décroissante | Résultat PDF |
| --- | --- |
| `iCCP` | Profil source exact dans un espace `ICCBased`, avec composantes gris/RGB correspondantes. |
| `sRGB` | Profil ICC standard généré avec la courbe sRGB par morceaux, et `/Intent`. |
| `gAMA` et/ou `cHRM` | `CalGray` ou `CalRGB`, avec gamma image inversé, point blanc XYZ normalisé et, pour RGB, matrice dérivée des primaires déclarées. |
| Aucune, ou seulement `gAMA` nul | Échantillons gris/RGB dépendants du périphérique comme auparavant. |

`PngColorMetadata` porte les déclarations d’une seule conversion.
`PngChromaticities` résout la matrice RGB vers XYZ depuis les coordonnées xy
explicites. Des primaires singulières, des coordonnées invalides ou non finies
et un point blanc hors du triangle RGB ne définissent pas d’espace calibré
utilisable et sont rejetés. Les chunks ont une taille fixe et apparaissent une
seule fois avant PLTE/IDAT. Une déclaration valide de priorité inférieure ne
modifie pas un profil prioritaire.

Les métadonnées incomplètes demandent une politique d’application.
`PngColorDefaults` utilise par défaut un gamma image `1 / 2.2` et les
chromaticités D65/Rec.709. Ces hypothèses ne complètent les valeurs manquantes
que lorsqu’au moins une déclaration gAMA/cHRM effective existe. Elles
n’inventent pas de profil pour une image sans déclaration couleur. L’application
peut fournir explicitement une autre politique :

```php
use PDF\Images\PngChromaticities;
use PDF\Images\PngColorDefaults;
use PDF\Images\PngImageDecoder;

$decoder = new PngImageDecoder(colorDefaults: new PngColorDefaults(
    imageGamma: 0.5,
    chromaticities: new PngChromaticities(),
));
```

Les valeurs `sRGB` 0, 1, 2 et 3 sélectionnent respectivement Perceptual,
RelativeColorimetric, Saturation et AbsoluteColorimetric. La fabrique du core
propage `SampledImage::$intent`. Une entrée image `/Intent` fournie explicitement
dans les options du core reste prioritaire. Cette propagation nécessite le core
0.12 ou supérieur. Un profil iCCP incorporé reste inchangé octet pour octet,
y compris son en-tête ; il n’hérite pas de l’intention sRGB moins prioritaire.

`PngSrgbProfile` incorpore deux petits profils générés par LittleCMS : RGB
(588 octets) et gris (364 octets). Leurs courbes comprennent le segment linéaire
sRGB ; ce ne sont pas des approximations gamma 2,2.
`tools/build_srgb_profiles.py` les recrée avec LittleCMS 2.19. Dates de création
et identifiants optionnels sont normalisés ; seule l’intention d’en-tête est
adaptée par image. Leur notice interne autorise l’utilisation sans restriction.
Aucun fichier de profil, bibliothèque CMS ou extension native externe n’est
nécessaire en production. Taille, nombre de tags et deux tampons de décodage et
de modification consomment les limites normales d’import.

L’implémentation suit [PNG troisième édition](https://www.w3.org/TR/2025/REC-png-3-20250624/),
§4.3/tableau 1, §§11.3.2.1–3, §11.3.2.5 et §§13.13–14. Les deux corrections de
compatibilité suivent également §11.3.1.1 (masquer les bits hauts inutilisés
des clés de transparence) et §11.2.3 (ignorer les octets inutilisés après le
dernier flux zlib IDAT). Les correspondances PDF utilisent la source conservée
`ISO_32000-2_sponsored_EC3.pdf`, pages physiques 201–203, tableaux 62–63 pour
CalGray/CalRGB, et pages 206–208 pour ICCBased. Animation et déclarations HDR
non prises en charge restent des erreurs explicites ; toutes les capacités de
PNG 3 ne sont pas annoncées.

## Validation indépendante

`PngColorMetadataTest` vérifie priorités, valeurs de matrice, quatre intentions,
déclarations invalides, limites et réutilisation d’un décodeur sans fuite
d’état. `tools/validate_png_color.py` contrôle 14 fixtures originales, en plus
des 35 cas de profondeur/Adam7 de `tools/validate_png.py` :

- Pillow et ImageMagick/libpng décodent indépendamment les échantillons source.
- qpdf contrôle la structure PDF ; pypdf compare les octets couleur/alpha à leur
  précision d’origine, la provenance ICC, les déclarations XYZ/gamma et `/Intent`.
- LittleCMS construit des transformations de référence indépendamment depuis
  les déclarations source. Les centres de pixels rendus par Poppler diffèrent
  d’au plus trois niveaux par canal 8 bits, avec NOOPTIMIZE et
  BLACKPOINTCOMPENSATION.

Le chemin Splash de Poppler réduit les échantillons image 16 bits à leur octet
fort avant la conversion couleur, dans
[`ImageStream::getLine`](https://gitlab.freedesktop.org/poppler/poppler/-/blob/master/poppler/Stream.cc).
La référence de rendu utilise la même précision d’entrée. La conservation
complète des 16 bits est démontrée séparément par comparaison des octets, sans
la déduire d’une image écran 8 bits. LittleCMS et Poppler sont des oracles de
développement, pas des dépendances PHP.

```sh
python tools/validate_png_color.py --report _build/png-colour.json
```

Consulter les [contrats et budgets complets](architecture.md).
