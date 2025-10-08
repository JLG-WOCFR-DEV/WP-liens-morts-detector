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

