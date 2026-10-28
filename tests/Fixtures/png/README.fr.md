# Fixtures PNG étendues

[English](README.md) | Français

35 images synthétiques originales couvrent toutes les combinaisons PNG légales,
avec et sans Adam7, les cinq filtres, les clés de transparence à pleine précision
et les profils ICC gris/RGB. `manifest.json` conserve le SHA-256 de chaque PNG,
les octets et précisions couleur/alpha attendus et le SHA-256 du profil.

`tools/generate_png_fixtures.py` est un encodeur PNG indépendant en Python.
LittleCMS système crée les deux profils couleur originaux ; date de création
et identifiants optionnels sont normalisés pour la reproductibilité. Ces profils
ne sont ni des certificats ni des identités. Toutes les fixtures sont sous la
licence MIT du dépôt, sans images tierces ni métadonnées confidentielles.

`tools/validate_png.py` décode indépendamment les PNG avec Pillow et
ImageMagick/libpng, vérifie les octets PDF avec pypdf et rend chaque cas avec
Poppler. ImageMagick Q16 permet une comparaison couleur/alpha exacte lorsque
les conversions Pillow perdent de la précision ou une clé tRNS non 8 bits.
Les états des passes et filtres Adam7 ne sont pas partagés avec le code PHP.

Régénération : Python et LittleCMS. Validation : dépendances Python épinglées,
PHP, qpdf, Poppler (`pdftoppm`) et ImageMagick Q16 (`convert` ou `--imagemagick magick`).
