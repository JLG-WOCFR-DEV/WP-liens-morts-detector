# Revue de code

## Points principaux

1. **Injection de formules CSV possible dans les exports**  
   `blc_write_report_export()` écrit directement dans le fichier CSV les valeurs issues de la base (URL, titre d’article, extrait de contexte, etc.) sans neutraliser les préfixes dangereux (`=`, `+`, `-`, `@`). Un auteur malveillant peut injecter un contenu qui déclenchera une formule lors de l’ouverture du fichier dans Excel/LibreOffice, ce qui ouvre la porte à de l’exfiltration de données ou à l’exécution de commandes externes. Il faut préfixer ces champs (par exemple avec une apostrophe) avant de les passer à `fputcsv`. 【F:liens-morts-detector-jlg/includes/blc-reports.php†L241-L306】

2. **Gestion incomplète des erreurs d’écriture CSV**  
   Le retour de `fputcsv()` n’est jamais contrôlé. En cas de disque plein ou d’erreur E/S, la fonction renverra `false` mais la génération sera quand même considérée comme un succès, ce qui produira un fichier partiel/corrompu et un historique erroné. Il faut vérifier le résultat de chaque `fputcsv()` et retourner un `WP_Error` en cas d’échec. 【F:liens-morts-detector-jlg/includes/blc-reports.php†L287-L306】

3. **Nom de fichier d’export non normalisé**  
   Le nom du dataset (`$dataset_type`) est injecté tel quel dans le nom du fichier exporté. Ce nom peut être altéré via le filtre `blc_report_export_datasets`, y compris par un autre plugin moins fiable, et contenir des caractères inattendus (`..`, retours chariot, etc.). Sans nettoyage, on risque d’obtenir des chemins peu sûrs ou illisibles. Il est préférable de restreindre ce nom à une whitelist (`[A-Za-z0-9_-]`) avant de l’utiliser dans le `sprintf`. 【F:liens-morts-detector-jlg/includes/blc-reports.php†L241-L319】
