<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI_Command')) {
    return;
}

class BLC_Scan_CLI_Command extends WP_CLI_Command
{
    /**
     * Launch a link scan synchronously from the command line.
     *
     * ## OPTIONS
     *
     * [--full]
     * : Execute a full scan instead of the incremental mode.
     *
     * [--bypass-rest-window]
     * : Ignore the configured rest window when running the scan.
     *
     * ## EXAMPLES
     *
     *     wp broken-links scan links --full
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, mixed> $assoc_args Associative arguments.
     *
     * @return void
     */
    public function links($args, $assoc_args)
    {
        $is_full_scan = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'full', false);
        $bypass_rest_window = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'bypass-rest-window', false);

        $this->run_link_scan($is_full_scan, $bypass_rest_window);
    }

    /**
     * Launch an image scan synchronously from the command line.
     *
     * ## OPTIONS
     *
     * [--full]
     * : Execute a full scan (default behaviour).
     *
     * ## EXAMPLES
     *
     *     wp broken-links scan images
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, mixed> $assoc_args Associative arguments.
     *
     * @return void
     */
    public function images($args, $assoc_args)
    {
        $is_full_scan = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'full', true);

        $this->run_image_scan($is_full_scan);
    }

    private function run_link_scan(bool $is_full_scan, bool $bypass_rest_window): void
    {
        $remote_client = blc_make_remote_request_client();
        $queue = blc_make_scan_queue($remote_client);
        $controller = blc_make_link_scan_controller($queue);

        $this->process_batches(
            static function (array $task) use ($controller) {
                $batch = (int) ($task['args'][0] ?? 0);
                $is_full = (bool) ($task['args'][1] ?? false);
                $bypass = (bool) ($task['args'][2] ?? false);

                \WP_CLI::log(sprintf('→ Lancement du lot de liens #%d (scan complet : %s)…', $batch, $is_full ? 'oui' : 'non'));

                $result = $controller->runBatch($batch, $is_full, $bypass);
                if (is_wp_error($result)) {
                    \WP_CLI::error($result->get_error_message());
                }

                $status = blc_get_link_scan_status();
                $this->display_status('liens', $status);

                return $status;
            },
            [
                'hook'  => 'blc_check_batch',
                'args'  => [0, $is_full_scan, $bypass_rest_window],
                'delay' => 0,
            ],
            ['blc_check_batch']
        );

        $final_status = blc_get_link_scan_status();
        if ($final_status['state'] !== 'completed') {
            $message = $final_status['last_error'] ?: $final_status['message'] ?: __('Analyse des liens interrompue.', 'liens-morts-detector-jlg');
            \WP_CLI::error($message);
        }

        \WP_CLI::success('Analyse des liens terminée.');
    }

    private function run_image_scan(bool $is_full_scan): void
    {
        $queue = blc_make_image_scan_queue();
        $controller = blc_make_image_scan_controller($queue);

        $this->process_batches(
            static function (array $task) use ($controller) {
                $batch = (int) ($task['args'][0] ?? 0);
                $is_full = (bool) ($task['args'][1] ?? true);

                \WP_CLI::log(sprintf('→ Lancement du lot d\'images #%d…', $batch));

                $result = $controller->run($batch, $is_full);
                if (is_wp_error($result)) {
                    \WP_CLI::error($result->get_error_message());
                }

                $status = blc_get_image_scan_status();
                $this->display_status('images', $status);

                return $status;
            },
            [
                'hook'  => 'blc_check_image_batch',
                'args'  => [0, $is_full_scan],
                'delay' => 0,
            ],
            ['blc_check_image_batch']
        );

        $final_status = blc_get_image_scan_status();
        if ($final_status['state'] !== 'completed') {
            $message = $final_status['last_error'] ?: $final_status['message'] ?: __('Analyse des images interrompue.', 'liens-morts-detector-jlg');
            \WP_CLI::error($message);
        }

        \WP_CLI::success('Analyse des images terminée.');
    }

    /**
     * @param callable(array):array<string, mixed> $runner
     * @param array<string, mixed>                 $initial_task
     * @param string[]                              $allowed_hooks
     *
     * @return void
     */
    private function process_batches(callable $runner, array $initial_task, array $allowed_hooks): void
    {
        $scheduled_events = [];
        $filter = static function ($pre, $event) use (&$scheduled_events, $allowed_hooks) {
            if (!is_array($event) || empty($event['hook']) || !in_array($event['hook'], $allowed_hooks, true)) {
                return $pre;
            }

            $timestamp = isset($event['timestamp']) ? (int) $event['timestamp'] : time();
            $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];

            $scheduled_events[] = [
                'hook'      => (string) $event['hook'],
                'timestamp' => $timestamp,
                'args'      => $args,
            ];

            return true;
        };

        add_filter('pre_schedule_event', $filter, 10, 2);

        $queue = [$initial_task];
        $seen = [];
        $iterations = 0;

        try {
            while (!empty($queue)) {
                if (++$iterations > 1000) {
                    \WP_CLI::error('Nombre maximal d\'itérations atteint pendant le scan.');
                }

                $task = array_shift($queue);
                $delay = isset($task['delay']) ? (int) $task['delay'] : 0;

                $signature = $task['hook'] . '|' . wp_json_encode($task['args']);
                if (isset($seen[$signature]) && $delay === 0) {
                    continue;
                }
                $seen[$signature] = true;

                if ($delay > 0) {
                    \WP_CLI::log(sprintf('⏳ Pause de %d s avant le prochain lot…', $delay));
                    sleep($delay);
                }

                $status = $runner($task);

                $state = isset($status['state']) ? (string) $status['state'] : 'idle';
                if (in_array($state, ['completed', 'failed', 'cancelled'], true)) {
                    break;
                }

                if ($scheduled_events === []) {
                    \WP_CLI::warning("Aucun lot suivant n'a été planifié. Le scan risque de rester incomplet.");
                    break;
                }

                $handoff_events = [];

                foreach ($scheduled_events as $event) {
                    $event_args = $event['args'];

                    $scheduled_batch = isset($event_args[0]) ? (int) $event_args[0] : null;
                    $current_batch = isset($task['args'][0]) ? (int) $task['args'][0] : null;

                    $should_process_sync = true;
                    if ($current_batch !== null && $scheduled_batch === $current_batch) {
                        $should_process_sync = false;
                    }

                    if ($should_process_sync) {
                        $queue[] = [
                            'hook'  => $event['hook'],
                            'args'  => $event_args,
                            'delay' => max(0, $event['timestamp'] - time()),
                        ];
                        continue;
                    }

                    $handoff_events[] = $event;
                }

                $scheduled_events = [];

                if ($handoff_events !== []) {
                    foreach ($handoff_events as $event) {
                        $timestamp = $event['timestamp'];
                        if ($timestamp <= time()) {
                            $timestamp = time() + 1;
                        }

                        $rescheduled = wp_schedule_single_event($timestamp, $event['hook'], $event['args']);
                        if (false === $rescheduled) {
                            \WP_CLI::warning(sprintf(
                                "Impossible de reprogrammer le lot différé \"%s\". Il devra être lancé manuellement.",
                                $event['hook']
                            ));
                        }
                    }

                    \WP_CLI::log("Des lots ont été différés et seront repris automatiquement par WP-Cron.");
                    break;
                }
            }
        } finally {
            remove_filter('pre_schedule_event', $filter, 10);
        }
    }

    /**
     * @param string                   $dataset
     * @param array<string, mixed>     $status
     *
     * @return void
     */
    private function display_status(string $dataset, array $status): void
    {
        $state = strtoupper((string) ($status['state'] ?? ''));
        $message = isset($status['message']) ? trim((string) $status['message']) : '';

        $batch_progress = '';
        $total_batches = (int) ($status['total_batches'] ?? 0);
        $processed_batches = (int) ($status['processed_batches'] ?? 0);
        if ($total_batches > 0) {
            $batch_progress = sprintf('%d/%d lots', $processed_batches, $total_batches);
        }

        $item_progress = '';
        $total_items = (int) ($status['total_items'] ?? 0);
        $processed_items = (int) ($status['processed_items'] ?? 0);
        if ($total_items > 0) {
            $item_progress = sprintf('%d/%d éléments', $processed_items, $total_items);
        } elseif ($processed_items > 0) {
            $item_progress = sprintf('%d éléments traités', $processed_items);
        }

        $parts = array_filter([$batch_progress, $item_progress]);
        if ($parts !== []) {
            \WP_CLI::log(sprintf('[%s] Progression %s : %s', $state, $dataset, implode(', ', $parts)));
        } else {
            \WP_CLI::log(sprintf('[%s] Progression %s…', $state, $dataset));
        }

        if ($message !== '') {
            \WP_CLI::log($message);
        }
    }
}

WP_CLI::add_command('broken-links scan', 'BLC_Scan_CLI_Command');
