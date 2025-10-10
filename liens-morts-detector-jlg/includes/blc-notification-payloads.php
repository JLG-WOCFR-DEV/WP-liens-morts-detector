<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build the payload sent to a webhook channel for scan summaries.
 *
 * @param string               $channel Channel identifier.
 * @param string               $message Plain text summary message.
 * @param array<string, mixed> $summary Structured summary metadata.
 * @param array<string, mixed> $settings Webhook settings.
 *
 * @return array<string, mixed>
 */
function blc_build_notification_webhook_payload($channel, $message, array $summary, array $settings = array()) {
    $channel = is_scalar($channel) ? (string) $channel : 'generic';

    switch ($channel) {
        case 'slack':
            return blc_build_slack_notification_payload($message, $summary, $settings);
        case 'teams':
            return blc_build_teams_notification_payload($message, $summary, $settings);
        case 'mattermost':
            return blc_build_mattermost_notification_payload($message, $summary, $settings);
        case 'generic':
        default:
            return blc_build_generic_notification_payload($message, $summary);
    }
}

/**
 * Build the payload for generic JSON webhooks.
 *
 * @param string               $message Summary message.
 * @param array<string, mixed> $summary Structured summary metadata.
 *
 * @return array<string, mixed>
 */
function blc_build_generic_notification_payload($message, array $summary) {
    return array(
        'message'      => $message,
        'subject'      => isset($summary['subject']) ? (string) $summary['subject'] : '',
        'dataset_type' => isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '',
        'broken_count' => isset($summary['broken_count']) ? (int) $summary['broken_count'] : 0,
        'report_url'   => isset($summary['report_url']) ? (string) $summary['report_url'] : '',
        'site_name'    => isset($summary['site_name']) ? (string) $summary['site_name'] : '',
    );
}

/**
 * Build a rich Block Kit payload for Slack.
 *
 * @param string               $message Summary message used as fallback text.
 * @param array<string, mixed> $summary Structured summary metadata.
 * @param array<string, mixed> $settings Webhook settings.
 *
 * @return array<string, mixed>
 */
