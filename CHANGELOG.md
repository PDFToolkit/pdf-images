# Changelog

## Unreleased

## 0.1.3 — 2026-10-13

### Compatibilité

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5`.
- Verrouille les vérifications sur le core publié `0.4.0`, sous PHP 8.3, 8.4 et 8.5.
- Conserve l'API et les limites des convertisseurs ; aucun traitement de format d'image
  supplémentaire n'est ajouté par ce correctif de compatibilité.

## 0.1.2 — 2026-10-13

### Compatibilité

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4`.
- Conserve l'API du module et sa frontière de traitement des données externes.
- Vérifie le core stable 0.3.0 et les nouveaux objets du core 0.4 en développement,
  sous PHP 8.3, 8.4 et 8.5.

## 0.1.1 — 2026-10-13

### Compatibilité

- Accepte le core `roukmoute/pdf:^0.2 || ^0.3`, pour permettre l'installation
  avec les nouveaux objets PDF de la série 0.3 sans modifier l'API du module.
- Revalide les tests et les PDF indépendants avec le core stable `0.2.1`.
  Aucun mécanisme de lecture ou de conversion n'est ajouté par ce correctif.

## 0.1.0 — 2026-10-13

Première version de `pdftoolkit/pdf-images`, sous `PDF\Images`.
Le module dépend du core `roukmoute/pdf:^0.2`, qui conserve les objets image PDF
normalisés ; le core ne dépend pas des convertisseurs d'images.

### Ajouts

- Neuf composants pour convertir des octets PNG ou JPEG vers `SampledImage`
  et `DCTImage`, sans accès implicite à un fichier ou une URL.
- Décodage des PNG statiques non entrelacés à huit bits par composante : gris,
  RGB, palette, gris avec alpha et RGBA.
- Validation des signatures, de l'ordre des chunks, des tailles et des CRC PNG ;
  reconstruction des cinq filtres de scanline et développement des palettes.
- Séparation de l'alpha, y compris `tRNS`, pour construire explicitement le
  masque `/SMask` avec les objets du core.
- Lecture des marqueurs, tables, trames et composantes des JPEG Huffman DCT
  baseline ou progressifs à huit bits : gris, RGB et CMYK Adobe avec transformée zéro.
- Conservation des octets JPEG d'origine et détermination des paramètres PDF
  de couleur, de transformation et d'inversion.
- Limites par instance pour les données encodées et décodées, les dimensions,
  les pixels et le nombre de chunks ou de marqueurs ; inflation PNG bornée.
- Exemple de quatre images avec transparence et fixtures originales sous licence MIT.
- Tests des échantillons, de l'alpha, des octets conservés, des structures invalides
  et des limites ; vérifications sur PHP 8.3, 8.4 et 8.5.

Les PNG animés, entrelacés ou d'autres profondeurs, les profils ICC intégrés,
les JPEG YCCK et les sémantiques couleur ambiguës sont refusés. Les métadonnées
EXIF ne déclenchent pas de rotation automatique. Le module n'effectue pas le
décodage entropique des coefficients JPEG ; le lecteur PDF assure leur rendu.
