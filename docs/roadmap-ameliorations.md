# Feuille de route détaillée des axes d'amélioration

Cette feuille de route décline les pistes d'amélioration listées dans le `README.md` en objectifs mesurables, jalons techniques et indicateurs de suivi. Chaque axe est pensé pour être réalisable par itérations courtes afin de s'intégrer au flux de développement continu du plugin.

## Intégration de rapports automatisés

**Objectifs**
- Offrir un export récurrent (CSV/JSON) des liens cassés et des corrections appliquées pour les équipes éditoriales.
- Permettre la diffusion vers des canaux externes (Google Sheets, stockage S3) afin de partager les résultats sans accès WordPress.

**Backlog priorisé**
1. Créer un scheduler dédié qui agrège les résultats du dernier scan et les sérialise en CSV.
2. Ajouter un connecteur Google Sheets via l'API REST avec rotation automatique des tokens OAuth.
3. Implémenter un upload vers des services S3 compatibles (AWS, Scaleway) avec configuration multi-endpoints.

> ✅ Le scheduler `blc_generate_report_exports` est désormais disponible : il prépare le répertoire `blc-report-exports`, génère un CSV après chaque scan terminé et conserve l'historique des tentatives pour éviter les doublons. 【F:liens-morts-detector-jlg/includes/blc-reports.php†L1-L330】【F:liens-morts-detector-jlg/includes/blc-cron.php†L626-L742】
> ✅ Un second pipeline `blc_schedule_automated_report_generation()` → `blc_generate_automated_report_csv()` orchestre des exports dédiés (liens ou images) via `blc_generate_automated_report`, en normalisant le contexte (job, timestamps, format) et en enregistrant les hooks d'observabilité associés. 【F:liens-morts-detector-jlg/includes/blc-reporting.php†L98-L599】

**Indicateurs de réussite**
- Temps moyen de génération < 30 secondes pour 10 000 liens.
- Taux d'erreurs d'export < 1 % par semaine.
- Adoption : au moins 50 % des clients actifs activent un export automatisé.

## Notifications multicanales

**Objectifs**
- Compléter le webhook générique par des connecteurs Slack, Microsoft Teams et Mattermost.
- Fournir des gabarits de messages adaptés aux statuts (alerte critique, avertissement, rétablissement).

**Backlog priorisé**
1. Mutualiser le moteur de notification (formatage, throttling, historique) dans un service unique.
2. Ajouter un adaptateur Slack avec personnalisation du canal, du titre et des blocs de contenu.
3. Ajouter un adaptateur Teams/Mattermost basé sur les cartes Adaptive Cards.

**Indicateurs de réussite**
- Notifications livrées en < 5 secondes après la fin d'un scan.
- Capacité à router les alertes selon le statut HTTP (4xx, 5xx, soft 404).
- Diminution de 20 % du délai de correction moyen après adoption.

## Optimisations d'interface

**Objectifs**
- Offrir un tableau de bord synthétique (bloc ou widget) récapitulant les métriques clés.
- Améliorer l'accessibilité (navigation clavier, focus visible, attributs ARIA).

**Backlog priorisé**
1. Créer un composant `DashboardSummary` réutilisable avec données issues de `blc_get_link_scan_status_payload()`.
2. Ajouter des tests d'accessibilité automatisés (axe-core) et corriger les problèmes détectés.
3. Documenter une charte UX (taille des cibles tactiles, contrastes, animations).

**Indicateurs de réussite**
- Score axe-core > 95 sur le tableau de bord.
- Temps moyen pour identifier un lien critique réduit de 30 %.
- Retour positif (>4/5) lors des tests utilisateurs internes.

## Renforcement de la qualité logicielle

**Objectifs**
- Couvrir les scénarios critiques par des tests automatisés (PHPUnit, Playwright).
- Mettre en place une intégration continue complète (lint, tests, packaging).

**Backlog priorisé**
1. Étendre la suite PHPUnit aux cas d'échec réseau (`RemoteRequestClient`).
2. Ajouter des tests end-to-end Playwright simulant la correction d'un lot de liens.
3. Configurer GitHub Actions (ou GitLab CI) pour exécuter lint PHP/JS, tests, et publier des artefacts ZIP.

**Indicateurs de réussite**
- Couverture de code PHP > 75 % sur les modules cœur.
- Pipeline CI < 8 minutes en moyenne.
- Zéro régression majeure signalée en production sur deux cycles de release.

## Surveillance proactive

**Objectifs**
- Permettre la définition de seuils d'alerte et d'escalade progressive.
- Historiser les tendances (glissement hebdomadaire, rolling window) pour anticiper les pics d'erreurs.

**Backlog priorisé**
1. Implémenter un gestionnaire de seuils configurable (pourcentage de liens cassés, nombre absolu par taxonomie).
2. Ajouter un module d'escalade (e-mail → webhook → notification push) avec règles de temporisation.
3. Visualiser les tendances sous forme de sparkline dans l'interface et exposer les données via l'API REST.

**Indicateurs de réussite**
- Alertes envoyées à J+0 pour 95 % des pics détectés.
- Réduction de 25 % des incidents découverts par les utilisateurs finaux.
- Adoption de la fonctionnalité par au moins 30 % des sites ayant > 5 000 liens suivis.

## Suivi et gouvernance

- Organiser une revue trimestrielle des métriques et recalibrer les objectifs.
- Documenter chaque incrément dans `docs/changelog.md` avec les impacts pour les utilisateurs.
- Prévoir un canal de feedback (formulaire ou Slack communautaire) pour prioriser les prochains axes.