function blc_build_slack_notification_payload($message, array $summary, array $settings = array()) {
    $subject       = isset($summary['subject']) ? blc_trim_slack_plain_text((string) $summary['subject']) : '';
    $site_name     = isset($summary['site_name']) ? blc_trim_slack_plain_text((string) $summary['site_name']) : '';
    $dataset_label = isset($summary['dataset_label']) ? blc_trim_slack_plain_text((string) $summary['dataset_label']) : '';
    $dataset_type  = isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '';
    $broken_count  = isset($summary['broken_count']) ? (int) $summary['broken_count'] : 0;
    $report_url    = isset($summary['report_url']) ? (string) $summary['report_url'] : '';
    $difference    = array_key_exists('difference', $summary) ? $summary['difference'] : null;
    $previous      = array_key_exists('previous_count', $summary) ? $summary['previous_count'] : null;
    $top_issues    = isset($summary['top_issues']) && is_array($summary['top_issues']) ? $summary['top_issues'] : array();
    $filters       = isset($summary['status_filters']) && is_array($summary['status_filters']) ? $summary['status_filters'] : array();
    $filter_labels = blc_translate_notification_status_filters($filters);
    $trend_label   = blc_format_notification_trend($difference, $previous);

    if ($dataset_label === '' && $dataset_type !== '') {
        $dataset_label = $dataset_type;
    }

    $overview_fields = array();

    if ($site_name !== '') {
        $overview_fields[] = array(
            'type' => 'mrkdwn',
            'text' => sprintf('*%s*%s%s',
                __('Site', 'liens-morts-detector-jlg'),
                PHP_EOL,
                blc_escape_slack_text($site_name)
            ),
        );
    }

    if ($dataset_label !== '') {
        $overview_fields[] = array(
            'type' => 'mrkdwn',
            'text' => sprintf('*%s*%s%s',
                __('Analyse', 'liens-morts-detector-jlg'),
                PHP_EOL,
                blc_escape_slack_text($dataset_label)
            ),
        );
    }

    $overview_fields[] = array(
        'type' => 'mrkdwn',
        'text' => sprintf('*%s*%s%s',
            __('Éléments cassés', 'liens-morts-detector-jlg'),
            PHP_EOL,
            blc_escape_slack_text((string) $broken_count)
        ),
    );

    $overview_fields[] = array(
        'type' => 'mrkdwn',
        'text' => sprintf('*%s*%s%s',
            __('Tendance', 'liens-morts-detector-jlg'),
            PHP_EOL,
            blc_escape_slack_text($trend_label)
        ),
    );

    $blocks = array();

    if ($subject !== '') {
        $blocks[] = array(
            'type' => 'header',
            'text' => array(
                'type' => 'plain_text',
                'text' => $subject,
                'emoji' => true,
            ),
        );
    }

    $blocks[] = array(
        'type'   => 'section',
        'fields' => $overview_fields,
    );

    if ($filter_labels !== array()) {
        $blocks[] = array(
            'type' => 'context',
            'elements' => array(
                array(
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        '*%s* %s',
                        __('Filtres actifs', 'liens-morts-detector-jlg'),
                        blc_escape_slack_text(implode(' • ', $filter_labels))
                    ),
                ),
            ),
        );
    }

    if ($top_issues !== array()) {
        $issue_lines = array();
        foreach ($top_issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $url         = isset($issue['url']) ? (string) $issue['url'] : '';
            $http_status = isset($issue['http_status']) ? $issue['http_status'] : null;
            $occurrences = isset($issue['occurrence_count']) ? (int) $issue['occurrence_count'] : null;
            $title       = isset($issue['post_title']) ? (string) $issue['post_title'] : '';

            $status_label = $http_status !== null && $http_status !== ''
                ? sprintf(__('statut : %s', 'liens-morts-detector-jlg'), $http_status)
                : __('statut : inconnu', 'liens-morts-detector-jlg');

            $details = array($status_label);

            if ($occurrences !== null) {
                $details[] = sprintf(__('occurrences : %d', 'liens-morts-detector-jlg'), $occurrences);
            }

            if ($title !== '') {
                $details[] = sprintf(__('contenu : %s', 'liens-morts-detector-jlg'), blc_escape_slack_text($title));
            }

            $link_label = $url !== '' ? sprintf('<%1$s|%1$s>', blc_escape_slack_url($url)) : __('URL inconnue', 'liens-morts-detector-jlg');
            $issue_lines[] = sprintf('• %s — %s', $link_label, blc_escape_slack_text(implode(' — ', $details)));
        }

        if ($issue_lines !== array()) {
            $blocks[] = array(
                'type' => 'section',
                'text' => array(
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        "*%s*\n%s",
                        __('Problèmes principaux', 'liens-morts-detector-jlg'),
                        implode("\n", $issue_lines)
                    ),
                ),
            );
        }
    }

    if ($report_url !== '') {
        $button_text = __('Ouvrir le rapport', 'liens-morts-detector-jlg');
        $blocks[] = array(
            'type'     => 'actions',
            'elements' => array(
                array(
                    'type'  => 'button',
                    'text'  => array(
                        'type'  => 'plain_text',
                        'text'  => blc_trim_slack_plain_text($button_text, 40),
                        'emoji' => true,
                    ),
                    'url'   => $report_url,
                ),
            ),
        );
    }

    return array(
        'text'   => $message,
        'blocks' => $blocks,
    );
}

/**
 * Build an Adaptive Card payload for Microsoft Teams.
 *
 * @param string               $message Summary message.
 * @param array<string, mixed> $summary Structured summary metadata.
 * @param array<string, mixed> $settings Webhook settings.
 *
 * @return array<string, mixed>
 */
