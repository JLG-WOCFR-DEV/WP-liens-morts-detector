# État des priorités restantes

## Manques face à la feuille de route interne
- **Notifications multicanales** : le moteur mutualisé et les connecteurs Slack/Teams/Mattermost décrits dans la roadmap n’ont pas encore été implémentés.
- **Optimisations d’interface** : les tests d’accessibilité automatisés (axe-core) et la documentation de la charte UX restent à livrer malgré la création du composant `DashboardSummary`.
- **Renforcement de la qualité logicielle** : les scénarios Playwright et la chaîne CI complète (lint, packaging) restent planifiés mais absents du code.
- **Surveillance proactive** : la gestion de seuils configurables, l’escalade multicanale et les visualisations sparkline doivent encore être conçues.

## Écarts persistants avec les suites professionnelles
- **Supervision temps réel** : l’interface n’expose toujours pas l’historique et les métriques pourtant stockés côté base, contrairement aux consoles pro.
- **Scalabilité horizontale** : aucune file distribuée (Redis/SQS) ni worker externe ne complète encore WP-Cron pour les catalogues volumineux.
- **Résilience réseau avancée** : la rotation de proxys/IP et les stratégies multi-sorties restent à ajouter pour égaler les solutions premium.
- **Workflows collaboratifs** : l’assignation, la journalisation fine des corrections et le partage de vues enregistrées ne sont pas encore disponibles.
- **Connecteurs prêts à l’emploi** : l’extension ne propose pas d’intégrations Slack/Jira/ServiceNow ni de documentation REST industrialisée comme les concurrents.
