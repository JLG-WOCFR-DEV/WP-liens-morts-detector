# WP-liens-morts-detector

Liens Morts Detector est une extension WordPress qui détecte les liens et images morts, les signale dans l’administration et propose des outils de réparation rapide. L’analyse peut s’exécuter automatiquement via WP‑Cron ou manuellement depuis le tableau de bord.

## Fonctionnalités
- Vérification automatique des liens `<a>` et des images `<img>` grâce à WP‑Cron
- Planification quotidienne, hebdomadaire ou mensuelle
- Tableau de bord listant les liens et images cassés avec statistiques
- Actions rapides (modifier/dissocier) disponibles pour les liens ; les images proposent un accès direct à l’édition de l’article concerné
- Options avancées : exclusion de domaines, plages horaires de repos, mode debug

## Installation
1. Copier le dossier `liens-morts-detector-jlg` dans `wp-content/plugins/`.
2. Activer l’extension depuis le menu **Extensions** de WordPress.
3. Accéder au menu **Liens Morts** pour configurer la fréquence des scans et lancer une première analyse.

## Utilisation
- La vérification s’exécute automatiquement selon la fréquence choisie ou manuellement via un bouton sur les pages de rapport.
- Les liens et images détectés comme cassés apparaissent dans une table : les liens bénéficient d’actions rapides pour modifier leur URL ou les dissocier, tandis que les images offrent uniquement un raccourci vers l’édition de l’article d’origine.
- Des réglages avancés permettent d’exclure certains domaines, de limiter l’analyse à des plages horaires et d’activer un mode debug pour le suivi.

## Hooks disponibles
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

## Auteur
Jérôme Le Gousse
