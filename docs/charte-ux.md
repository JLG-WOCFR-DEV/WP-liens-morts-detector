# Charte UX & Accessibilité — Liens Morts Detector (interface admin)

Cette charte décrit les conventions graphiques et d’interaction appliquées aux composants d’administration du plugin. Elle sert de référence pour la conception, l’implémentation et la revue des interfaces, y compris pour les audits d’accessibilité automatisés.

## Typographies

- **Famille principale** : `Inter`, avec repli sur `"Segoe UI"`, `Roboto`, puis `sans-serif` (déclaré via `--blc-admin-font-family`).
- **Hiérarchie** :
  - Titres de sections (`h1`–`h2`) : 1.35 rem par défaut, 1.25 rem en affichage réduit (`max-width: 782px`).
  - Libellés secondaires et sous-titres : 0.95 rem avec couleur atténuée (`--blc-admin-text-subtle`).
  - Labels utilitaires (ex. métriques) : 0.85 rem, uppercase, espacement de 0.08 em.
  - Valeurs clés : 1.85 rem, graisse 700, ligne à 1.1.
- **Accessibilité** :
  - Les textes doivent rester lisibles à 200 % de zoom. Les lignes sont compactes (1.3 – 1.5 selon le contexte) et l’espacement vertical est assuré par les gaps des conteneurs.
  - La classe `blc-accessibility--large-font` (préférences utilisateur) doit conserver une base 16 px et respecter ces proportions.

## Palette de couleurs

Les couleurs sont définies via des variables CSS (cf. `:root` dans `liens-morts-detector-jlg/assets/css/blc-admin-styles.css`). Les combinaisons suivantes assurent un contraste ≥ 4.5:1 pour le texte principal.

| Usage | Variable(s) | Couleur | Couleur de texte associée | Ratio de contraste |
| --- | --- | --- | --- | --- |
| Surface principale | `--blc-admin-surface` | `#fcfcfd` | `--blc-admin-text` (`#11181c`) | 15.3:1 |
| Surface secondaire | `--blc-admin-surface-subtle` | `#f5f6f8` | `--blc-admin-text` | 13.0:1 |
| Texte atténué | `--blc-admin-text-subtle` | `#687076` | — | — |
| Accent | `--blc-admin-accent` | dépend du thème WP | texte inversé ou bordures, jamais seul |
| Statut succès | `--blc-admin-success-bg` (`#e5fbeb`) | `--blc-admin-success-text` (`#31694a`) | 5.95:1 |
| Statut info | `--blc-admin-info-bg` (`#e4ecff`) | `--blc-admin-info-text` (`#3c4ae0`) | 5.46:1 |
| Statut avertissement | `--blc-admin-warning-bg` (`#fff1d0`) | `--blc-admin-warning-text` (`#8a4600`) | 6.34:1 |
| Statut critique | `--blc-admin-danger-bg` (`#ffe5e0`) | `--blc-admin-danger-text` (`#b02a1c`) | 5.49:1 |
| Mode neutre | `--blc-admin-surface-subtle` | `--blc-admin-text` / `--blc-admin-text-subtle` | ≥ 4.6:1 |

> 📝 **Note** : Les thèmes WP peuvent écraser l’accent (`--wp-admin-theme-color`). Veiller à vérifier les contrastes lorsque la charte est intégrée à un thème personnalisé.

## États d’interaction

- **Repos** : cartes et résumés ont une bordure 1 px (ou 6 px à gauche pour `--accent`), une ombre légère et un fond conforme aux surfaces définies.
- **Survol (`:hover`)** : translation verticale maximum de 2 px, ombre amplifiée. Ne pas modifier la couleur de fond pour éviter de dégrader le contraste.
- **Focus clavier (`:focus-visible`)** :
  - Utiliser un contour de 2 px `outline` ou `box-shadow` basé sur `--blc-admin-accent` (ou `--blc-admin-border` en mode contraste élevé), avec rayon cohérent (`--blc-admin-radius-md`).
  - Le focus doit être implémenté sur les éléments interactifs (`a`, `button`, `[role="tab"]`, contrôles de formulaire). Les éléments purement informatifs ne deviennent pas focusables.
- **État actif** : conserver la variation de couleur ou d’ombre existante, en veillant à ne jamais supprimer l’indicateur de focus.

## Mouvements et animations

- Transition standard : `var(--blc-admin-transition)` (180 ms, courbe `cubic-bezier(0.22, 1, 0.36, 1)`).
- Réduction de mouvement : la classe `blc-accessibility--reduce-motion` doit désactiver transitions et animations (`transition-duration: 0.001ms`, etc.). Tout nouvel effet doit respecter cette mécanique.
- Les animations doivent être discrètes (translation < 4 px, opacité) et ne pas introduire de flashs.

## Composants admin clés

### Résumé du tableau de bord (`.blc-dashboard-summary`)

- Bloc `section` avec `aria-labelledby` pointant sur le titre `h2`.
- Ajouter `aria-describedby` vers le sous-titre pour inclure le contexte dans le focus SR.
- Liste non ordonnée (`ul`) avec items (`li`) affichant label, valeur et description.
- Variantes (`--success`, `--info`, `--warning`, `--danger`, `--neutral`) utilisent les couleurs du tableau ci-dessus et conservent les contrastes à 4.5:1 minimum.

### Cartes statistiques (`.blc-stat`)

- Bouton simulé `<a>` avec `aria-label` dynamique et badge visuel `Actif` + équivalent SR.
- Focus visible via halo accentué, hover avec légère translation uniquement.

## Audit accessibilité axe-core

1. Exporter les variables `WP_E2E_BASE_URL`, `WP_E2E_USERNAME`, `WP_E2E_PASSWORD` et (le cas échéant) `WP_E2E_STORAGE_STATE`.
2. Lancer `npm run test:e2e -- --grep @a11y` pour exécuter les audits axe-core (réglages + synthèse dashboard).
3. Examiner le rapport HTML généré dans `playwright-report/` en cas d’échec.
4. Corriger les violations sérieuses ou critiques avant de valider la PR.

## Revue de code

- Vérifier la cohérence avec la présente charte (typographies, palette, focus/hover, réduction des animations).
- Refuser toute régression de contraste ou suppression d’indicateur de focus.
- S’assurer qu’un audit axe-core (Playwright) a été exécuté et archivé dans la PR.

