# Fonctions prioritaires à renforcer

Cette analyse met en avant les zones du plugin où une application professionnelle de surveillance de liens morts offrirait habituellement plus de robustesse, de modularité et d'observabilité.

## `JLG\BrokenLinks\Scanner\ScanQueue::runBatch`
- **Pourquoi l'améliorer :** la méthode concentre la quasi-totalité de l'orchestration (lecture des réglages, gestion du verrou, planification, heuristiques 404, traitement DOM) dans un seul bloc de plusieurs centaines de lignes, ce qui complique la maintenance et les tests unitaires. 【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L20-L200】
- **Attentes côté solutions pro :** découpage en services dédiés (gestion des options, orchestration des requêtes HTTP, analyse heuristique) et instrumentation native (métriques de durée, taux d'erreur, distribution par type de ressource) pour alimenter des tableaux de bord ou des alertes.
- **Pistes concrètes :**
  - Extraire un gestionnaire de configuration et un orchestrateur de verrou pour isoler les lectures d'options et faciliter les tests par injection de dépendances.
  - Introduire une stratégie d'exécution asynchrone (jobs en file, promesses) afin de mieux exploiter `max_concurrent_requests` au lieu d'une boucle séquentielle.
  - Publier des événements/métriques structurés (succès/échec par ressource, temps moyen par requête) consumables par un système d'observabilité.

## `BlcImageUrlNormalizer::normalize`
- **Pourquoi l'améliorer :** la méthode mélange normalisation d'URL, validations de sécurité, transformation de chemins et journalisation. Les solutions pro séparent ces responsabilités pour offrir des pipelines configurables et extensibles. 【F:liens-morts-detector-jlg/includes/Scanner/ImageUrlNormalizer.php†L50-L208】
- **Attentes côté solutions pro :** normaliseurs modulaires (chaîne de responsabilités), cache des résolutions de chemins et contrôles de conformité configurables (listes blanches/détection CDN).
- **Pistes concrètes :**
  - Découper les vérifications d'hôte, la gestion des chemins et les conversions en objets dédiés pour réduire les branches conditionnelles.
  - Capitaliser les résultats récurrents (résolution d'hôte, chemins uploads) dans un cache partagé pour limiter le coût CPU.
  - Ajouter une interface de politiques de sécurité (par exemple validation IP, CDN approuvés) paramétrable depuis l'administration.

## `blc_update_link_scan_status`
- **Pourquoi l'améliorer :** la fonction valide désormais les transitions et alimente un journal mémoire, mais elle persiste toujours l'état dans une simple option WordPress partagée. Les solutions pro stockent ces statuts dans des tables dédiées avec verrouillage optimiste et historisation longue durée pour éviter les écrasements concurrents et faciliter les audits multi-sites. 【F:liens-morts-detector-jlg/includes/blc-scanner.php†L557-L666】
- **Attentes côté solutions pro :** journalisation durable (table SQL avec clé composite site/job), conservation d'un historique complet et exposition d'API permettant la consultation différée ou l'export BI.
- **Pistes concrètes :**
  - Migrer les statuts et le journal `blc_link_scan_transition_log` vers une table structurée avec identifiant de job et horodatage indexé.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L266-L320】
  - Exposer une couche d'accès transactionnelle (verrous applicatifs, versions) pour sécuriser les mises à jour simultanées depuis WP-Cron et WP-CLI.
  - Offrir une API de consultation paginée/REST pour que les équipes support puissent remonter plusieurs cycles de scans sans manipuler directement les options.

## `JLG\BrokenLinks\Scanner\RemoteRequestClient`
- **Pourquoi l'améliorer :** la classe intègre désormais un backoff exponentiel, une rotation d'User-Agent et le respect de `Retry-After`, mais elle reste limitée à une unique sortie réseau et ne trace pas finement les tentatives. Les offres professionnelles pilotent plusieurs pools de proxys/IP, collectent des métriques détaillées et exposent des compteurs de réussite/échec par domaine. 【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L17-L168】
- **Attentes côté solutions pro :**
  - Gestion de pools (proxys, régions, authentification) avec stratégies de bascule et quotas par cible.
  - Emission de métriques structurées (latence, taille de réponse, nombre de retries) consommables par Prometheus/DataDog.
- **Pistes concrètes :**
  - Introduire une interface de fournisseur de transport permettant d'enregistrer plusieurs connecteurs (HTTP direct, proxy résidentiel, CDN) sélectionnés dynamiquement selon la cible.
  - Ajouter des hooks `blc_http_request_attempted`/`blc_http_request_completed` contenant latence, tentative, code et identifiant de job pour alimenter la télémétrie externe.

# Plan de débogage et tests

Les tests PHPUnit ajoutés se concentrent sur la fiabilité des fonctions de statut d'analyse, base nécessaire avant de refactorer :

- `LinkScanStatusTest::test_get_link_scan_status_enforces_defaults` vérifie la normalisation des données chargées depuis les options (état, compteurs, messages). 【F:tests/LinkScanStatusTest.php†L69-L99】
- `LinkScanStatusTest::test_get_link_scan_status_backfills_completed_end_time` assure que l'heure de fin est bien recalée quand l'analyse s'est terminée. 【F:tests/LinkScanStatusTest.php†L101-L112】
- `LinkScanStatusTest::test_update_link_scan_status_sets_started_timestamp_when_entering_running` & `test_update_link_scan_status_sets_ended_timestamp_when_finishing` sécurisent les transitions temporelles critiques (démarrage/fin). 【F:tests/LinkScanStatusTest.php†L114-L141】
- `LinkScanStatusTest::test_update_link_scan_status_resets_timestamps_when_idle` et `test_reset_link_scan_status_deletes_option` validant respectivement la remise à zéro des timestamps et l'effacement propre de l'état. 【F:tests/LinkScanStatusTest.php†L143-L164】

Ces scénarios servent de garde-fous avant de pousser des refontes plus ambitieuses, et reproduisent les vérifications standard observées dans des solutions professionnelles (cohérence d'état, métriques de progression, récupération après incident).
