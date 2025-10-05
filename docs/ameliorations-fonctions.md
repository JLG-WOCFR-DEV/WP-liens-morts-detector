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
- **Pourquoi l'améliorer :** la fonction applique la logique métier directement dans la mise à jour des options, sans piste d'audit ni gestion des transitions interdites. Les outils pro conservent l'historique des transitions et exposent des états enrichis (durées, progression). 【F:liens-morts-detector-jlg/includes/blc-scanner.php†L73-L138】
- **Attentes côté solutions pro :** machine à états explicite, validations contextuelles (ex. empêcher `completed` sans `processed_items`), et historisation des événements pour faciliter le support.
- **Pistes concrètes :**
  - Introduire un validateur de transition afin de bloquer les séquences incohérentes (ex. retour à `running` depuis `failed` sans reset).
  - Journaliser chaque changement (timestamp, acteur) dans une table dédiée ou via un hook d'observabilité.
  - Calculer et stocker des métriques dérivées (progression %, durée totale) afin de rapprocher l'UI de standards pro.

## `JLG\BrokenLinks\Scanner\RemoteRequestClient`
- **Pourquoi l'améliorer :** l'adaptateur HTTP se contente de déléguer à `wp_safe_remote_*` sans configuration des délais, redirections ou instrumentation. Les solutions professionnelles exposent un client résilient avec backoff, traçage et statistiques réseau. 【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L7-L20】
- **Attentes côté solutions pro :**
  - Paramétrage centralisé des timeouts, limites de redirections, politiques TLS et suivi des tentatives.
  - Support natif des journaux structurés et des identifiants de corrélation pour debugger les scans à grande échelle.
- **Pistes concrètes :**
  - Permettre l'injection d'options (timeouts, en-têtes) et implémenter une logique de retry exponentiel conditionnel.
  - Exposer des hooks ou callbacks pour enregistrer les durées, codes et erreurs dans un système de télémétrie.

# Plan de débogage et tests

Les tests PHPUnit ajoutés se concentrent sur la fiabilité des fonctions de statut d'analyse, base nécessaire avant de refactorer :

- `LinkScanStatusTest::test_get_link_scan_status_enforces_defaults` vérifie la normalisation des données chargées depuis les options (état, compteurs, messages). 【F:tests/LinkScanStatusTest.php†L69-L99】
- `LinkScanStatusTest::test_get_link_scan_status_backfills_completed_end_time` assure que l'heure de fin est bien recalée quand l'analyse s'est terminée. 【F:tests/LinkScanStatusTest.php†L101-L112】
- `LinkScanStatusTest::test_update_link_scan_status_sets_started_timestamp_when_entering_running` & `test_update_link_scan_status_sets_ended_timestamp_when_finishing` sécurisent les transitions temporelles critiques (démarrage/fin). 【F:tests/LinkScanStatusTest.php†L114-L141】
- `LinkScanStatusTest::test_update_link_scan_status_resets_timestamps_when_idle` et `test_reset_link_scan_status_deletes_option` validant respectivement la remise à zéro des timestamps et l'effacement propre de l'état. 【F:tests/LinkScanStatusTest.php†L143-L164】

Ces scénarios servent de garde-fous avant de pousser des refontes plus ambitieuses, et reproduisent les vérifications standard observées dans des solutions professionnelles (cohérence d'état, métriques de progression, récupération après incident).
