# WP-liens-morts-detector

Liens Morts Detector est une extension WordPress qui détecte les liens et images morts, les signale dans l’administration et propose des outils de réparation rapide. L’analyse peut s’exécuter automatiquement via WP‑Cron ou manuellement depuis le tableau de bord.

## Fonctionnalités
- Vérification automatique des liens `<a>` et des images `<img>` grâce à WP‑Cron  
- Planification quotidienne, hebdomadaire ou mensuelle  
- Tableau de bord listant les liens et images cassés avec statistiques  
- Actions rapides pour modifier une URL ou retirer un lien directement depuis la liste  
- Options avancées : exclusion de domaines, plages horaires de repos, mode debug

## Installation
1. Copier le dossier `liens-morts-detector-jlg` dans `wp-content/plugins/`.
2. Activer l’extension depuis le menu **Extensions** de WordPress.
3. Accéder au menu **Liens Morts** pour configurer la fréquence des scans et lancer une première analyse.

## Utilisation
- La vérification s’exécute automatiquement selon la fréquence choisie ou manuellement via un bouton sur les pages de rapport.
- Les liens ou images détectés comme cassés apparaissent dans une table permettant la modification rapide de l’URL ou la suppression du lien.
- Des réglages avancés permettent d’exclure certains domaines, de limiter l’analyse à des plages horaires et d’activer un mode debug pour le suivi.

## Structure du projet
- `liens-morts-detector-jlg.php` : point d’entrée du plugin, chargement des fichiers, hooks et actions AJAX.
- `includes/` : planification WP‑Cron, fonctions d’activation/désactivation, scanners et pages d’administration.
- `assets/` : ressources CSS et JS pour l’administration.
- `languages/` : fichiers de traduction.

## Auteur
Jérôme Le Gousse