function blc_build_teams_notification_payload($message, array $summary, array $settings = array()) {
    $subject       = isset($summary['subject']) ? (string) $summary['subject'] : '';
    $site_name     = isset($summary['site_name']) ? (string) $summary['site_name'] : '';
    $dataset_label = isset($summary['dataset_label']) ? (string) $summary['dataset_label'] : '';
    $dataset_type  = isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '';
    $broken_count  = isset($summary['broken_count']) ? (int) $summary['broken_count'] : 0;
    $report_url    = isset($summary['report_url']) ? (string) $summary['report_url'] : '';
    $difference    = array_key_exists('difference', $summary) ? $summary['difference'] : null;
    $previous      = array_key_exists('previous_count', $summary) ? $summary['previous_count'] : null;
    $top_issues    = isset($summary['top_issues']) && is_array($summary['top_issues']) ? $summary['top_issues'] : array();
    $filters       = isset($summary['status_filters']) && is_array($summary['status_filters']) ? $summary['status_filters'] : array();
    $filter_labels = blc_translate_notification_status_filters($filters);

    if ($dataset_label === '' && $dataset_type !== '') {
        $dataset_label = $dataset_type;
    }

    $trend_label = blc_format_notification_trend($difference, $previous);

    $facts = array(
        array(
            'name'  => __('Éléments cassés', 'liens-morts-detector-jlg'),
            'value' => (string) $broken_count,
        ),
        array(
            'name'  => __('Tendance', 'liens-morts-detector-jlg'),
            'value' => $trend_label,
        ),
    );

    if ($filter_labels !== array()) {
        $facts[] = array(
            'name'  => __('Filtres actifs', 'liens-morts-detector-jlg'),
            'value' => implode(', ', $filter_labels),
        );
    }

    $sections = array(
        array(
            'activityTitle'    => $site_name,
            'activitySubtitle' => $dataset_label,
            'markdown'         => true,
            'facts'            => $facts,
        ),
    );

    if ($top_issues !== array()) {
        $issue_lines = array();
        foreach ($top_issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $url         = isset($issue['url']) ? (string) $issue['url'] : '';
            $http_status = isset($issue['http_status']) ? $issue['http_status'] : null;
            $occurrences = isset($issue['occurrence_count']) ? (int) $issue['occurrence_count'] : null;
            $title       = isset($issue['post_title']) ? (string) $issue['post_title'] : '';

            $parts = array();
            if ($http_status !== null && $http_status !== '') {
                $parts[] = sprintf(__('statut : %s', 'liens-morts-detector-jlg'), $http_status);
            }
            if ($occurrences !== null) {
                $parts[] = sprintf(__('occurrences : %d', 'liens-morts-detector-jlg'), $occurrences);
            }
            if ($title !== '') {
                $parts[] = sprintf(__('contenu : %s', 'liens-morts-detector-jlg'), $title);
            }

            if ($url !== '') {
                $issue_lines[] = sprintf('[%s](%s) — %s', $url, $url, implode(' — ', $parts));
            } else {
                $issue_lines[] = implode(' — ', $parts);
            }
        }

        if ($issue_lines !== array()) {
            $sections[] = array(
                'title'   => __('Problèmes principaux', 'liens-morts-detector-jlg'),
                'text'    => implode("\n", $issue_lines),
                'markdown'=> true,
            );
        }
    }

    $actions = array();
    if ($report_url !== '') {
        $actions[] = array(
            '@type'  => 'OpenUri',
            'name'   => __('Ouvrir le rapport', 'liens-morts-detector-jlg'),
            'targets' => array(
                array(
                    'os'  => 'default',
                    'uri' => $report_url,
                ),
            ),
        );
    }

    $themeColor = $broken_count > 0 ? 'D0382D' : '2E8540';

    $summary_text = $subject !== '' ? $subject : __('Résumé de scan Liens Morts Detector', 'liens-morts-detector-jlg');

    return array(
        '@type'           => 'MessageCard',
        '@context'        => 'https://schema.org/extensions',
        'summary'         => $summary_text,
        'themeColor'      => $themeColor,
        'title'           => $subject !== '' ? $subject : $summary_text,
        'text'            => $message,
        'sections'        => $sections,
        'potentialAction' => $actions,
    );
}

