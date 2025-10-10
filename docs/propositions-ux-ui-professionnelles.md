# Pistes UX/UI inspirées des solutions professionnelles

## 1. Onboarding guidé et presets métiers
- **Constat** : la page des réglages affiche une longue liste de champs techniques (fréquence, pauses, délais, timeouts, heuristiques soft 404) sans regroupement thématique ni aide contextuelle, ce qui suppose une expertise WordPress avancée pour configurer le plugin.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L27-L210】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L211-L498】
- **Inspiration pro** : les plateformes d’audit SaaS proposent un assistant en plusieurs étapes, des presets selon le secteur (agence, e-commerce, institutionnel) et des conseils in-app.
- **Améliorations détaillées** :
  1. **Wizard en 3 étapes** (Profil → Paramètres réseau → Alertes) :
     - Chaque écran comporte un texte introductif, une illustration et des champs limités (3–5 maximum) pour éviter la surcharge cognitive.
     - Navigation progressive (boutons « Suivant » et « Retour », indicateur d’étape) et sauvegarde automatique après chaque étape via `wp.ajax.post`.
     - Intégration possible dans la page `options-general.php?page=broken-link-checker` via une section React montée conditionnellement tant que l’utilisateur n’a pas terminé l’onboarding.
  2. **Presets métiers** accessibles dès la première étape :
     - Sélection d’un profil (ex. « Site éditorial », « Boutique WooCommerce », « Collectivité »).
     - Chaque preset remplit des options cachées (délai d’expiration, tolérance Soft 404, nombre de requêtes concurrentes) avec la possibilité de les personnaliser ensuite.
     - Stockage du preset choisi dans une option spécifique (`blc_selected_preset`) pour permettre des recommandations ultérieures.
  3. **Aide contextuelle** :
     - Info-bulles ou panneau latéral affichant des exemples concrets et des liens vers la documentation.
     - Messages de confirmation clairs (« Votre audit est configuré pour 500 URLs toutes les 24 h »).
     - Checklist de fin d’onboarding résumant les points essentiels avec CTA « Lancer un premier scan ».
  4. **Mode configuration rapide** :
     - Bouton « Paramétrer automatiquement » qui sélectionne un preset recommandé selon la taille du site (détection via `wp_count_posts` et `blc_link_query`).
     - Feedback visuel (spinner et message de succès) pour rassurer l’utilisateur.

## 2. Tableau de bord exécutif actionnable
- **Constat** : l’interface principale se limite à des onglets WordPress standard (Liens, Images, Historique, Réglages) et à des cartes statistiques basiques sans tendances, états critiques ou raccourcis décisionnels.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L19-L109】【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L3-L158】 Les métriques détaillées existent en base (`blc_link_scan_metrics_history`) mais ne sont pas visualisées.【F:liens-morts-detector-jlg/includes/blc-scanner.php†L297-L325】
- **Inspiration pro** : les consoles professionnelles exposent un overview avec KPIs temporels, cartes de santé, tendances, alertes et boutons d’actions rapides (re-scan, export, assignation).
- **Améliorations détaillées** :
  1. **Page « Centre de supervision »** en tant que nouvel onglet par défaut :
     - Section « Vue d’ensemble » avec quatre KPI (Liens cassés critiques, Liens redirigés, Temps moyen de correction, Couverture de scan) accompagnés de variations J-7.
     - Composants charts (sparklines ou mini-barres) en SVG/Canvas alimentés par les données de `blc_link_scan_metrics_history`.
  2. **Cartes d’état** par catégorie de problème :
     - Design en cartes de couleur (rouge/orange/bleu/vert) indiquant volume, tendance et bouton « Voir les liens concernés » filtrant la liste.
     - Ajout d’icônes illustratives pour faciliter la hiérarchisation visuelle.
  3. **Timeline et incidents récents** :
     - Liste verticale des scans récents avec horodatage, durée, nombre de liens critiques détectés/corrigés.
     - Tags « Succès », « Attention », « Échec » suivant le résultat du scan.
  4. **Actions rapides** :
     - Boutons primaires (« Relancer un scan complet », « Scanner uniquement les brouillons ») et secondaires (« Exporter CSV », « Partager un rapport »).
     - Les actions déclenchent un toast de confirmation et proposent de planifier un scan récurrent.
  5. **Encart « Actions suggérées »** :
     - Recommandations dynamiques (ex. « 5 liens en erreur 500 depuis plus de 7 jours ») générées via une requête sur les liens non résolus.
     - CTA contextuel (« Assigner à un éditeur », « Créer une redirection ») reliant aux workflows collaboratifs décrits ci-dessous.
  6. **Personnalisation** :
     - Possibilité pour l’utilisateur de masquer/afficher des widgets, dont la configuration est stockée via l’API `user_meta`.

