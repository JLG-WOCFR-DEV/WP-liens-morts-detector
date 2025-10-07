# WP-liens-morts-detector

![Panneau de configuration du bloc][block-editor-panel]
> Capture encodée en Base64 (voir `docs/images/block-editor-panel.b64`).
> Pour générer une image locale : `base64 --decode docs/images/block-editor-panel.b64 > block-editor-panel.png`.


Liens Morts Detector est une extension WordPress qui détecte les liens et images morts, les signale dans l’administration et propose des outils de réparation rapide. L’analyse des liens peut s’exécuter automatiquement via WP‑Cron, tandis que la vérification des images se lance manuellement depuis le tableau de bord avant de se poursuivre en tâche de fond.

## Fonctionnalités

### Couverture des contenus
- Exploration des liens dans les widgets texte/HTML, les menus de navigation, les commentaires et les métadonnées personnalisées afin de couvrir les sources hors articles classiques.【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L600-L704】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L707-L781】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L2253-L2305】

### Planification et modes de scan
- Vérification automatique des liens `<a>` via WP‑Cron, avec replanification manuelle possible depuis l’interface (scan complet ou incrémental selon les besoins).【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L147-L220】【F:liens-morts-detector-jlg/includes/blc-scanner.php†L3214-L3245】
- Chaque déclenchement manuel reçoit un identifiant de job, une tentative de replanification automatique en cas d’échec et une historisation persistante (timestamp, statut, erreurs) pour l’audit ou la reprise manuelle.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L149-L226】【F:liens-morts-detector-jlg/includes/blc-scanner.php†L25-L148】
- Réglages fins : fréquences prédéfinies ou personnalisées, plages horaires de repos, délais entre liens ou lots, taille des batchs et timeouts HTTP pour adapter la charge serveur.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L35-L498】
- Le scanner d’images dispose de sa propre file d’attente avec verrouillage, cadence paramétrable et possibilité d’inclure les médias distants servis depuis un CDN ou un domaine externe.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L380-L515】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L247-L305】

### Tableaux de bord et actions rapides
- Deux tables d’administration listent liens et images cassés avec recherche, filtres (type de contenu, interne/externe, statut HTTP) et statistiques agrégées.【F:liens-morts-detector-jlg/includes/class-blc-links-list-table.php†L90-L200】【F:liens-morts-detector-jlg/includes/class-blc-images-list-table.php†L59-L188】
- Actions en ligne et groupées : modifier une URL, proposer/appliquer une redirection détectée, re-vérifier, ignorer/restaurer ou dissocier un lien en conservant l’ancre.【F:liens-morts-detector-jlg/includes/class-blc-links-list-table.php†L530-L881】

### Notifications et suivi
- Notifications par e-mail ou webhook personnalisable, avec choix du canal, du gabarit de message et des catégories de statuts HTTP qui déclenchent un envoi.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L295-L366】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L659-L1669】
- Résumés post-scan envoyés automatiquement depuis le cœur du scanner dès que des destinataires ou un webhook sont configurés.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L1231-L1250】
- Chaque lot publie des métriques (durée, progression, réussite/échec) stockées côté WordPress et exposées via un hook pour alimenter des tableaux de bord externes.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L3214-L3250】

### Automatisation et intégrations
- Endpoint REST (`blc/v1/scan-status`) pour suivre en direct la progression des scans côté JavaScript ou via outils externes.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L412-L473】
- Commandes WP-CLI pour lancer des scans synchrones (liens ou images), forcer un mode complet ou ignorer la plage de repos lors d’un run supervisé.【F:liens-morts-detector-jlg/includes/blc-cli.php†L11-L143】

### Détection avancée
- Heuristiques configurables pour identifier les « soft 404 » (longueur minimale, indicateurs de titre/corps, motifs à ignorer) et alignement entre PHP et JavaScript pour expliquer les faux positifs 200.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L500-L691】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L131-L228】【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L152-L333】

### Résilience réseau et performances
- Le client HTTP applique des timeouts par défaut, une rotation d’User-Agent, une limitation de débit et un backoff exponentiel avec prise en compte de `Retry-After` pour fiabiliser les scans sur des centaines d’URLs.【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L7-L192】
- Le scheduler de liens a été segmenté (préparation, verrous, heuristiques soft 404, exécution) pour faciliter la maintenance et l’instrumentation du cœur d’analyse.【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L1-L180】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L260-L340】

## Installation
1. Copier le dossier `liens-morts-detector-jlg` dans `wp-content/plugins/`.
2. Activer l’extension depuis le menu **Extensions** de WordPress.
3. Accéder au menu **Liens Morts** pour configurer la fréquence des scans et lancer une première analyse.

