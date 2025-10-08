# Revue de code

## Points principaux

1. **Injection de formules dans les exports automatisés** — ✅ Résolu
   `blc_generate_automated_report_csv()` applique désormais `blc_escape_report_csv_field()` à chaque colonne exportée pour préfixer les valeurs potentiellement dangereuses d'une apostrophe, empêchant l'exécution de formules lors de l'ouverture dans Excel/LibreOffice. Les tests automatisés vérifient la présence du préfixe sur l'ancre et le contexte. 【F:liens-morts-detector-jlg/includes/blc-reporting.php†L120-L161】【F:liens-morts-detector-jlg/includes/blc-reporting.php†L247-L320】【F:tests/BlcAutomatedReportTest.php†L205-L244】

2. **Écriture CSV sans contrôle d'erreur** — ✅ Résolu
   La génération s'appuie sur un wrapper `blc_write_csv_row()` afin de contrôler chaque écriture CSV. En cas d'échec, la ressource est fermée, le fichier partiellement généré est supprimé via `wp_delete_file()` et un `WP_Error` est renvoyé. Un test simule l'échec pour s'assurer qu'aucun historique n'est enregistré. 【F:liens-morts-detector-jlg/includes/blc-reporting.php†L163-L211】【F:liens-morts-detector-jlg/includes/blc-reporting.php†L452-L511】【F:tests/BlcAutomatedReportTest.php†L246-L314】

3. **Export complet en mémoire**
   `blc_query_report_rows()` charge l'intégralité des lignes correspondant au dataset dans un tableau PHP avant l'écriture. Sur des catalogues volumineux (plusieurs dizaines de milliers de lignes), cette approche explose la consommation mémoire et le temps d'exécution, contrairement aux solutions pro qui streament les résultats par lots. Introduire une itération paginée (curseur SQL, LIMIT/OFFSET) ou un générateur permettrait de réduire le pic mémoire et de mieux s'intégrer à des files de traitement. 【F:liens-morts-detector-jlg/includes/blc-reporting.php†L324-L400】
