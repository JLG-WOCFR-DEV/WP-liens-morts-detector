# Pistes UX/UI inspirées des solutions professionnelles

Ce plan priorise l’impact utilisateur en mettant en tête le duo « Mode simple / Mode expert » et la lisibilité de la fiabilité perçue, puis en déroulant les autres chantiers structurants.

## Priorité 1 – Mode simple / Mode expert et fiabilité perçue
- **Constat** : le formulaire de configuration affiche simultanément les sections essentielles et avancées, exposant immédiatement des paramètres techniques (timeouts, heuristiques, webhooks) qui peuvent décourager les profils non experts.【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L2669-L2999】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L27-L498】 En parallèle, la fiabilité déjà assurée par la file d’analyse et le client HTTP reste peu visible dans l’interface.【F:liens-morts-detector-jlg/includes/Scanner/ScanQueue.php†L600-L799】【F:liens-morts-detector-jlg/includes/Scanner/RemoteRequestClient.php†L37-L166】
- **Inspiration pro** : les plateformes premium présentent un mode simplifié rassurant, affichent un score de santé immédiatement et permettent de basculer vers les réglages fins tout en gardant une vue d’ensemble sur la stabilité du service.
- **Améliorations détaillées** :
  1. **Toggle persistant et accessible** :
     - Switch « Activer le mode expert » placé en haut du formulaire, sauvegardé dans le `user_meta` pour conserver la préférence par utilisateur.
     - Mode simple : trois cartes thématiques (« Planification », « Alertes », « Confort visuel ») affichant 1 à 3 options critiques chacune, avec annonces `wp.a11y.speak` lors des changements.【F:liens-morts-detector-jlg/assets/js/blc-admin-scripts.js†L6-L220】
     - Mode expert : sections avancées repliables regroupées par objectifs (« Performance & quotas », « Fiabilité réseau », « Automatisation & webhooks »), avec une navigation latérale pour un accès rapide.
  2. **Bandeau de fiabilité perçue** :
     - Widget sticky affichant dernier scan réussi, taux d’échec réseau sur 7 jours et statut de la file (en cours, en attente), alimenté par `blc_link_scan_metrics_history`.
     - Messages contextuels colorés (`success`, `warning`, `error`) accompagnés d’icônes et d’annonces vocales pour renforcer l’accessibilité.
  3. **Panneau « Diagnostics rapides »** :
     - Bouton « Tester ma configuration » lançant une requête de validation légère et affichant un résumé (latence moyenne, nombre de liens testés) dans un toast accessible.
     - Historique court des 5 derniers incidents réseau avec CTA « Voir le détail » renvoyant vers le centre de supervision.
  4. **Parcours utilisateur type** :
     - Étape 1 : l’utilisateur active le mode simple, ajuste la fréquence de scan et valide les notifications critiques.
     - Étape 2 : il consulte le bandeau de fiabilité qui confirme le dernier scan et, si besoin, ouvre les diagnostics pour tester son hébergement.
     - Étape 3 : lorsqu’un ajustement avancé est requis, il bascule vers le mode expert depuis le même écran sans perdre le contexte des réglages ouverts.
  5. **Spécifications accessibilité & QA** :
     - Tous les éléments conditionnels doivent posséder des attributs `aria-expanded`/`aria-controls` cohérents et être testés avec la navigation clavier.
     - Cas de tests automatisés : bascule simple/expert persistante, affichage du bandeau de santé, réponse du test rapide en situation de succès et d’erreur.

## Priorité 2 – Centre de supervision et alertes en temps réel
- **Constat** : l’interface principale se limite à des onglets WordPress standard (Liens, Images, Historique, Réglages) et à des cartes statistiques basiques sans tendances ni alertes, alors que les métriques détaillées existent (`blc_link_scan_metrics_history`).【F:liens-morts-detector-jlg/includes/blc-admin-pages.php†L19-L109】【F:liens-morts-detector-jlg/assets/css/blc-admin-styles.css†L3-L158】【F:liens-morts-detector-jlg/includes/blc-scanner.php†L297-L325】
- **Inspiration pro** : dashboards exécutifs offrant KPIs temporels, timelines d’incidents, actions rapides et alerting multi-canaux.
- **Améliorations détaillées** :
  1. **Nouvel onglet « Centre de supervision »** :
     - Section d’accueil avec quatre KPI (Liens critiques, Liens redirigés, Temps moyen de correction, Couverture de scan) et tendance J-7 via sparklines.
     - Cartes d’état colorées filtrant la liste principale par type de problème.
  2. **Timeline d’incidents et diagnostics** :
     - Liste verticale des scans récents avec horodatage, durée, taux de réussite et badges « Succès », « Attention », « Échec ».
     - Encadré « Diagnostics réseau » reprenant les erreurs HTTP fréquentes et les proxys utilisés pour préparer le chantier de rotation d’IP.
  3. **Alerting proactif** :
     - Connecteurs Slack/PagerDuty déclenchés via `do_action('blc_link_scan_metrics', …)` avec seuils dynamiques (ex. taux d’erreur > 15 % sur deux scans).【F:liens-morts-detector-jlg/includes/blc-scanner.php†L3558-L3565】
     - Notifications dans l’admin (`wp_admin_notice`) résumant les incidents critiques et proposant un lien direct vers le mode expert pour ajuster les paramètres.
  4. **Actions rapides et presets de rapports** :
     - Boutons « Relancer un scan complet », « Scanner les brouillons », « Exporter CSV », « Partager un rapport » avec toasts de confirmation.
     - Possibilité d’enregistrer une configuration de widgets par utilisateur (`user_meta`) pour adapter le dashboard aux rôles.
  5. **Roadmap de livraison** :
     - Sprint 1 : création des widgets KPI et de la timeline d’incidents avec filtres essentiels.
     - Sprint 2 : ajout des diagnostics réseau et des actions rapides avec instrumentation analytique (événements `trackEvent`).
     - Sprint 3 : intégration des connecteurs Slack/PagerDuty et paramètres de seuils directement dans la page.
  6. **Indicateurs de réussite** :
     - Diminution de 30 % des tickets support liés aux scans échoués.
     - Taux d’utilisation des exports et partages de rapports supérieur à 40 % des comptes actifs.