## Utilisation
- Les liens sont vérifiés automatiquement selon la fréquence choisie, tandis que les images nécessitent de lancer un scan manuel depuis le rapport (le traitement se poursuit ensuite en arrière-plan).
- La cadence peut être ajustée via un champ combinant intervalles prédéfinis et intervalle personnalisé (slider toutes les X heures + heure de départ). Une action sur le tableau de bord permet de forcer la reprogrammation selon les réglages en cas de blocage de WP‑Cron.
- Les liens ou images détectés comme cassés apparaissent dans une table permettant la modification rapide de l’URL ou la suppression du lien.
- Des réglages avancés permettent d’exclure certains domaines, de limiter l’analyse à des plages horaires et d’activer un mode debug pour le suivi.
- Les liens qui répondent en 200 mais affichent une page d’erreur peuvent être détectés grâce à des heuristiques paramétrables (seuil de contenu, motifs de titre/corps, liste d’exclusion).
- La taille des lots analysés peut être ajustée pour s’adapter aux capacités de l’hébergement (de manière optionnelle via l’interface ou un filtre).
- L’analyse des images distantes (CDN, sous-domaines médias) peut être activée dans les réglages. Cette vérification reste basée sur les fichiers présents dans `wp-content/uploads` et peut rallonger la durée du scan ou consommer davantage de quotas côté CDN.

## Détection des soft 404

### Principe des heuristiques
- Chaque réponse HTTP 200 est analysée afin de déterminer si la page correspond à un gabarit d’erreur (soft 404) : longueur minimale du contenu, présence de mots-clés suspects dans le titre ou dans le corps, et correspondance avec des motifs d’exclusion configurables.【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L1896-L1933】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L1941-L1961】
- Lorsque l’une des heuristiques déclenche (et qu’aucun motif d’exclusion ne correspond), le lien est enregistré comme cassé avec le code HTTP reçu, ce qui permet de signaler des faux positifs 200 directement dans la liste des liens.【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L1916-L1933】【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L1941-L1961】

### Réglages disponibles
- Quatre champs dédiés sont exposés dans les réglages : seuil de longueur minimale, liste des titres suspects, gabarits de corps et motifs à ignorer. Chaque champ accepte une valeur par ligne et la syntaxe `/motif/i` permet d’utiliser des expressions régulières insensibles à la casse.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L498-L555】
- Les valeurs par défaut reprennent des gabarits courants (pages 404, profils introuvables, etc.) et peuvent être adaptées à votre contexte éditorial via l’interface ou en important vos propres listes dans ces zones de texte.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L514-L555】

### Personnalisation avancée et filtres
- Les heuristiques peuvent être modulées par code : ajuster le seuil (`blc_soft_404_min_length`), enrichir les listes de motifs (`blc_soft_404_title_indicators`, `blc_soft_404_body_indicators`, `blc_soft_404_ignore_patterns`) ou surcharger le verdict final via `blc_soft_404_detection` pour greffer votre propre logique métier.【F:liens-morts-detector-jlg/includes/blc-utils.php†L1911-L1955】
- Les mêmes fonctions utilitaires exposent les valeurs normalisées, ce qui facilite la construction d’outils annexes ou d’exports alignés sur les réglages actifs.【F:liens-morts-detector-jlg/includes/blc-utils.php†L1911-L1948】

### Interface d’administration et assistance front
- Le module JavaScript `window.blcAdmin.soft404` reprend ces heuristiques côté interface : il normalise la configuration, applique les mêmes règles de nettoyage (suppression des balises, décodage HTML, expressions régulières) et peut être utilisé pour prévisualiser les raisons d’un signalement depuis le navigateur.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L152-L333】
- Les zones de texte des réglages acceptent directement la saisie de motifs ignorés ou d’expressions régulières ; l’interface les convertit en listes prêtes à l’emploi et évite les doublons pour garantir une détection cohérente entre l’admin et le scanner serveur.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L208-L333】

## Commandes WP-CLI
- `wp broken-links scan links` lance immédiatement un lot de vérification des liens. Ajouter `--full` force une réindexation complète, et `--bypass-rest-window` ignore la plage de repos configurée.
- `wp broken-links scan images` exécute le scanner d’images de façon synchrone. Le flag `--full` est accepté pour homogénéité (le mode complet est déjà l’option par défaut).
- Les commandes affichent la progression (lots et éléments traités) ainsi que les messages d’état renvoyés par le scanner. Elles retournent un code de sortie non nul en cas d’échec, ce qui permet une intégration directe dans vos scripts d’automatisation ou jobs de supervision.

## Hooks disponibles
### `blc_cron_schedule_definitions`
Permet d’ajouter, modifier ou supprimer des intervalles WP‑Cron proposés par défaut (heures, jours, semaines…). Chaque entrée doit fournir un identifiant unique ainsi qu’un intervalle en secondes.

```php
add_filter('blc_cron_schedule_definitions', function (array $definitions): array {
    $definitions['quarter_hour'] = array(
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __('Toutes les 15 minutes', 'liens-morts-detector-jlg'),
    );

    return $definitions;
});
```

