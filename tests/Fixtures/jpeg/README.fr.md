# Images JPEG couleur originales

[English](README.md) | Français

Les neuf images de 48 × 32 pixels sont des motifs originaux de six blocs,
sous licence MIT du dépôt. `tools/generate_jpeg_fixtures.py` consigne les versions
des encodeurs et les SHA-256 dans `manifest.json`. Pillow/libjpeg encode les
images gris/RGB/CMYK baseline et progressives. ImageMagick/libjpeg encode le
YCCK Adobe ; le générateur ajoute les segments ICC APP2 complets dans l’ordre
normal et dans un ordre mélangé.

Les profils RGB et gris reprennent les profils originaux des vecteurs PNG.
`cmyk.icc` est un profil d’entrée ICC v2 original et déterministe, avec une LUT
CMYK quatre composantes vers XYZ D50 et un modèle soustractif simple. LittleCMS
l’ouvre et construit indépendamment sa transformation. C’est un profil de test,
pas une calibration d’imprimante. Aucune image ni aucun profil tiers n’est
redistribué ici.

Exécuter `python tools/generate_jpeg_fixtures.py` avec Pillow et ImageMagick pour
régénérer. Les octets JPEG peuvent dépendre des versions des encodeurs : les
empreintes et le manifeste identifient donc le corpus indépendant exact.
Après installation Composer, `python tools/validate_jpeg.py --report jpeg-validation.json`
vérifie les octets image/profil exacts, les dictionnaires PDF et le rendu avec
qpdf, pypdf, Poppler et Pillow/LittleCMS. La comparaison couleur utilise les
réglages LittleCMS de Poppler (sans LUT approximative, compensation du point
noir) et les intérieurs uniformes pour exclure le rééchantillonnage aux bords.
Le raster complet doit aussi égaler celui d’un PDF assemblé indépendamment
avec pypdf.
