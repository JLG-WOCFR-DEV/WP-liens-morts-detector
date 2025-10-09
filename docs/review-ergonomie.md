# Audit ergonomique et recommandations

## Synthèse
- **Points forts :** l’extension propose un éventail d’outils rarement réunis dans un même plugin WordPress (planification fine, heuristiques soft 404, automatisations WP‑CLI) et s’appuie sur une base technique robuste (files de scan, backoff réseau, caches).【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L386-L726】【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L7-L215】【F:liens-morts-detector-jlg/includes/Admin/DashboardCache.php†L17-L176】
- **Points perfectibles (vs. applications professionnelles) :** surcharge cognitive dans les réglages, actions manuelles éclatées, certains choix UI (couleurs, micro-typographie) qui n’atteignent pas les standards SaaS actuels, polling Ajax permanent et absence d’inertie sur les modales qui peuvent impacter performance et accessibilité.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L1520-L1572】【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L3-L120】【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L2681-L2709】

## Ergonomie & présentation des options
**Constat :**
- Les actions manuelles (lancement, annulation, replanification) sont réparties sur plusieurs formulaires successifs, obligeant à faire défiler la page et multipliant les rechargements alors que les concurrents agrègent ces commandes dans un panneau flottant avec feedback immédiat.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L1520-L1572】
- La page de réglages aligne plus de 30 contrôles, dont plusieurs zones de texte avancées, présentés d’un bloc sans hiérarchie visuelle ni segmentation « basique / avancé ». Les labels avec émojis peuvent dérouter dans un environnement pro et réduisent la scannabilité.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L386-L726】

**Recommandations :**
- Fusionner les actions manuelles dans un seul composant latéral (drawer) ou une barre flottante avec boutons primaires / secondaires et toasts success/erreur pour limiter les rechargements et aligner l’expérience sur les consoles de monitoring modernes.
- Introduire des sections accordéon « Réglages essentiels » vs « Réglages avancés », et n’afficher les champs heuristiques (soft 404, CDN…) qu’à la demande. Remplacer les émojis par des intitulés courts + sous-titres descriptifs pour rester cohérent avec l’UI WordPress et faciliter la lecture en liste.

## UX / UI
**Constat :**
- Les cartes et stats utilisent des dégradés, ombres fortes et police 12 px pour les badges de statut, ce qui s’éloigne des interfaces épurées des solutions professionnelles et peut nuire à la lisibilité (contraste et densité visuelle).【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L80-L161】【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L580-L616】
- Les modales réutilisées pour toutes les actions reposent sur un unique gabarit générique, sans icône contextuelle ni différenciation visuelle (succès/danger), ce qui allonge le temps de compréhension par rapport aux patterns UI des suites SaaS (modales contextualisées, side panels).

**Recommandations :**
- Simplifier les cartes (surface unie, ombre légère, bordure discrète) et augmenter la taille des badges/statuts (≥14 px) avec un contraste vérifié WCAG AA. Prévoir un mode compact pour les écrans étroits.
- Décliner les modales : messages de confirmation simples → toast + bouton Undo, actions destructives → modale avec pictogramme danger, actions d’édition → panneau latéral éditable en plein écran pour afficher l’aperçu « Appliquer partout » sans scroller.

## Performance
**Constat :**
- Le panneau de suivi lance un polling Ajax sans condition (replanifié à chaque réponse) même lorsque l’état est « idle », générant un hit permanent toutes les 5–10 s sur l’administration.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L2681-L2709】【F:liens-morts-detector-jlg/includes/Admin/AdminScriptLocalizations.php†L63-L109】
- Le client réseau sérialise les requêtes avec `usleep`, ce qui est fiable mais bloque le worker PHP : sur des hébergements modestes, des scans parallèles peuvent saturer les workers et ralentir le back-office.【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L25-L119】【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L176-L215】

**Recommandations :**
- Arrêter le polling après deux cycles « idle » et le relancer uniquement lors d’un événement utilisateur (clic sur « Lancer ») ou via WebSocket/Server-Sent Events pour une mise à jour réellement temps réel sans surcharge.
- Introduire une fenêtre de tir parallèle (pool de workers) plutôt qu’un `sleep` bloquant : utiliser `wp_remote_request` asynchrone ou un lot côté Action Scheduler pour lisser la charge, et exposer la concurrence max dans les réglages avancés.

## Accessibilité
**Constat :**
- Les palettes soft et les états actifs reposent majoritairement sur des variations de couleur (fond violet clair vs fond gris) sans alternative textuelle, ce qui pénalise les utilisateurs daltoniens.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L317-L374】
- Les modales masquent l’arrière-plan visuellement mais ne désactivent pas le contenu sous-jacent (absence d’attribut `inert` ou d’application d’`aria-hidden` sur le wrapper), laissant la possibilité aux lecteurs d’écran de parcourir la page derrière la boîte de dialogue.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L212-L237】【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L900-L1106】

**Recommandations :**
- Ajouter des marqueurs textuels (ex. « Actif ») ou icônes contrastées dans les onglets/cartes, et vérifier systématiquement le contraste (≥4.5:1) en clair et en sombre.
- Lors de l’ouverture de la modale, appliquer `aria-hidden="true"` (ou `inert`) sur le conteneur principal et restaurer l’attribut à la fermeture pour empêcher la navigation au lecteur d’écran. Envisager un backdrop `<div role="presentation">` pour annoncer la prise de focus.

## Fiabilité
**Constat :**
- En cas d’échec de planification (`wp_schedule_single_event`), le statut est mis à jour mais l’utilisateur n’a pas de guidage pour résoudre (ex : vérifier DISABLE_WP_CRON, trigger manuel). Les solutions pro fournissent généralement un check-list contextualisé.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L205-L274】
- Le mode manuel nettoie systématiquement les hooks existants avant de programmer un nouveau lot, ce qui peut interrompre un scan prolongé si un administrateur relance par réflexe. Un garde-fou (confirmation ou file d’attente) manque par rapport aux workflows industriels.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L209-L238】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L196-L236】

**Recommandations :**
- Enrichir les messages d’erreur avec des CTA (« Consulter la checklist WP-Cron », « Copier la commande WP-CLI ») et logguer les erreurs critiques dans un journal consultable depuis l’UI.
- Ajouter une file d’attente d’actions manuelles : si un scan est en cours, proposer de l’insérer dans une queue plutôt que d’annuler silencieusement. Un bandeau d’avertissement (ou un toast) doit prévenir qu’une replanification effacera le lot courant.

