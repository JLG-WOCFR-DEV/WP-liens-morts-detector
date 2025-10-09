# Audit ergonomie, UX/UI et qualités logicielles

## Synthèse rapide
- **Portée** : plugin WordPress d'administration pour détecter et gérer les liens et images cassés.
- **Méthode** : revue statique du code et des ressources front (PHP, JS, CSS). Aucune exécution WordPress complète n'a été réalisée.

## Forces par rapport à des extensions professionnelles
- **Structure claire du back-office** : les pages d'administration sont regroupées derrière un menu unique avec sous-onglets, et un composant d'onglets accessible (`aria-current`, focus visibles) permet de circuler entre tableaux, historique et réglages, ce qui s'aligne sur les patterns utilisés par des plugins premium.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L21-L111】【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L86-L144】
- **Options avancées pilotées par API** : l'enregistrement systématique des réglages via l'API Settings avec normalisation/contrainte (`sanitize_callback`) garantit une configuration robuste et comparable à ce que proposent les solutions professionnelles orientées administrateurs techniques.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L20-L200】
- **Performance & robustesse** : la couche de scan applique du retry, du backoff exponentiel et un pool de user agents pour limiter les blocages réseau — un niveau d’attention plutôt rare dans des produits gratuits.【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L7-L186】 La mise en cache des statistiques avec invalidation versionnée réduit la charge SQL sur le tableau de bord.【F:liens-morts-detector-jlg/includes/Admin/DashboardCache.php†L1-L176】
- **Accessibilité travaillée** : les actions JS remplacent les `prompt()`/`confirm()` par des modales localisées, injectent une zone `aria-live` et exploitent `wp.a11y.speak`, ce qui suit les recommandations de l’équipe Gutenberg et se rapproche des standards d’éditeurs commerciaux.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L1-L148】
- **Design admin modernisé** : la feuille de style définit des variables, des modes dark/prefers-reduced-motion et des composants (cards, stats) cohérents avec les UI contemporaines tout en respectant la charte WordPress.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L1-L210】

## Écarts / points perfectibles face aux références du marché
### Ergonomie & présentation des options
- **Charge cognitive élevée** : la page « Réglages » expose de nombreux paramètres techniques (timeouts, batch size, heuristiques soft 404). Les solutions commerciales segmentent généralement ces choix par persona (débutant/avancé) ou les assortissent d’assistants contextuels pour éviter la surcharge.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L20-L200】
- **Absence de workflow guidé** : aucun onboarding (barre de progression, checklist) n’accompagne le premier scan. Les utilisateurs moins experts pourraient ignorer l’ordre d’exécution (planification vs. scan manuel), là où des concurrents proposent un bouton « Lancer un premier audit » mis en avant.

### UX / UI opérationnelle
- **Densité des tables** : les classes `WP_List_Table` sont adaptées aux interfaces WordPress mais restent austères. Les extensions premium modernisent souvent les listes (colonnes responsives, tags colorés, inline editing) pour réduire la friction et la longueur des lignes.【F:liens-morts-detector-jlg/includes/class-blc-links-list-table.php†L52-L200】
- **Manque de visualisations synthétiques** : le tableau de bord ne semble proposer que des cartes et listes textuelles. L’ajout de graphiques d’évolution (histogrammes, sparklines) faciliterait les revues rapides par des équipes SEO.
- **Réactivité mobile perfectible** : si les onglets sont adaptés en mobile, la densité des cartes/statistiques n’est pas explicitement optimisée pour les écrans réduits (pas de breakpoint détaillé pour `.blc-stats-box`). Des plugins premium ajoutent des vues condensées ou empilées par carte.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L200-L272】

### Performance & scalabilité
- **Dépendance WP-Cron** : toute l’automatisation repose sur WP-Cron, sans fallback vers un système d’ordonnancement distant ni surveillance d’échec. Les offres professionnelles offrent parfois un service cloud ou au moins une notification lorsque le cron n’a pas tourné depuis X heures.【F:liens-morts-detector-jlg/includes/blc-cron.php†L15-L199】
- **Agrégations SQL** : la requête `blc_get_top_broken_link_domains` agrège sur la table principale sans indexation apparente sur `url_host`. Sur des sites volumineux, prévoir des index ou un système de pré-calcul serait nécessaire pour rester fluide face à la concurrence.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L120-L160】

### Accessibilité
- **État du focus** : les cartes `.blc-stat` utilisent des effets de translation et de soulignement au survol. Elles prévoient bien un outline focus-visible, mais un rôle explicite (`role="button"`) ou un fallback clavier pour les actions (si ce sont des liens) assurerait une conformité WCAG AA complète.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L210-L284】
- **Annonces vocales partielles** : la zone `aria-live` annonce les succès/erreurs mais aucune mention n’est faite pour informer sur la progression des scans en direct (ETA, pourcentage) côté JS, alors que les données sont calculées dans `blc_enrich_scan_status_metrics`. Une mise à jour périodique dans la région live améliorerait l’équité avec les solutions pro.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L1-L88】【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L107-L148】

### Fiabilité
- **Gestion d’échec utilisateur** : en cas d’impossibilité de planifier des tâches (WP-Cron désactivé), seules des notices PHP sont affichées (`blc_maybe_show_activation_schedule_notice`, non vu ici). Les outils professionnels offrent des diagnostics plus approfondis (tests de boucle, recommandation de WP-CLI). Prévoir une page d’état système serait un plus.
- **Tests automatisés** : le dépôt ne contient ni tests unitaires/integ (hormis un dossier `tests` vide pour JS). Pour viser la fiabilité des solutions commerciales, il faudrait introduire des suites de tests sur le scanner, les heuristiques soft 404, et les actions massives.

## Recommandations concrètes
1. **Segmentation des réglages** : proposer des modes « Simple / Avancé » ou des sections accordéons pour masquer les options réseau tant que l’utilisateur ne les active pas.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L20-L200】
2. **Onboarding & CTA principal** : ajouter une bannière ou un widget « Lancer un premier scan » avec indicateurs de progression pour réduire le temps de prise en main.
3. **Visualisations et résumé KPI** : intégrer des graphes (ex. Chart.js) ou des badges de statut colorés dans les tables pour rejoindre les standards UX des suites SEO.
4. **Monitoring des tâches planifiées** : enregistrer un timestamp du dernier cron réussi et afficher une alerte dédiée (ou envoyer un mail) si le délai dépasse un seuil configurable.【F:liens-morts-detector-jlg/includes/blc-cron.php†L15-L199】
5. **Optimisations SQL** : documenter les index recommandés (ou les créer via l’activation) sur `url_host`, `ignored_at`, `http_status` pour que les vues top domaines restent performantes sur des bases importantes.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L120-L160】
6. **Accessibilité dynamique** : exploiter les métriques de `blc_enrich_scan_status_metrics` pour annoncer la progression à la zone `aria-live`, et vérifier que chaque élément interactif a un rôle accessible explicite.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L1-L88】【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L107-L148】
7. **Pipeline de tests** : initier des tests PHPUnit/Playwright couvrant les cas critiques (retry HTTP, bulk actions, import/export) afin d’atteindre une fiabilité proche des offres professionnelles.

## Bloc Gutenberg / Frontend
Le plugin ne déclare aucun bloc Gutenberg (`register_block_type` absent), la question d’un rendu frontend ne se pose donc pas sur cette version.
