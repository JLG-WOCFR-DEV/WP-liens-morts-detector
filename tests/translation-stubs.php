<?php

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

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return $text;
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
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return $text;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($text)
    {
        return $text;
    }
}

