# File d'attente distribuée

Le plugin propose désormais deux modes de file :

- **WP-Cron (par défaut)** pour conserver le comportement historique où WordPress planifie les lots via `wp_schedule_single_event`.
- **Redis Streams/Listes** pour externaliser la file sur une instance Redis et faire tourner un worker WP-CLI (`wp blc:worker run`).

## Paramètres disponibles

Dans l’onglet « Réglages », section *File d’attente distribuée* :

- **Pilote de file** : sélectionnez `WP-Cron` ou `Redis`.
- **Hôte Redis** : nom d’hôte ou IP de votre serveur.
- **Port Redis** : port TCP à utiliser (6379 par défaut).
- **Mot de passe** : secret d’authentification si votre instance est protégée (`requirepass`).
- **Travailleurs simultanés** : nombre maximal de workers WP-CLI/externes que vous autorisez en parallèle. Cette valeur est incluse dans les jobs sérialisés pour adapter le dimensionnement côté worker.

## Worker WP-CLI

Lancez un worker via :

```bash
wp blc:worker run --max-jobs=25 --sleep=2
```

- `--max-jobs` limite le nombre de lots traités (0 = illimité).
- `--sleep` définit la pause (en secondes) lorsque la file est vide.

Si le worker ne parvient pas à se connecter à Redis, il programme automatiquement le lot initial via WP-Cron afin de ne pas bloquer la campagne de scan.

## Extensibilité

Un hook `blc_queue_driver_registered` est déclenché pour chaque pilote chargé, et `blc_queue_driver_resolved` lorsque le pilote actif est sélectionné. Cela permet d’ajouter des adaptateurs (AWS SQS, RabbitMQ, etc.) depuis une extension tierce.