### `blc_frequency_preset_options`
Personnalise la liste des fréquences affichées dans les réglages (radios + champ personnalisé). Idéal pour mettre en cohérence l’interface avec un nouvel intervalle WP‑Cron ajouté via le filtre précédent.

```php
add_filter('blc_frequency_preset_options', function (array $options): array {
    $options = array('quarter_hour' => __('Toutes les 15 minutes', 'liens-morts-detector-jlg')) + $options;

    return $options;
});
```

### `blc_max_load_threshold`
Permet d’ajuster le seuil de charge CPU au‑delà duquel l’analyse est reportée. La valeur par défaut est `2.0`.

```php
add_filter('blc_max_load_threshold', function (float $threshold): float {
    return 3.5; // Reporter le scan uniquement si la charge instantanée dépasse 3.5.
});
```

### `blc_load_retry_delay`
Définit le délai (en secondes) avant la reprise d’un scan suspendu pour cause de forte charge. La valeur par défaut est `300` secondes.

```php
add_filter('blc_load_retry_delay', function (int $delay): int {
    return 600; // Reprogrammer le scan dans 10 minutes au lieu de 5.
});
```

### `blc_link_batch_size`
Permet de modifier dynamiquement la taille des lots utilisés par le scanner de liens. La valeur par défaut est bornée entre `5` et `200` éléments, mais ces limites peuvent également être ajustées via `blc_link_batch_size_constraints`.

```php
add_filter('blc_link_batch_size', function (int $batchSize, int $batch, bool $isFullScan): int {
    if ($isFullScan) {
        return 50; // Traiter plus d’éléments par lot lors d’une réindexation complète.
    }

    return $batchSize;
});
```

### `blc_link_mass_update_performed`
Déclenché après une mise à jour globale d’un lien via l’interface d’actions rapides. Le hook transmet le résumé de l’opération
 (`status`, `updated`, `failed`, `apply_globally`, `preview`) ainsi que la liste détaillée des contenus modifiés ou en erreur.
Ce filtre est idéal pour enregistrer les changements dans un journal personnalisé ou déclencher des redirections automatiques.

```php
add_action('blc_link_mass_update_performed', function (array $context) {
    foreach ($context['updated_posts'] as $postChange) {
        error_log(sprintf(
            'Lien mis à jour dans %s (%d) : %s → %s',
            $postChange['post_title'],
            $postChange['post_id'],
            $postChange['old_url'],
            $postChange['new_url']
        ));
    }

    foreach ($context['failed_posts'] as $postError) {
        error_log(sprintf(
            'Échec de la mise à jour du lien dans %s (%d) : %s',
            $postError['post_title'],
            $postError['error_message'],
            $postError['post_id']
        ));
    }
});
```

## Pistes d'amélioration

- **Intégration de rapports automatisés** : proposer un export planifié (PDF/CSV ou envoi vers Google Sheets) afin de partager les résultats des scans avec des équipes éditoriales sans accès à l’administration.
- **Notifications multicanales** : ajouter des connecteurs prêts à l’emploi pour Slack, Microsoft Teams ou Mattermost en complément du webhook générique, avec des modèles de messages adaptés aux différents statuts.
- **Optimisations d’interface** : introduire un tableau de bord synthétique (widgets ou bloc Gutenberg) affichant les métriques clés (taux d’erreurs, liens corrigés, tendances) et améliorer l’accessibilité (navigation clavier, ARIA) pour faciliter le suivi quotidien.
- **Renforcement de la qualité logicielle** : étendre la couverture de tests automatisés (PHPUnit/WP-CLI, tests end-to-end Playwright) et configurer l’intégration continue pour détecter rapidement les régressions sur les scénarios critiques.
- **Surveillance proactive** : permettre la configuration d’alertes basées sur des seuils (ex. >5 % de liens cassés sur un site) avec escalade graduelle (mail, webhook, notification push) pour aider à prioriser les corrections.

Une feuille de route détaillée de ces axes (objectifs, backlog priorisé et indicateurs de succès) est disponible dans [`docs/roadmap-ameliorations.md`](docs/roadmap-ameliorations.md) afin de planifier les itérations et mesurer la progression.

## Structure du projet
- `liens-morts-detector-jlg.php` : point d’entrée du plugin, chargement des fichiers, hooks et actions AJAX.
- `includes/` : planification WP‑Cron, fonctions d’activation/désactivation, scanners et pages d’administration.
- `assets/` : ressources CSS et JS pour l’administration.
- `languages/` : fichiers de traduction.

## Développement
- PHP 7.3 ou supérieur et [Composer](https://getcomposer.org/) sont requis pour installer les dépendances de développement.
- Installer les dépendances : `composer install`.
- Installer les dépendances front : `npm install`.
- Exécuter les tests PHP : `vendor/bin/phpunit`.
- Exécuter les tests front : `npm test` (ou `composer test:js`).

## Auteur
Jérôme Le Gousse
