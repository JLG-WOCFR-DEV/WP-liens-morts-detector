<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        if (!isset($GLOBALS['__translation_calls'])) {
            $GLOBALS['__translation_calls'] = [];
        }

        $GLOBALS['__translation_calls'][] = [
            'text'   => (string) $text,
            'domain' => $domain,
        ];

        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null)
    {
        return ((int) $number === 1) ? $single : $plural;
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = null)
    {
        echo $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text)
    {
        return $text;
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null)
    {
        return $text;
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e($text, $domain = null)
    {
        echo $text;
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea($text)
    {
        return htmlspecialchars((string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return esc_html($text);
    }
}

if (!function_exists('sanitize_html_class')) {
    function sanitize_html_class($class)
    {
        return is_scalar($class) ? (string) $class : '';
    }
}

if (!function_exists('esc_url')) {
    function esc_url($text)
    {
        return $text;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text)
    {
        if (is_string($text)) {
            $text = preg_replace('/[\r\n\t\0\x0B]+/', '', $text);
            $text = trim($text);
        }

        if (is_scalar($text) || $text === null) {
            return (string) $text;
        }

        if (is_object($text) && method_exists($text, '__toString')) {
            return (string) $text;
        }

        return '';
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin')
    {
        $base = 'https://example.com/wp-admin/';

        if (!is_string($path) || $path === '') {
            return $base;
        }

        return $base . ltrim($path, '/');
    }
}

if (!function_exists('blc_is_wp_error')) {
    function blc_is_wp_error($thing)
    {
        if (function_exists('is_wp_error')) {
            try {
                if (is_wp_error($thing)) {
                    return true;
                }
            } catch (\Throwable $throwable) {
                $message = $throwable->getMessage();
                if (stripos($message, 'is_wp_error') === false || (stripos($message, 'undefined') === false && stripos($message, 'not defined') === false)) {
                    throw $throwable;
                }
            }
        }

        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null)
    {
        throw new \RuntimeException('success');
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null)
    {
        throw new \RuntimeException('error');
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = [])
    {
        return array_merge($defaults, is_array($args) ? $args : (array) $args);
    }
}

if (!function_exists('__return_false')) {
    function __return_false()
    {
        return false;
    }
}