## 3. Workflows collaboratifs et assignations
- **Constat** : l’accès aux pages est limité à la capacité `manage_options` et le tableau des liens ne prévoit que des actions globales (éditer, dissocier, ignorer) sans notion d’assignation, de commentaire ou de statut personnalisé.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L19-L58】【F:liens-morts-detector-jlg/includes/class-blc-links-list-table.php†L60-L205】
- **Inspiration pro** : les outils d’agences incluent des colonnes « Assigné à », des workflows d’approbation, des tags personnalisés et des intégrations Jira/Asana.
- **Améliorations détaillées** :
  1. **Rôles et permissions affinés** :
     - Créer un rôle `blc_manager` héritant de `edit_posts` avec accès aux écrans du plugin.
     - Introduire des capabilities spécifiques (`blc_assign_links`, `blc_manage_workflow`) pour autoriser les assignations sans donner accès aux réglages globaux.
  2. **Panneau latéral « Collaboration »** dans la liste des liens :
     - Slide-over activé via un bouton « Collaborer » sur chaque ligne.
     - Champs : assignation (liste des utilisateurs), statut (Nouveau, Analyse, Résolution, Vérifié), échéance, fil de commentaires.
     - Historique des actions affiché sous forme de timeline avec avatar et horodatage.
  3. **Base de données dédiée** :
     - Table `wp_blc_link_workflow` (link_id, assignee_id, status, due_date, notes, updated_at).
     - Webhooks optionnels (`blc_workflow_updated`) permettant d’envoyer les changements vers des outils externes.
  4. **Notifications et intégrations** :
     - Emails résumant les liens assignés en retard et notifications WordPress (`wp_admin_notice`) ciblées.
     - Connecteurs simples (Zapier, Make) via un endpoint REST `blc/v1/workflows` pour créer/mettre à jour des tâches.
  5. **Filtres et vues sauvegardées** :
     - Possibilité de filtrer par assigné, statut ou priorité et d’enregistrer la vue (« Mes liens critiques », « À valider »).
     - Export CSV ciblé respectant les colonnes de workflow.

## 4. Modale d’action enrichie et recommandations automatiques
- **Constat** : la modale actuelle gère l’accessibilité (focus trap, aria-live) mais reste générique (titre, message, champ URL) sans visuel ni suggestion pour guider la prise de décision.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L119-L148】
- **Inspiration pro** : les interfaces modernes affichent des aperçus, des scores de confiance, des recommandations et des avertissements contextualisés.
- **Améliorations détaillées** :
  1. **Layout modulaire** :
     - Colonne gauche : contexte (page source, statut HTTP, dernière vérification).
     - Colonne droite : actions (remplacer l’URL, ignorer, créer une redirection, assigner).
     - Bandeau supérieur coloré selon la sévérité de l’erreur.
  2. **Aperçu de la page cible** :
     - Miniature générée via un service de screenshot (ou fallback avec favicon + meta title).
     - Bouton « Ouvrir dans un nouvel onglet » et badge affichant le temps de réponse moyen.
  3. **Recommandations automatiques** :
     - Suggestion de redirection basée sur la structure du site (matching slug ou taxonomie) et l’historique des redirections enregistrées.
     - Score de confiance (0–100) pour indiquer la pertinence de la suggestion.
     - Action « Appliquer la recommandation » qui remplit automatiquement le champ URL ou crée une redirection via `wp_redirect`.
  4. **Guide contextuel** :
     - Section « Étapes suivantes » listant 2–3 actions (contacter l’auteur, vérifier la ressource, marquer comme corrigée) avec temps estimé.
     - Zone de notes internes synchronisée avec le workflow collaboratif.
  5. **Accessibilité renforcée** :
     - Raccourcis clavier (←/→ pour naviguer entre les liens, `A` pour assigner, `R` pour remplacer).
     - Annonces ARIA précisant les changements de statut et focus automatique sur la première action disponible.

