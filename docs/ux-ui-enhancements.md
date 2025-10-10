# Opportunités UX/UI supplémentaires face aux outils professionnels

Ce document recense des axes d'amélioration identifiés pour rapprocher l'expérience du détecteur de liens morts de celle proposée par des suites professionnelles.

## 1. Supervision consolidée
- **Constat** : la supervision des scans manuels est actuellement limitée à la zone « Statut du scan manuel » sur la page d'administration dédiée.
- **Référence** : `liens-morts-detector-jlg/includes/blc-admin-pages.php`, lignes 2600-2716.
- **Proposition** :
  - Créer une vue récapitulative épinglée (bandeau global ou centre de notifications) présentant les derniers incidents, la durée moyenne des scans et l'état des files.
  - Offrir un accès rapide aux commandes essentielles pour se rapprocher d'une supervision type NOC.

## 2. Vues enregistrées et segmentations
- **Constat** : la liste des liens repose sur un formulaire GET qui ne permet pas de sauvegarder des filtres personnalisés.
- **Référence** : `liens-morts-detector-jlg/includes/blc-admin-pages.php`, lignes 2745-2760.
- **Proposition** :
  - Introduire des collections de filtres enregistrables et partageables entre utilisateurs.
  - Ajouter des badges de sévérité sur les lignes pour accélérer le tri et faciliter les workflows d'équipe.
- **Mise à jour** : le rapport des liens propose désormais un panneau « Segments enregistrés » pour mémoriser, appliquer et supprimer des combinaisons de filtres privées à chaque compte.
- **Nouvelle amélioration** : les utilisateurs peuvent définir une vue enregistrée par défaut, appliquée automatiquement lors de l'ouverture du rapport, pour coller aux pratiques des suites professionnelles.

## 3. Guidage de configuration
- **Constat** : toutes les options de configuration (fréquences, pauses, timeouts, notifications) sont exposées sur une seule page.
- **Référence** : `liens-morts-detector-jlg/includes/blc-settings-fields.php`, lignes 52-200.
- **Proposition** :
  - Mettre en place un assistant de configuration qui priorise les paramètres essentiels et propose des presets en fonction du type d'hébergement.
  - Ajouter des contrôles de cohérence (ex. vérifier la compatibilité entre timeouts et tailles de batch).

## 4. Système visuel différenciant
- **Constat** : la feuille de style actuelle reste proche de l'esthétique WordPress par défaut.
- **Référence** : `liens-morts-detector-jlg/assets/css/blc-admin-styles.css`, lignes 140-189.
- **Proposition** :
  - Définir un set d'icônes dédiées et des micro-animations conditionnelles (changements d'état du scan, succès/échecs).
  - Concevoir des tuiles synthétiques pour les KPI clés afin de renforcer la perception premium tout en conservant l'accessibilité.

## 5. Étapes suivantes
- Prioriser les chantiers selon l'effort estimé et l'impact utilisateur.
- Prototyper les éléments d'interface clés (tableau de bord, segments enregistrés, assistant).
- Prévoir des tests utilisateurs ciblés pour valider la pertinence des nouvelles interactions.

