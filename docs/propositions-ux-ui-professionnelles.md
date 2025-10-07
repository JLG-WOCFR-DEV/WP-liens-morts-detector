# Pistes UX/UI inspirées des solutions professionnelles

## 1. Onboarding guidé et presets métiers
- **Constat** : la page des réglages affiche une longue liste de champs techniques (fréquence, pauses, délais, timeouts, heuristiques soft 404) sans regroupement thématique ni aide contextuelle, ce qui suppose une expertise WordPress avancée pour configurer le plugin.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L27-L210】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L211-L498】
- **Inspiration pro** : les plateformes d’audit SaaS proposent un assistant en plusieurs étapes, des presets selon le secteur (agence, e-commerce, institutionnel) et des conseils in-app.
- **Amélioration proposée** : concevoir un wizard en trois étapes (profil d’usage → performance réseau → notifications) avec des textes d’aide, des valeurs recommandées et un mode « configuration rapide » qui charge un preset. Chaque section pourrait être animée via un composant React ou Alpine injecté dans la page Settings pour réduire la charge cognitive à l’instar d’outils comme Screaming Frog ou ContentKing.

## 2. Tableau de bord exécutif actionnable
- **Constat** : l’interface principale se limite à des onglets WordPress standard (Liens, Images, Historique, Réglages) et à des cartes statistiques basiques sans tendances, états critiques ou raccourcis décisionnels.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L19-L109】【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L3-L158】 Les métriques détaillées existent en base (`blc_link_scan_metrics_history`) mais ne sont pas visualisées.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L297-L325】
- **Inspiration pro** : les consoles professionnelles exposent un overview avec KPIs temporels, cartes de santé, tendances, alertes et boutons d’actions rapides (re-scan, export, assignation). 
- **Amélioration proposée** : créer une page d’accueil « Centre de supervision » combinant : (1) graphiques sparkline sur les volumes d’erreurs, (2) badges de sévérité avec codes couleur (critique, à surveiller), (3) timeline des derniers scans et incidents, (4) boutons rapides (« Relancer le scan », « Partager le rapport ») et (5) un encart « Actions suggérées » alimenté par les statuts dominants. Cela alignerait l’expérience sur des solutions comme SiteImprove ou ContentKing.

## 3. Workflows collaboratifs et assignations
- **Constat** : l’accès aux pages est limité à la capacité `manage_options` et le tableau des liens ne prévoit que des actions globales (éditer, dissocier, ignorer) sans notion d’assignation, de commentaire ou de statut personnalisé.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L19-L58】【F:liens-morts-detector-jlg/includes/class-blc-links-list-table.php†L60-L205】
- **Inspiration pro** : les outils d’agences incluent des colonnes « Assigné à », des workflows d’approbation, des tags personnalisés et des intégrations Jira/Asana.
- **Amélioration proposée** : introduire un panneau latéral « Collaboration » permettant de (1) assigner un lien à un rôle éditorial dédié, (2) ajouter des commentaires internes et (3) suivre un statut de résolution (Nouveau, En cours, Corrigé, Vérifié). On pourrait stocker ces informations dans une table personnalisée et exposer des webhooks pour synchroniser l’état vers des outils externes.

## 4. Modale d’action enrichie et recommandations automatiques
- **Constat** : la modale actuelle gère l’accessibilité (focus trap, aria-live) mais reste générique (titre, message, champ URL) sans visuel ni suggestion pour guider la prise de décision.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L119-L148】
- **Inspiration pro** : les interfaces modernes affichent des aperçus, des scores de confiance, des recommandations et des avertissements contextualisés.
- **Amélioration proposée** : enrichir la modale avec (1) un aperçu miniaturisé de la page cible, (2) une suggestion automatique de redirection (basée sur la taxonomie ou l’historique) avec un bouton « Appliquer », (3) un indicateur de risque (ex. trafic estimé) et (4) des badges rappelant les actions récentes. Cela rapprocherait le flux de résolution de suites professionnelles comme Semrush Site Audit.

## 5. Expérience mobile et terrain
- **Constat** : les styles responsive basculent les onglets en pile et élargissent les cartes, mais aucun résumé ni action flottante n’est proposé pour un usage sur tablette lors d’audits terrain.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L55-L138】
- **Inspiration pro** : les applications d’audit mobiles conservent un bandeau supérieur avec KPI clés, un bouton principal toujours visible et des filtres dissimulés dans un tiroir coulissant.
- **Amélioration proposée** : ajouter un header sticky sur mobile affichant le nombre de liens critiques, le temps écoulé depuis le dernier scan et une action « Scanner à nouveau ». Coupler ce header à un tiroir latéral « Filtres » permettrait d’ajuster rapidement l’affichage sans quitter l’écran, améliorant la productivité des consultants en déplacement.
