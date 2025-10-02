# WP-liens-morts-detector

Liens Morts Detector est une extension WordPress qui détecte les liens et images morts, les signale dans l’administration et propose des outils de réparation rapide. L’analyse des liens peut s’exécuter automatiquement via WP‑Cron, tandis que la vérification des images se lance manuellement depuis le tableau de bord avant de se poursuivre en tâche de fond.

## Fonctionnalités
- Vérification automatique des liens `<a>` grâce à WP‑Cron, et déclenchement manuel des images `<img>` (traitées ensuite en arrière-plan)
- Analyse des liens issus des commentaires, des métadonnées personnalisées et des widgets texte WordPress
- Planification flexible : toutes les heures, toutes les 6 ou 12 heures, quotidienne, hebdomadaire, mensuelle ou intervalle personnalisé
- Tableau de bord listant les liens et images cassés avec statistiques
- Actions rapides pour modifier une URL ou retirer un lien directement depuis la liste
- Options avancées : exclusion de domaines, plages horaires de repos, mode debug
- Option dédiée pour analyser les images servies depuis un CDN ou un domaine externe sécurisé

## Installation
1. Copier le dossier `liens-morts-detector-jlg` dans `wp-content/plugins/`.
2. Activer l’extension depuis le menu **Extensions** de WordPress.
3. Accéder au menu **Liens Morts** pour configurer la fréquence des scans et lancer une première analyse.

## Utilisation
- Les liens sont vérifiés automatiquement selon la fréquence choisie, tandis que les images nécessitent de lancer un scan manuel depuis le rapport (le traitement se poursuit ensuite en arrière-plan).
- La cadence peut être ajustée via un champ combinant intervalles prédéfinis et intervalle personnalisé (slider toutes les X heures + heure de départ). Une action sur le tableau de bord permet de forcer la reprogrammation selon les réglages en cas de blocage de WP‑Cron.
- Les liens ou images détectés comme cassés apparaissent dans une table permettant la modification rapide de l’URL ou la suppression du lien.
- Des réglages avancés permettent d’exclure certains domaines, de limiter l’analyse à des plages horaires et d’activer un mode debug pour le suivi.
- L’analyse des images distantes (CDN, sous-domaines médias) peut être activée dans les réglages. Cette vérification reste basée sur les fichiers présents dans `wp-content/uploads` et peut rallonger la durée du scan ou consommer davantage de quotas côté CDN.

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
