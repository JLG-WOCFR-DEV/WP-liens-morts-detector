# Presets graphiques inspirés de bibliothèques UI modernes

Ce document propose des presets UI prêts à l'emploi pour le plugin "Liens morts detector", chacun inspiré d'une bibliothèque ou d'un framework populaire. Chaque preset combine principes visuels, composants typiques, micro-interactions et usage recommandé.

## 1. Preset "Headless Minimal"
- **Inspiration** : [Headless UI](https://headlessui.com/)
- **Philosophie** : Fournir des composants accessibles sans opinion visuelle forte, afin d'être facilement adaptés à la charte WordPress existante.
- **Palette** : tons neutres (`#111827`, `#374151`, `#6B7280`) avec accents bleus (`#2563EB`).
- **Typographie** : `Inter` ou `Source Sans Pro`, 16 px base, interlignage 1,5.
- **Composants clés** :
  - Panneaux accordéon pour les sections d'options avancées.
  - Dialogues modaux gérés via `@headlessui/react` (focus trap, transitions).
  - Menus déroulants avec recherche intégrée pour les presets métiers.
- **Micro-interactions** : Transitions CSS discrètes (150 ms, `ease-out`), highlight au clavier.
- **Cas d'usage** : Onboarding, modales d'action, tiroirs de filtres.

## 2. Preset "Shadcn Clean"
- **Inspiration** : [shadcn/ui](https://ui.shadcn.com/)
- **Philosophie** : Combiner la puissance de Radix UI avec un design système sobre et prêt à theming.
- **Palette** : gris chauds (`#111111`, `#1F1F1F`, `#F5F5F5`) et accent vert `#22C55E` pour signaler la réussite.
- **Typographie** : `Geist Sans`, boutons uppercase 14 px.
- **Composants clés** :
  - Cards avec bordures 1 px et ombres diffuses (`0 10px 20px rgba(15, 23, 42, 0.08)`).
  - Tabs + table responsive pour le tableau des liens.
  - Toast notifications stackées en bas à droite.
- **Micro-interactions** : Hover-lift (`translateY(-2px)`), skeleton loading pour listes.
- **Cas d'usage** : Dashboard exécutif, workflow collaboratif.

## 3. Preset "Radix Structured"
- **Inspiration** : [Radix UI](https://www.radix-ui.com/)
- **Philosophie** : Maximiser l'accessibilité, les tokens de design et les transitions orientées état.
- **Palette** : tokens Radix Slate + accent violet (`#7C3AED`).
- **Typographie** : `Work Sans` 15 px, titres en `500`.
- **Composants clés** :
  - `Tabs`, `Popover`, `Tooltip`, `Toast` issus de Radix pour orchestrer les interactions complexes.
  - Slider de réglage pour les fréquences de scan.
  - `AlertDialog` pour confirmer les actions destructrices.
- **Micro-interactions** : transitions basées sur les states Radix (`data-state="open"`).
- **Cas d'usage** : Paramétrage avancé, confirmation d'actions.

## 4. Preset "Bootstrap Audit"
- **Inspiration** : [Bootstrap 5](https://getbootstrap.com/)
- **Philosophie** : Rapidité de mise en place avec composants familiers pour les administrateurs WordPress.
- **Palette** : `primary` bleu (`#0d6efd`), `success` vert (`#198754`), `warning` jaune (`#ffc107`).
- **Typographie** : `system-ui`, `1rem` base.
- **Composants clés** :
  - Layout en grid responsive (`row`/`col`) pour les cards KPI.
  - `Accordion` pour les sections FAQ et aide contextuelle.
  - `Offcanvas` pour le tiroir de filtres mobile.
- **Micro-interactions** : transitions `fade` Bootstrap, `spinner-border` pour les chargements.
- **Cas d'usage** : Tableaux et rapports, version lite du dashboard.

## 5. Preset "Semantic Insight"
- **Inspiration** : [Semantic UI](https://semantic-ui.com/)
- **Philosophie** : UI expressive avec icônes et labels colorés pour mettre l'accent sur la hiérarchisation des erreurs.
- **Palette** : Couleurs Semantic (`blue`, `green`, `orange`, `red`) combinées à des fonds clairs.
- **Typographie** : `Lato`, 15 px, titres `600`.
- **Composants clés** :
  - `Statistic` pour visualiser rapidement les KPIs.
  - `Label` et `Ribbon` pour indiquer la sévérité des liens.
  - `Steps` pour l'onboarding guidé.
- **Micro-interactions** : `transition` 200 ms `ease` sur les boutons et les cartes.
- **Cas d'usage** : Centre de supervision, timeline des incidents.

## 6. Preset "Anime Motion"
- **Inspiration** : [Anime.js](https://animejs.com/)
- **Philosophie** : Ajouter des animations vectorielles fluides pour renforcer le feedback utilisateur sans alourdir l'interface.
- **Palette** : Neutres foncés + accent cyan (`#06B6D4`) pour les animations.
- **Typographie** : `Manrope`, 16 px, titres `600`.
- **Composants clés** :
  - Charts SVG animés (courbes de progression, compteurs).
  - `Timeline` animée pour les scans récents.
  - Boutons avec effet ripple subtil.
- **Micro-interactions** :
  - Animations de transition `anime.js` pour les modales (`opacity`, `scale`).
  - `stagger` sur l'apparition des cartes et des listes.
  - Animation des compteurs KPI (`value` de 0 au total en 500 ms).
- **Cas d'usage** : Dashboard, onboarding, feedback post-scan.

## 7. Conseils de mise en œuvre
- Utiliser des tokens de design centralisés (SCSS, CSS custom properties ou Theme UI) pour changer de preset sans réécrire les composants.
- Prévoir un sélecteur de preset dans les options du plugin :
  - Choix via `radio group` affichant une prévisualisation miniature.
  - Stockage dans `blc_ui_preset` pour conditionner l'enqueue des styles/scripts.
- Isoler les assets dans `assets/presets/<nom>` avec un SCSS principal et, selon le besoin, un bundle JS.
- Mettre en place des tests visuels (Storybook Chromatic ou Playwright) pour assurer la cohérence entre presets.
- Documenter chaque preset (tokens, composants, comportements) dans Storybook ou dans une section dédiée du guide contributeur.

## 8. Roadmap suggérée
1. Prioriser "Headless Minimal" et "Shadcn Clean" pour couvrir les besoins "sans style" et "design systémique".
2. Ajouter "Radix Structured" pour les cas avancés et réutiliser ses primitives dans les autres presets.
3. Introduire "Bootstrap Audit" comme option rétro-compatible pour les équipes familières avec Bootstrap.
4. Déployer "Semantic Insight" une fois le workflow collaboratif en place.
5. Intégrer "Anime Motion" comme couche optionnelle d'animations, activable dans les réglages.

