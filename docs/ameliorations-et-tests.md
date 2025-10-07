# Plan d'amélioration et stratégie de débogage

## Fonctions prioritaires par rapport aux applications professionnelles

- **`blc_schedule_manual_link_scan`** – La fonction se contente de planifier un événement cron unique sans suivi approfondi de l'exécution ni mécanismes de reprise en cas d'échec répété. Les solutions professionnelles offrent souvent un traçage des jobs (ID de tâche, journalisation détaillée, replanification automatique) et l'option de basculer vers une file de traitement dédiée lorsque WP-Cron est inactif. Ici, aucun suivi persistant des tentatives ni délai de réessai exponentiel n'est appliqué, ce qui limite la fiabilité en environnement mutualisé. 【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L147-L208】
- **`blc_perform_check`** – Le scan s'appuie sur un contrôleur mais ne publie pas d'informations de performance (temps par lot, taux d'erreur) ni de granularité sur la récupération en arrière-plan. Les produits professionnels exposent généralement des métriques temps réel, supportent la parallélisation et permettent de prioriser les éléments critiques ; ici, la fonction n'offre ni instrumentation ni adaptation dynamique à la charge serveur. 【F:liens-morts-detector-jlg/includes/blc-scanner.php†L3208-L3235】
- **`blc_update_image_scan_status`** – Le statut `is_full_scan` est forcé à `true` avant l'enregistrement, ce qui empêche de différencier un balayage partiel d'un complet. Les suites pro conservent la granularité de configuration pour personnaliser les scans (par lot, par média critique, etc.), alors que cette implémentation annule l'intention de l'appelant. 【F:liens-morts-detector-jlg/includes/blc-scanner.php†L248-L308】
- **`JLG\BrokenLinks\Scanner\RemoteRequestClient`** – Le client n'encapsule que des appels directs à `wp_safe_remote_*`. Les offres professionnelles proposent des stratégies de retry, la rotation d'agents utilisateurs, la mise en cache des réponses et un plafonnement dynamique du débit pour éviter les blocages réseau, fonctionnalités absentes ici. 【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L5-L20】
## Tests de débogage ajoutés

- **`Tests\BlcManualScanSchedulingTest`** : une nouvelle suite PHPUnit/Brain Monkey qui vérifie le comportement de `blc_schedule_manual_link_scan` lorsque la planification échoue, lorsqu'elle réussit et lorsque le déclenchement manuel via `spawn_cron()` échoue. Ces tests facilitent l'identification rapide des régressions liées à la fiabilité de la planification. 【F:tests/BlcManualScanSchedulingTest.php†L1-L224】
- **`Tests\LinkScanStatusTest`** : enrichi pour couvrir le cache mémoire par requête, la purge via `blc_reset_link_scan_status()` et les métriques exposées dans le payload (pourcentage d'avancement, débit par minute, temps restant estimé). Ces assertions détectent les régressions de performance et garantissent la cohérence des données présentées à l'interface. 【F:tests/LinkScanStatusTest.php†L12-L187】
- **`Tests\BlcReportExportsTest`** : vérifie la synchronisation de la nouvelle planification d'exports, la génération de CSV lorsqu'un scan est terminé et l'enregistrement des tentatives ignorées pour éviter les doublons. Ces tests sécurisent l'itération sur les rapports automatisés. 【F:tests/BlcReportExportsTest.php†L1-L357】

## Améliorations réalisées

- **`blc_get_link_scan_status_payload`** calcule désormais des métriques opérationnelles (pourcentage d'avancement, éléments restants, durée, débit par minute, estimation d'achèvement) et les expose à l'interface et à l'API REST pour un suivi plus transparent. 【F:liens-morts-detector-jlg/includes/blc-scanner.php†L10-L102】【F:liens-morts-detector-jlg/includes/blc-scanner.php†L366-L408】
- **`blc_get_link_scan_status`** et **`blc_get_image_scan_status`** utilisent un cache mémoire par requête pour limiter les lectures répétitives de la base de données lors d'appels successifs, améliorant la charge serveur pendant les écrans de suivi. 【F:liens-morts-detector-jlg/includes/blc-scanner.php†L10-L102】【F:liens-morts-detector-jlg/includes/blc-scanner.php†L446-L544】
- **Exports automatisés** : une nouvelle planification `blc_generate_report_exports` sérialise les résultats du dernier scan en CSV dans `wp-content/uploads/blc-report-exports`, mémorise les tentatives et évite les re-générations inutiles. 【F:liens-morts-detector-jlg/includes/blc-reports.php†L1-L330】【F:liens-morts-detector-jlg/includes/blc-cron.php†L626-L742】【F:liens-morts-detector-jlg/includes/blc-activation.php†L81-L118】

## Tests manuels recommandés

1. **Validation cron avancée** – Utiliser WP-CLI (`wp cron event run blc_manual_check_batch`) pour confirmer que les événements planifiés via l'interface aboutissent bien en environnement réel et mesurer le délai de lancement.
2. **Observation réseau** – Lancer un scan complet tout en surveillant les requêtes sortantes (via un proxy ou `tcpdump`) pour détecter les éventuelles limitations dues à l'absence de gestion de débit et d'en-têtes adaptés.
3. **Simulation d'échec répété** – Configurer un site de test où `wp_schedule_single_event` renvoie `false` (via un mu-plugin) afin d'observer la résilience réelle de la file d'attente et confirmer les besoins en retries automatiques.

## Commandes de test automatisées

```bash
composer install
vendor/bin/phpunit --filter BlcManualScanSchedulingTest
```
Ces commandes garantissent que les stubs nécessaires sont en place et que la suite de tests de planification s'exécute correctement.