/**
 * Build an attachment based payload for Mattermost.
 *
 * Mattermost est compatible avec le format Slack des pièces jointes. On
 * capitalise sur ce support pour proposer un rendu riche sans multiplier les
 * formats de sortie tout en conservant une structure adaptée au canal.
 *
 * @param string               $message  Summary message used as fallback text.
 * @param array<string, mixed> $summary  Structured summary metadata.
 * @param array<string, mixed> $settings Webhook settings.
 *
 * @return array<string, mixed>
 */
function blc_build_mattermost_notification_payload($message, array $summary, array $settings = array()) {
    $subject       = isset($summary['subject']) ? blc_trim_slack_plain_text((string) $summary['subject']) : '';
    $site_name     = isset($summary['site_name']) ? blc_trim_slack_plain_text((string) $summary['site_name']) : '';
    $dataset_label = isset($summary['dataset_label']) ? blc_trim_slack_plain_text((string) $summary['dataset_label']) : '';
    $dataset_type  = isset($summary['dataset_type']) ? (string) $summary['dataset_type'] : '';
    $broken_count  = isset($summary['broken_count']) ? (int) $summary['broken_count'] : 0;
    $report_url    = isset($summary['report_url']) ? (string) $summary['report_url'] : '';
    $difference    = array_key_exists('difference', $summary) ? $summary['difference'] : null;
    $previous      = array_key_exists('previous_count', $summary) ? $summary['previous_count'] : null;
    $top_issues    = isset($summary['top_issues']) && is_array($summary['top_issues']) ? $summary['top_issues'] : array();
    $filters       = isset($summary['status_filters']) && is_array($summary['status_filters']) ? $summary['status_filters'] : array();
    $filter_labels = blc_translate_notification_status_filters($filters);

    if ($dataset_label === '' && $dataset_type !== '') {
        $dataset_label = $dataset_type;
    }

    $trend_label = blc_format_notification_trend($difference, $previous);

    $fields = array(
        array(
            'short' => true,
            'title' => __('Site', 'liens-morts-detector-jlg'),
            'value' => $site_name !== '' ? blc_escape_slack_text($site_name) : __('Inconnu', 'liens-morts-detector-jlg'),
        ),
        array(
            'short' => true,
            'title' => __('Analyse', 'liens-morts-detector-jlg'),
            'value' => $dataset_label !== '' ? blc_escape_slack_text($dataset_label) : __('N/A', 'liens-morts-detector-jlg'),
        ),
        array(
            'short' => true,
            'title' => __('Éléments cassés', 'liens-morts-detector-jlg'),
            'value' => blc_escape_slack_text((string) $broken_count),
        ),
        array(
            'short' => true,
            'title' => __('Tendance', 'liens-morts-detector-jlg'),
            'value' => blc_escape_slack_text($trend_label),
        ),
    );

    if ($filter_labels !== array()) {
        $fields[] = array(
            'short' => false,
            'title' => __('Filtres actifs', 'liens-morts-detector-jlg'),
            'value' => blc_escape_slack_text(implode(' • ', $filter_labels)),
        );
    }

    $issue_lines = array();
    if ($top_issues !== array()) {
        foreach ($top_issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $url         = isset($issue['url']) ? (string) $issue['url'] : '';
            $http_status = isset($issue['http_status']) ? $issue['http_status'] : null;
            $occurrences = isset($issue['occurrence_count']) ? (int) $issue['occurrence_count'] : null;
            $title       = isset($issue['post_title']) ? (string) $issue['post_title'] : '';

            $details = array();
            if ($http_status !== null && $http_status !== '') {
                $details[] = sprintf(__('statut : %s', 'liens-morts-detector-jlg'), $http_status);
            }
            if ($occurrences !== null) {
                $details[] = sprintf(__('occurrences : %d', 'liens-morts-detector-jlg'), $occurrences);
            }
            if ($title !== '') {
                $details[] = sprintf(__('contenu : %s', 'liens-morts-detector-jlg'), blc_escape_slack_text($title));
            }

            $issue_label = $url !== ''
                ? sprintf('<%1$s|%1$s>', blc_escape_slack_url($url))
                : __('URL inconnue', 'liens-morts-detector-jlg');

            $issue_lines[] = sprintf('• %s — %s', $issue_label, blc_escape_slack_text(implode(' — ', $details)));
        }
    }

    $color = $broken_count > 0 ? '#D0382D' : '#2E8540';
    $title = $subject !== '' ? $subject : __('Résumé de scan Liens Morts Detector', 'liens-morts-detector-jlg');

    $attachment = array(
        'fallback'   => $message,
        'color'      => $color,
        'title'      => $title,
        'text'       => $message,
        'fields'     => $fields,
        'mrkdwn_in'  => array('text', 'fields'),
    );

    if ($issue_lines !== array()) {
        $attachment['text'] .= sprintf("\n\n*%s*\n%s",
            __('Problèmes principaux', 'liens-morts-detector-jlg'),
            implode("\n", $issue_lines)
        );
    }

    if ($report_url !== '') {
        $attachment['actions'] = array(
            array(
                'name' => __('Ouvrir le rapport', 'liens-morts-detector-jlg'),
                'type' => 'button',
                'url'  => $report_url,
            ),
        );
    }

    return array(
        'text'        => $message,
        'attachments' => array($attachment),
    );
}

