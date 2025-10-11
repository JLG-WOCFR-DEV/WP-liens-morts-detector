<?php

namespace JLG\BrokenLinks\Scanner;

class Soft404Heuristics
{
    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getMinLength(): int
    {
        return isset($this->config['min_length']) ? (int) $this->config['min_length'] : 0;
    }

    /**
     * @return array<int, string>
     */
    public function getTitleIndicators(): array
    {
        return isset($this->config['title_indicators']) && is_array($this->config['title_indicators'])
            ? $this->config['title_indicators']
            : [];
    }

    /**
     * @return array<int, string>
     */
    public function getBodyIndicators(): array
    {
        return isset($this->config['body_indicators']) && is_array($this->config['body_indicators'])
            ? $this->config['body_indicators']
            : [];
    }

    /**
     * @return array<int, string>
     */
    public function getIgnorePatterns(): array
    {
        return isset($this->config['ignore_patterns']) && is_array($this->config['ignore_patterns'])
            ? $this->config['ignore_patterns']
            : [];
    }

    public function extractTitle($html): string
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return '';
        }

        $title = isset($matches[1]) ? (string) $matches[1] : '';
        if ($title === '') {
            return '';
        }

        if (function_exists('wp_specialchars_decode')) {
            $title = wp_specialchars_decode($title, ENT_QUOTES);
        } else {
            $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        }

        return trim(preg_replace('/\s+/', ' ', $title));
    }

    public function stripText($html): string
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        if (function_exists('wp_strip_all_tags')) {
            $text = wp_strip_all_tags($html, true);
        } else {
            $text = strip_tags($html);
        }

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }

    /**
     * @param array<int, string> $patterns
     * @param array<int, string> $candidates
     */
    public function matchesAny(array $patterns, array $candidates): bool
    {
        if ($patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }

            $pattern_value = (string) $pattern;
            $is_regex = false;
            $regex_body = '';
            $regex_flags = 'i';

            if (strlen($pattern_value) >= 2 && $pattern_value[0] === '/') {
                $last_delimiter = strrpos($pattern_value, '/');
                if ($last_delimiter !== false) {
                    $regex_body = substr($pattern_value, 1, $last_delimiter - 1);
                    $regex_flags = substr($pattern_value, $last_delimiter + 1);
                    $is_regex = ($regex_body !== '');
                }
            }

            foreach ($candidates as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    continue;
                }

                if ($is_regex) {
                    $regex = '/' . $regex_body . '/' . $regex_flags;
                    if (@preg_match($regex, $candidate) === 1) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                        return true;
                    }
                } elseif (stripos($candidate, $pattern_value) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
