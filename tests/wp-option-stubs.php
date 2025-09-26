<?php

namespace Tests\Stubs {
    final class OptionsStore
    {
        /** @var array<string, mixed> */
        public static array $options = [];

        public static function reset(): void
        {
            self::$options = [];
        }
    }
}

namespace {
    use Tests\Stubs\OptionsStore;

    if (!function_exists('get_option')) {
        function get_option($name, $default = false)
        {
            $name = (string) $name;

            return OptionsStore::$options[$name] ?? $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option($name, $value, $autoload = null)
        {
            $name = (string) $name;
            OptionsStore::$options[$name] = $value;

            return true;
        }
    }

    if (!function_exists('delete_option')) {
        function delete_option($name)
        {
            $name = (string) $name;

            if (isset(OptionsStore::$options[$name])) {
                unset(OptionsStore::$options[$name]);
            }

            return true;
        }
    }
}