/**
 * Translate status filter identifiers into human readable labels.
 *
 * @param string[] $filters Selected filter identifiers.
 *
 * @return string[]
 */
function blc_translate_notification_status_filters(array $filters) {
    if ($filters === array()) {
        return array();
    }

    if (function_exists('blc_get_notification_status_filter_choices')) {
        $choices = blc_get_notification_status_filter_choices();
    } else {
        $choices = array();
    }

    $labels = array();
    foreach ($filters as $filter) {
        if (!is_scalar($filter)) {
            continue;
        }

        $key = (string) $filter;
        if ($key === '') {
            continue;
        }

        if (isset($choices[$key])) {
            $labels[] = (string) $choices[$key];
        } else {
            $labels[] = $key;
        }
    }

    return array_values(array_unique($labels));
}

/**
 * Format a human readable trend description.
 *
 * @param mixed $difference Difference with previous scan.
 * @param mixed $previous   Previous count.
 *
 * @return string
 */
function blc_format_notification_trend($difference, $previous) {
    if ($difference === null || $previous === null) {
        return __('Nouvelle mesure', 'liens-morts-detector-jlg');
    }

    if (!is_numeric($difference)) {
        return __('Nouvelle mesure', 'liens-morts-detector-jlg');
    }

    $difference = (int) $difference;

    if ($difference === 0) {
        return __('Stable', 'liens-morts-detector-jlg');
    }

    if ($difference > 0) {
        return sprintf(__('En hausse de %d', 'liens-morts-detector-jlg'), $difference);
    }

    return sprintf(__('En baisse de %d', 'liens-morts-detector-jlg'), abs($difference));
}

/**
 * Escape text that will be sent to Slack.
 *
 * @param string $text Arbitrary text.
 *
 * @return string
 */
function blc_escape_slack_text($text) {
    $text = (string) $text;
    $text = str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), $text);

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
}

/**
 * Escape URLs that will be displayed in Slack.
 *
 * @param string $url URL to escape.
 *
 * @return string
 */
function blc_escape_slack_url($url) {
    $url = (string) $url;

    return str_replace(array('&', '<', '>'), array('%26', '%3C', '%3E'), $url);
}

/**
 * Trim a Slack plain-text value to the recommended length.
 *
 * @param string $text Text to trim.
 * @param int    $max  Maximum number of characters.
 *
 * @return string
 */
function blc_trim_slack_plain_text($text, $max = 150) {
    $text = (string) $text;

    if ($text === '') {
        return '';
    }

    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return mb_substr($text, 0, $max - 1) . '…';
}
