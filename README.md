# WP-liens-morts-detector

![Panneau de configuration du bloc][block-editor-panel]
> Capture encodée en Base64 (voir `docs/images/block-editor-panel.b64`).
> Pour générer une image locale : `base64 --decode docs/images/block-editor-panel.b64 > block-editor-panel.png`.


Liens Morts Detector est une extension WordPress qui détecte les liens et images morts, les signale dans l’administration et propose des outils de réparation rapide. L’analyse des liens peut s’exécuter automatiquement via WP‑Cron, tandis que la vérification des images se lance manuellement depuis le tableau de bord avant de se poursuivre en tâche de fond.

## Fonctionnalités
- Vérification automatique des liens `<a>` grâce à WP‑Cron, et déclenchement manuel des images `<img>` (traitées ensuite en arrière-plan)
- Analyse des liens issus des commentaires, des métadonnées personnalisées et des widgets texte WordPress
- Planification flexible : toutes les heures, toutes les 6 ou 12 heures, quotidienne, hebdomadaire, mensuelle ou intervalle personnalisé
- Tableau de bord listant les liens et images cassés avec statistiques
- Actions rapides pour modifier une URL ou retirer un lien directement depuis la liste
- Options avancées : exclusion de domaines, plages horaires de repos, mode debug
- Option dédiée pour analyser les images servies depuis un CDN ou un domaine externe sécurisé
- Heuristiques configurables pour identifier les « soft 404 » (longueur minimale, titres et contenus suspects, motifs à ignorer)

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
