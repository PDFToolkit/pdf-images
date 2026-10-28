# Fixtures couleur PNG

[English](README.md) | Français

Quatorze images Adam7 originales couvrent gris/RGB 8/16 bits, palette, alpha,
gAMA/cHRM, les quatre intentions sRGB, la priorité ICC et le gamma nul ignoré.
`manifest.json` enregistre les empreintes source, les octets couleur/alpha
exacts et les profils de référence LittleCMS indépendants. Ces profils ne
sont pas insérés dans les PNG source, sauf le cas explicite de priorité iCCP.

`tools/generate_png_color_fixtures.py` crée les sources avec un encodeur Python
indépendant. LittleCMS 2.19 construit les profils de référence directement
depuis les chromaticités et courbes. Dates/identifiants sont normalisés.
Une autre version de LittleCMS peut changer les métadonnées des profils :
examiner ces différences avant de renouveler les empreintes attendues.
`--check` vérifie la reproduction exacte. Les motifs synthétiques et profils
générés utilisent la licence MIT du dépôt ; les profils portent aussi la
notice LittleCMS d’utilisation sans restriction.

`tools/validate_png_color.py` vérifie indépendamment les échantillons PNG, les
paramètres PDF calibrés, la provenance ICC exacte et le rendu Poppler. La
limite explicite de précision 8 bits du rendu est expliquée dans
[le guide couleur](../../../docs/fr/png-colour.md).
