<?php

namespace JLG\BrokenLinks\Admin;

class AdminAssets
{
    /** @var string */
    private $pluginFile;

    /** @var AdminScriptLocalizations */
    private $localizations;

    public function __construct($pluginFile, ?AdminScriptLocalizations $localizations = null)
    {
        $this->pluginFile    = (string) $pluginFile;
        $this->localizations = $localizations ?: new AdminScriptLocalizations();
    }

    public function enqueue($hook)
    {
        if (!$this->shouldEnqueue($hook)) {
            return;
        }

        $this->enqueueStyles();
        $this->enqueueScripts();
        $this->registerTranslations();

        $context = $this->buildLocalizationContext();
        foreach ($this->localizations->getScriptData($context) as $objectName => $data) {
            if ($data === null) {
                continue;
            }

            \wp_localize_script('blc-admin-js', $objectName, $data);
        }
    }

    public function shouldEnqueue($hook)
    {
        if ($this->isPluginAdminRequest()) {
            return true;
        }

        if (!is_string($hook) || $hook === '') {
            return false;
        }

        foreach ($this->getAdminPageSlugs() as $slug) {
            if ($slug !== '' && strpos($hook, $slug) !== false) {
                return true;
            }
        }

        return false;
    }

    public function getAdminPageSlugs()
    {
        $pages = array('blc-dashboard', 'blc-images-dashboard', 'blc-history', 'blc-settings');

        if (function_exists('apply_filters')) {
            $pages = (array) apply_filters('blc_admin_page_slugs', $pages);
        }

        $normalized = array();
        foreach ($pages as $page) {
            if (!is_string($page)) {
                continue;
            }

            $normalized[] = sanitize_key($page);
        }

        return array_values(array_filter($normalized));
    }

    public function isPluginAdminRequest()
    {
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }

        if (!isset($_GET['page']) || !is_scalar($_GET['page'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only usage.
            return false;
        }

        $page = sanitize_key(\wp_unslash((string) $_GET['page'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only usage.

        return in_array($page, $this->getAdminPageSlugs(), true);
    }

    private function enqueueStyles()
    {
        $cssPath = $this->getPluginDir() . 'assets/css/blc-admin-styles.css';
        $cssVersion = file_exists($cssPath) ? filemtime($cssPath) : time();

        \wp_enqueue_style(
            'blc-admin-css',
            $this->getPluginUrl('assets/css/blc-admin-styles.css'),
            array(),
            $cssVersion
        );
    }

    private function enqueueScripts()
    {
        $togglePath = $this->getPluginDir() . 'assets/js/settings-mode-toggle.js';
        $toggleVersion = file_exists($togglePath) ? filemtime($togglePath) : time();

        \wp_enqueue_script(
            'blc-settings-mode-toggle',
            $this->getPluginUrl('assets/js/settings-mode-toggle.js'),
            array('jquery'),
            $toggleVersion,
            true
        );

        $jsPath = $this->getPluginDir() . 'assets/js/blc-admin-scripts.js';
        $jsVersion = file_exists($jsPath) ? filemtime($jsPath) : time();

        \wp_enqueue_script(
            'blc-admin-js',
            $this->getPluginUrl('assets/js/blc-admin-scripts.js'),
            array('jquery', 'wp-util', 'blc-settings-mode-toggle'),
            $jsVersion,
            true
        );

        $surveillancePath = $this->getPluginDir() . 'assets/js/surveillance-thresholds.js';
        $surveillanceVersion = file_exists($surveillancePath) ? filemtime($surveillancePath) : time();

        \wp_enqueue_script(
            'blc-surveillance-controls',
            $this->getPluginUrl('assets/js/surveillance-thresholds.js'),
            array('blc-admin-js'),
            $surveillanceVersion,
            true
        );
    }

    private function registerTranslations()
    {
        if (!function_exists('wp_set_script_translations')) {
            return;
        }

        \wp_set_script_translations(
            'blc-admin-js',
            'liens-morts-detector-jlg',
            $this->getPluginDir() . 'languages'
        );
    }

    private function buildLocalizationContext()
    {
        $uiPreset = function_exists('blc_get_active_ui_preset') ? blc_get_active_ui_preset() : 'default';
        $uiPresetKey = sanitize_key($uiPreset);
        $presetClass = 'blc-preset--' . (function_exists('sanitize_html_class') ? sanitize_html_class($uiPresetKey) : $uiPresetKey);
        $accessibilityPreferences = function_exists('blc_get_accessibility_preferences')
            ? blc_get_accessibility_preferences()
            : array();

        $restUrl = function_exists('rest_url') ? rest_url('blc/v1/scan-status') : '';
        $scanStatus = function_exists('blc_get_link_scan_status_payload') ? blc_get_link_scan_status_payload() : array();
        $pollInterval = function_exists('apply_filters') ? apply_filters('blc_scan_status_poll_interval', 10000) : 10000;
        if (!is_int($pollInterval)) {
            $pollInterval = (int) $pollInterval;
        }

        $imageRestUrl = $restUrl === '' ? '' : add_query_arg('type', 'image', $restUrl);
        $imageScanStatus = function_exists('blc_get_image_scan_status_payload') ? blc_get_image_scan_status_payload() : array();
        $imagePollInterval = function_exists('apply_filters') ? apply_filters('blc_image_scan_status_poll_interval', 10000) : 10000;
        if (!is_int($imagePollInterval)) {
            $imagePollInterval = (int) $imagePollInterval;
        }

        $soft404Config = function_exists('blc_get_soft_404_heuristics') ? blc_get_soft_404_heuristics() : array();

        return array(
            'uiPresetKey'        => $uiPresetKey,
            'presetClass'        => $presetClass,
            'restUrl'            => $restUrl,
            'scanStatus'         => $scanStatus,
            'pollInterval'       => max(2000, $pollInterval),
            'imageRestUrl'       => $imageRestUrl,
            'imageScanStatus'    => $imageScanStatus,
            'imagePollInterval'  => max(2000, $imagePollInterval),
            'soft404Config'      => $soft404Config,
            'accessibilityPreferences' => $accessibilityPreferences,
            'settingsMode'       => function_exists('blc_get_settings_mode') ? blc_get_settings_mode() : 'simple',
        );
    }

    private function getPluginDir()
    {
        $path = plugin_dir_path($this->pluginFile);

        if (function_exists('trailingslashit')) {
            return trailingslashit($path);
        }

        return rtrim($path, '/\\') . '/';
    }

    private function getPluginUrl($relative)
    {
        return plugins_url($relative, $this->pluginFile);
    }
}