## Priorité 3 – Onboarding guidé et presets métiers
- **Constat** : la page des réglages affiche une longue liste de champs techniques sans regroupement ni aide contextuelle, ce qui suppose une expertise WordPress avancée pour configurer le plugin.【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L27-L210】【F:liens-morts-detector-jlg/includes/blc-settings-fields.php†L211-L498】
- **Inspiration pro** : assistants en plusieurs étapes, presets sectoriels et recommandations in-app.
- **Améliorations détaillées** :
  1. **Wizard en trois étapes** (Profil → Paramètres réseau → Alertes) :
     - Écrans limités à 3–5 champs avec textes introductifs, illustrations et sauvegarde automatique via `wp.ajax.post`.
     - Intégration conditionnelle tant que l’utilisateur n’a pas finalisé l’onboarding (stocké dans `user_meta`).
  2. **Presets métiers et mode configuration rapide** :
     - Choix d’un profil (Site éditorial, Boutique WooCommerce, Collectivité) qui préremplit les options avancées cachées, modifiables ensuite.
     - Bouton « Paramétrer automatiquement » suggérant un preset en fonction du volume (`wp_count_posts`, `blc_link_query`) avec feedback visuel rassurant.
  3. **Aide contextuelle et checklist finale** :
     - Info-bulles, panneau latéral de conseils concrets, messages de confirmation clairs (« Votre audit est configuré pour 500 URLs toutes les 24 h »).
     - Checklist accessible concluant l’onboarding avec CTA « Lancer un premier scan ».
  4. **Pilotage du changement** :
     - Communication in-app via une modale « Nouveautés » présentant le wizard et les presets métiers.
     - Mesure analytique (événements) pour suivre la complétion du parcours et identifier les étapes générant le plus d’abandons.
  5. **Critères d’acceptation UX** :
     - 90 % des champs doivent être préremplis par un preset ou une valeur par défaut contextualisée.
     - Le wizard doit rester accessible (tab order, `aria-live` pour les validations) et offrir un mode reprise « Continuer plus tard ».

## Priorité 4 – Workflows collaboratifs et assignations
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
  6. **Gouvernance et sécurité** :
     - Journaliser toutes les modifications de statut/assignation (`wp_blc_link_workflow_log`) avec horodatage et utilisateur.
     - Vérifier systématiquement les capacités avant chaque action REST/CLI pour éviter l’escalade de privilèges.
  7. **Mesures de succès** :
     - Réduction de 25 % du temps moyen de résolution sur les liens critiques.
     - Adoption des vues sauvegardées par au moins deux rôles différents (éditeur, manager) dans les trois mois suivant le déploiement.

## Priorité 5 – Modale d’action enrichie et recommandations automatiques
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
  6. **Spécifications fonctionnelles** :
     - Les recommandations automatiques doivent être accompagnées d’un lien « Justification » expliquant l’origine de la suggestion (historique, similarité d’URL, redirection existante).
     - Prévoir un mode « aperçu sécurisé » désactivant les scripts tiers lors du chargement de la miniature pour éviter les fuites de données.
  7. **Plan de tests** :
     - Tests unitaires sur la génération des suggestions et la pondération du score de confiance.
     - Tests manuels QA couvrant les raccourcis clavier, l’annonce des erreurs et la cohérence du focus.

## Priorité 6 – Expérience mobile et terrain
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
  6. **Stratégie de déploiement** :
     - Mise à jour progressive des styles via classes utilitaires (`.is-mobile`, `.is-tablet`) pour limiter les régressions desktop.
     - Audit Lighthouse mobile avant/après pour vérifier la performance et la lisibilité.
  7. **Suivi post-lancement** :
     - Collecte d’avis via un questionnaire in-app ciblant les utilisateurs terrain.
     - Analyse des métriques d’usage mobile (taux de rebond, durée de session) pour prioriser les itérations futures.

