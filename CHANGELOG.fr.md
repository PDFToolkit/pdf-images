# Changelog

[English](CHANGELOG.md) | Français

## Unreleased

## 0.1.9 - 2026-10-15

- Accepter le core stable ^0.8 en conservant les versions précédemment prises en charge.
- Verrouiller la validation sur le core publié 0.7.1 ; vérifier la compatibilité avec le candidat core 0.8 et conserver l’API de conversion et l’analyse bornée.
- Mettre à jour l’installation en français et en anglais ; PHP 8.3–8.5.


## 0.1.8 — 2026-10-14

### Compatibilité et périmètre

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6 || ^0.7`.
- Verrouille les vérifications de publication sur le core publié `0.6.2`, avec des dépendances de production stables et les mêmes versions d’outils de développement.
- Conserve l’analyse bornée PNG/JPEG et les objets image PDF existants. Les métadonnées, le balisage et la sécurité PDF 2.0 restent des mécanismes du writer du core.
- Exige PHP 8.3 ou supérieur ; la CI valide PHP 8.3, 8.4 et 8.5 ainsi que les oracles PDF indépendants.

## 0.1.7 — 2026-10-14

### Compatibilité

- Accepte le core stable `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5 || ^0.6`.
- Verrouille les validations de publication sur le core publié `0.5.5`, sans modifier les versions ou commits des outils de développement.
- Vérifie le module inchangé avec le candidat du core 0.6 sous PHP 8.3, 8.4 et 8.5.
- Conserve l’API de conversion d’images, les formats pris en charge et les limites ; les règles de version PDF de sortie restent dans le core.

## 0.1.6 — 2026-10-14

### Documentation bilingue

- Utilise l'anglais par défaut pour le README, les guides et l'historique complet.
- Ajoute leurs versions françaises intégrales avec liens de langue réciproques et entrées documentaires françaises.
- Traduit les commentaires pédagogiques des exemples en conservant les tokens exécutés et données de sortie.
- Verrouille les validations sur le core publié 0.5.4 sans changer les autres versions ou commits de dépendances.
- Conserve les identifiants d'API, fixtures originales et comportement du runtime.
## 0.1.5 — 2026-10-14

### Installation et publication

- Publie le package sur Packagist et simplifie son installation en une commande
  `composer require` dans un projet consommateur.
- Utilise les dépôts Composer par défaut et relie les métadonnées du package à
  sa documentation sur `https://pdftoolkit.github.io/fr/`.
- Verrouille les vérifications sur le core stable `roukmoute/pdf:0.5.3`, sans
  modifier les versions des outils de développement.
- Vérifie l'indexation des versions publiées et leur installation sans dépôts
  VCS ou locaux. Conserve l'API PHP, les mécanismes et les limites existants.

## 0.1.4 — 2026-10-14

### Documentation

- Relie la documentation commune à `https://pdftoolkit.github.io/fr/` et ajoute
  l'entrée stable des images à `/modules/images/`.
- Confie les guides communs et la publication du site à
  `PDFToolkit/pdftoolkit.github.io`, avec des sources de modules épinglées sur
  leurs versions publiées et leurs commits exacts.
- Corrige la compatibilité décrite dans le README pour refléter la contrainte
  existante `roukmoute/pdf:^0.2 || ^0.3 || ^0.4 || ^0.5`.
- Conserve l'API, les conversions, les dépendances et leurs versions
  verrouillées ; cette version modifie uniquement la documentation.

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
