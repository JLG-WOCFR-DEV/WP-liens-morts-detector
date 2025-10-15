# Revue de code et audit RGAA

## Synthèse
- Le plugin structure proprement ses dépendances (activation, cron, assets, pages d’admin) et centralise les préférences UI/accessibilité, ce qui facilite la maintenance.
- Les préférences d’accessibilité (contraste renforcé, réduction des animations, taille de police) sont présentes côté CSS/JS et désactivables dans l’interface, ce qui va dans le sens du RGAA.

## Tests exécutés
- `npm test`

## Points positifs
- Le sélecteur de mode « simple/avancé » est annoncé comme un `role="switch"`, met à jour l’état textuel et appelle une annonce vocale via l’utilitaire `speak`, ce qui répond aux attentes RGAA sur les contrôles dynamiques.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L4041-L4093】【F:liens-morts-detector-jlg/assets/js/settings-mode-toggle.js†L17-L117】
- Les groupes avancés sont rendus avec `role="tablist"`, boutons `role="tab"`, gestion du `tabindex` et masquage via `hidden`, permettant un parcours clavier conforme RGAA.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L4400-L4483】【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L4469-L4526】
- Les thèmes d’accessibilité appliquent des jeux de couleurs à fort contraste et gèrent le mode réduit de mouvement, couvrant les critères RGAA 3.2 et 10.9.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L3-L120】

## Points à améliorer / bugs
1. **Boutons d’aide non contextualisés** – Les boutons d’aide affichent tous l’intitulé « Afficher l’aide » sans préciser le champ ciblé, et le contenu du tooltip n’est lié ni au bouton ni au champ via `aria-describedby`. Un lecteur d’écran ne saura pas à quel champ se rapporte l’aide, ce qui viole les critères RGAA 3.3/9.2. Ajouter un intitulé spécifique (ex. « Aide : délai de relance ») et relier le tooltip au champ concerné résoudrait le problème.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L32-L46】
2. **Fermeture des tooltips limitée à la souris** – Le script ferme les bulles d’aide sur clic extérieur ou Échap, mais pas lorsqu’on quitte le bouton au clavier. On reste avec `aria-expanded="true"` et le tooltip visuellement ouvert après un tab, ce qui contrevient au RGAA 7.1 (perte de focus). Intercepter les événements `focusout`/`blur` pour fermer automatiquement corrigerait cela.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L4370-L4427】
3. **Annonce de l’aide** – Lorsqu’un tooltip s’ouvre, aucun `aria-live` ni message vocal n’est déclenché. Les utilisateurs de lecteurs d’écran n’entendent donc pas le contenu nouvellement disponible, ce qui freine la conformité RGAA 4.1. Une solution consiste à appeler `accessibility.speak` lorsque la bulle s’ouvre ou à appliquer `aria-live="polite"` sur le conteneur actif.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L4370-L4410】

## Recommandations
- Prioriser la correction de la contextualisation et de la fermeture clavier des tooltips pour respecter les critères RGAA les plus critiques.
- Ajouter un test d’accessibilité automatisé (axe-core, pa11y) sur les pages d’admin afin d’éviter les régressions futures.
