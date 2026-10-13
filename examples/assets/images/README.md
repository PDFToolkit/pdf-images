# Images synthétiques

Ces motifs sont créés pour ce dépôt : aucune photographie, aucun logo, aucune image externe.
Ils sont distribués sous la même licence MIT que le code. `generate.py` les reconstruit avec
Pillow, utilisé uniquement lors du développement ; la bibliothèque PHP n'en dépend pas.

- `rgb.png` et `rgb.jpg` : motif original de 48 × 36 pixels, quatre couleurs.
- `progressive.jpg` : même motif avec codage JPEG progressif.
- `gray.png` et `gray.jpg` : rampe de gris diagonale.
- `rgba.png` : motif RGB avec opacité de gauche à droite.
- `gray-alpha.png` : rampe de gris avec opacité de haut en bas.
- `palette.png` : quatre indices de palette avec les opacités 0, 85, 170 et 255.
- `cmyk.jpg` : conversion CMYK du motif, avec le marqueur Adobe du codec Pillow.

Les différences de pixels JPEG sont attendues : son codage DCT est avec perte.