## 5. Expérience mobile et terrain
- **Constat** : les styles responsive basculent les onglets en pile et élargissent les cartes, mais aucun résumé ni action flottante n’est proposé pour un usage sur tablette lors d’audits terrain.【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L55-L138】
- **Inspiration pro** : les applications d’audit mobiles conservent un bandeau supérieur avec KPI clés, un bouton principal toujours visible et des filtres dissimulés dans un tiroir coulissant.
- **Améliorations détaillées** :
  1. **Header sticky** :
     - Bandeau compact affichant les KPIs critiques (liens cassés, derniers scans, temps depuis la dernière correction).
     - Bouton primaire « Scanner à nouveau » ou « Ajouter une note » toujours visible, adapté aux interactions tactiles.
  2. **Tiroir de filtres** :
     - Icône filtre dans le header ouvrant un volet latéral en plein écran.
     - Filtres rapides (statut, assigné, type de ressource) et tri (priorité, date de détection).
     - Bouton « Sauvegarder cette vue » pour retrouver la configuration sur desktop.
  3. **Cartes compactes** :
     - Présentation en cartes verticales (titre, statut, actions principales) avec gestes swipe (marquer comme résolu, ignorer).
     - Indicateur d’urgence coloré en bordure pour une lecture rapide en mobilité.
  4. **Mode offline / faibles connexions** :
     - Gestion optimisée des requêtes via préchargement minimal et indicateurs d’état (ex. « Connexion instable »).
     - Possibilité de mettre en file les actions (assignations, notes) pour synchronisation dès qu’une connexion est rétablie.
  5. **Support tablette** :
     - Layout en split-view : liste des liens à gauche, détail/modale à droite, pour réduire les allers-retours.
     - Adaptation des tailles de tap targets (>48 px) et espacement suffisant pour l’usage tactile.

## 6. Mode simple vs mode expert pour les réglages
- **Constat** : le formulaire de configuration affiche simultanément les sections essentielles et avancées, avec un seul flux de saisie qui expose immédiatement les paramètres techniques (timeouts, heuristiques, webhooks), même pour les utilisateurs qui n’ont besoin que des réglages de base.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L2669-L2999】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L27-L498】 Les solutions professionnelles segmentent souvent la configuration en un mode « Essentiel » très guidé et un mode « Expert » riche en options.
- **Inspiration pro** : plateformes comme Screaming Frog ou ContentKing proposent un premier écran simplifié, des explications pédagogiques et un basculement clair vers les options avancées, assorti d’un résumé des conséquences.
- **Améliorations détaillées** :
  1. **Toggle persistant** :
     - Ajout d’un switch « Mode expert » au-dessus du formulaire qui masque par défaut les sections avancées et n’affiche que les réglages critiques (fréquence, notifications, préférences d’accessibilité).
     - Stockage du choix par utilisateur (`user_meta`) pour conserver le mode sélectionné sur l’ensemble de l’admin.
  2. **Checklist en mode simple** :
     - Présenter trois cartes récapitulatives (« Planifier les scans », « Recevoir des alertes », « Adapter l’interface ») contenant chacune 1 à 3 options maximum.
     - Inclure des messages ARIA et `wp.a11y.speak` lors de l’activation pour renforcer l’accessibilité et guider les profils non techniques.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L6-L220】
  3. **Regroupement thématique en mode expert** :
     - Réorganiser les tabs actuels en blocs orientés objectifs (« Performance & quotas », « Fiabilité réseau », « Intégrations & webhooks », « Automatisation »), chacun avec un résumé en tête et un CTA contextuel.
     - Ajouter une barre latérale d’ancrage permettant de naviguer rapidement entre les sections, inspirée des consoles professionnelles.
  4. **Prévisualisation des impacts** :
     - Afficher un panneau droit « Conséquences » qui récapitule en temps réel les paramètres modifiés (ex. « Timeout HTTP : 5 s → 10 s », « Webhooks actifs : 2 canaux ») et signale les risques éventuels.
     - En mode expert, proposer un bouton « Générer un profil partageable » qui exporte la configuration courante (JSON ou preset) pour reproduire l’environnement sur d’autres sites.
  5. **Guides et fiabilité** :
     - Intégrer des messages de diagnostic issus de la file d’analyse et du client HTTP (taux d’erreurs récentes, temps moyen de réponse) pour rendre tangible la fiabilité de la plateforme, à l’image des dashboards pro.【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L600-L799】【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L37-L166】
     - Ajouter un bouton « Tester la configuration » qui exécute une requête de validation légère et présente les résultats dans un toast accessible (icônes, contraste, lecture vocale).
