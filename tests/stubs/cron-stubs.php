<?php

namespace {
    if (!function_exists('spawn_cron')) {
        function spawn_cron()
        {
            if (isset($GLOBALS['__blc_spawn_cron_callback']) && is_callable($GLOBALS['__blc_spawn_cron_callback'])) {
                return call_user_func($GLOBALS['__blc_spawn_cron_callback']);
            }

            return null;
        }
    }

    if (!function_exists('wp_cron')) {
        function wp_cron()
        {
            if (isset($GLOBALS['__blc_wp_cron_callback']) && is_callable($GLOBALS['__blc_wp_cron_callback'])) {
                return call_user_func($GLOBALS['__blc_wp_cron_callback']);
            }

            return null;
        }
    }
}
