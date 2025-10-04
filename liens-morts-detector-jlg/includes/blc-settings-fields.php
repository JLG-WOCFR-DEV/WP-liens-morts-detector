<?php

// Sécurité : empêche l'accès direct au fichier.
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_normalize_hour_option')) {
    require_once __DIR__ . '/blc-utils.php';
}

add_action('admin_init', 'blc_register_settings');

/**
 * Enregistre toutes les options de la page de réglages via l'API Settings.
 *
 * @return void
 */
function blc_register_settings() {
    $option_group = 'blc_settings';
    $timeout_constraints = blc_get_request_timeout_constraints();
    $head_timeout_limits = isset($timeout_constraints['head']) ? $timeout_constraints['head'] : array('default' => 5);
    $get_timeout_limits  = isset($timeout_constraints['get']) ? $timeout_constraints['get'] : array('default' => 10);
    $recheck_constraints = blc_get_recheck_interval_days_constraints();

    register_setting(
        $option_group,
        'blc_frequency',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_frequency_option',
            'default'           => 'daily',
        )
    );

    register_setting(
        $option_group,
        'blc_frequency_custom_hours',
        array(
            'type'              => 'integer',
            'sanitize_callback' => 'blc_sanitize_frequency_custom_hours_option',
            'default'           => 24,
        )
    );

    register_setting(
        $option_group,
        'blc_frequency_custom_time',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_frequency_custom_time_option',
            'default'           => '00:00',
        )
    );

    register_setting(
        $option_group,
        'blc_rest_start_hour',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_rest_start_hour_option',
            'default'           => '08',
        )
    );

    register_setting(
        $option_group,
        'blc_rest_end_hour',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_rest_end_hour_option',
            'default'           => '20',
        )
    );

    register_setting(
        $option_group,
        'blc_recheck_interval_days',
        array(
            'type'              => 'integer',
            'sanitize_callback' => 'blc_sanitize_recheck_interval_days_option',
            'default'           => isset($recheck_constraints['default']) ? $recheck_constraints['default'] : 7,
        )
    );

    register_setting(
        $option_group,
        'blc_link_delay',
        array(
            'type'              => 'integer',
            'sanitize_callback' => 'blc_sanitize_link_delay_option',
            'default'           => 200,
        )
    );

    register_setting(
        $option_group,
        'blc_batch_delay',
        array(
            'type'              => 'integer',
            'sanitize_callback' => 'blc_sanitize_batch_delay_option',
            'default'           => 60,
        )
    );

    register_setting(
        $option_group,
        'blc_head_request_timeout',
        array(
            'type'              => 'number',
            'sanitize_callback' => 'blc_sanitize_head_timeout_option',
            'default'           => isset($head_timeout_limits['default']) ? $head_timeout_limits['default'] : 5,
        )
    );

    register_setting(
        $option_group,
        'blc_get_request_timeout',
        array(
            'type'              => 'number',
            'sanitize_callback' => 'blc_sanitize_get_timeout_option',
            'default'           => isset($get_timeout_limits['default']) ? $get_timeout_limits['default'] : 10,
        )
    );

    register_setting(
        $option_group,
        'blc_scan_method',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_scan_method_option',
            'default'           => 'precise',
        )
    );

    register_setting(
        $option_group,
        'blc_remote_image_scan_enabled',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'blc_sanitize_remote_image_scan_option',
            'default'           => false,
        )
    );

    register_setting(
        $option_group,
        'blc_post_types',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'blc_sanitize_post_types_option',
            'default'           => array(),
        )
    );

    register_setting(
        $option_group,
        'blc_post_statuses',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'blc_sanitize_post_statuses_option',
            'default'           => array('publish'),
        )
    );

    register_setting(
        $option_group,
        'blc_excluded_domains',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_excluded_domains_option',
            'default'           => "x.com\ntwitter.com\nlinkedin.com",
        )
    );

    register_setting(
        $option_group,
        'blc_debug_mode',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'blc_sanitize_debug_mode_option',
            'default'           => false,
        )
    );

    register_setting(
        $option_group,
        'blc_notification_links_enabled',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'blc_sanitize_notification_channel_option',
            'default'           => true,
        )
    );

    register_setting(
        $option_group,
        'blc_notification_images_enabled',
        array(
            'type'              => 'boolean',
            'sanitize_callback' => 'blc_sanitize_notification_channel_option',
            'default'           => true,
        )
    );

    register_setting(
        $option_group,
        'blc_notification_recipients',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_notification_recipients_option',
            'default'           => '',
        )
    );

    register_setting(
        $option_group,
        'blc_notification_webhook_url',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_notification_webhook_url_option',
            'default'           => '',
        )
    );

    register_setting(
        $option_group,
        'blc_notification_webhook_channel',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_notification_webhook_channel_option',
            'default'           => 'disabled',
        )
    );

    register_setting(
        $option_group,
        'blc_notification_message_template',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'blc_sanitize_notification_message_template_option',
            'default'           => "{{subject}}\n\n{{message}}",
        )
    );

    blc_register_settings_sections();
}

/**
 * Déclare les sections et les champs affichés dans la page de réglages.
 *
 * @return void
 */
function blc_register_settings_sections() {
    $page = 'blc-settings';

    add_settings_section(
        'blc_planification_section',
        __('Planification', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_frequency',
        __('Fréquence de vérification', 'liens-morts-detector-jlg'),
        'blc_render_frequency_field',
        $page,
        'blc_planification_section',
        array(
            'label_for' => 'blc_frequency',
        )
    );

    add_settings_field(
        'blc_rest_period',
        __('😴 Plage horaire de repos', 'liens-morts-detector-jlg'),
        'blc_render_rest_period_field',
        $page,
        'blc_planification_section',
        array(
            'label_for' => 'blc_rest_start_hour',
        )
    );

    add_settings_field(
        'blc_recheck_interval_days',
        __('🔁 Délai avant re-contrôle', 'liens-morts-detector-jlg'),
        'blc_render_recheck_interval_field',
        $page,
        'blc_planification_section',
        array(
            'label_for' => 'blc_recheck_interval_days',
        )
    );

    add_settings_section(
        'blc_performance_section',
        __('Performance', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_link_delay',
        __('⚙️ Délai entre chaque lien', 'liens-morts-detector-jlg'),
        'blc_render_number_field',
        $page,
        'blc_performance_section',
        array(
            'option_name' => 'blc_link_delay',
            'min'         => 0,
            'step'        => 50,
            'unit'        => __('ms', 'liens-morts-detector-jlg'),
            'description' => __('Pause après la vérification de chaque URL. (Défaut : 200)', 'liens-morts-detector-jlg'),
            'label_for'   => 'blc_link_delay',
        )
    );

    add_settings_field(
        'blc_batch_delay',
        __('⚙️ Délai entre chaque lot', 'liens-morts-detector-jlg'),
        'blc_render_number_field',
        $page,
        'blc_performance_section',
        array(
            'option_name' => 'blc_batch_delay',
            'min'         => 10,
            'step'        => 10,
            'unit'        => __('secondes', 'liens-morts-detector-jlg'),
            'description' => __('Pause entre chaque groupe de 20 articles analysés. (Défaut : 60)', 'liens-morts-detector-jlg'),
            'label_for'   => 'blc_batch_delay',
        )
    );

    add_settings_field(
        'blc_head_request_timeout',
        __('⏱️ Timeout requêtes HEAD', 'liens-morts-detector-jlg'),
        'blc_render_timeout_field',
        $page,
        'blc_performance_section',
        array(
            'option_name' => 'blc_head_request_timeout',
            'description' => __('Durée maximale accordée à chaque requête HEAD. (Défaut : 5)', 'liens-morts-detector-jlg'),
            'constraints' => 'head',
            'label_for'   => 'blc_head_request_timeout',
        )
    );

    add_settings_field(
        'blc_get_request_timeout',
        __('⏱️ Timeout requêtes GET', 'liens-morts-detector-jlg'),
        'blc_render_timeout_field',
        $page,
        'blc_performance_section',
        array(
            'option_name' => 'blc_get_request_timeout',
            'description' => __('Durée maximale accordée à chaque requête GET lors du fallback. (Défaut : 10)', 'liens-morts-detector-jlg'),
            'constraints' => 'get',
            'label_for'   => 'blc_get_request_timeout',
        )
    );

    add_settings_section(
        'blc_post_statuses_section',
        __('Statuts analysés', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_post_types',
        __('Types de contenus à analyser', 'liens-morts-detector-jlg'),
        'blc_render_post_types_field',
        $page,
        'blc_post_statuses_section'
    );

    add_settings_field(
        'blc_post_statuses',
        __('Statuts des contenus à analyser', 'liens-morts-detector-jlg'),
        'blc_render_post_statuses_field',
        $page,
        'blc_post_statuses_section'
    );

    add_settings_section(
        'blc_scan_section',
        __('Méthode d\'Analyse', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_scan_method',
        __('Stratégie de vérification', 'liens-morts-detector-jlg'),
        'blc_render_scan_method_field',
        $page,
        'blc_scan_section'
    );

    add_settings_field(
        'blc_excluded_domains',
        __('Liste d\'exclusion', 'liens-morts-detector-jlg'),
        'blc_render_excluded_domains_field',
        $page,
        'blc_scan_section',
        array(
            'label_for' => 'blc_excluded_domains',
        )
    );

    add_settings_section(
        'blc_images_section',
        __('Images distantes', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_remote_image_scan_enabled',
        __('Analyse des images CDN', 'liens-morts-detector-jlg'),
        'blc_render_remote_image_scan_field',
        $page,
        'blc_images_section'
    );

    add_settings_section(
        'blc_notifications_section',
        __('Notifications', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_notification_channels',
        __('Canaux d\'envoi', 'liens-morts-detector-jlg'),
        'blc_render_notification_channels_field',
        $page,
        'blc_notifications_section'
    );

    add_settings_field(
        'blc_notification_recipients',
        __('Destinataires du résumé', 'liens-morts-detector-jlg'),
        'blc_render_notification_recipients_field',
        $page,
        'blc_notifications_section',
        array(
            'label_for' => 'blc_notification_recipients',
        )
    );

    add_settings_section(
        'blc_debug_section',
        __('Débogage', 'liens-morts-detector-jlg'),
        '__return_false',
        $page
    );

    add_settings_field(
        'blc_debug_mode',
        __('Mode Débogage', 'liens-morts-detector-jlg'),
        'blc_render_debug_mode_field',
        $page,
        'blc_debug_section'
    );
}

/**
 * Calcule l'étiquette de fuseau horaire affichée sur la page des réglages.
 *
 * @return string
 */
function blc_get_timezone_label() {
    $timezone_label = '';

    if (function_exists('wp_timezone_string')) {
        $timezone_label = (string) wp_timezone_string();
    }

    if ('' === $timezone_label && function_exists('wp_timezone')) {
        $timezone_object = wp_timezone();
        if ($timezone_object instanceof DateTimeZone) {
            $timezone_label = $timezone_object->getName();
        }
    }

    if ('' === $timezone_label) {
        $timezone_label = (string) get_option('timezone_string', '');
    }

    if ('' === $timezone_label) {
        $gmt_offset = get_option('gmt_offset');
        if (is_numeric($gmt_offset) && (float) $gmt_offset !== 0.0) {
            $timezone_label = sprintf('UTC%+g', (float) $gmt_offset);
        } else {
            $timezone_label = 'UTC';
        }
    }

    return $timezone_label;
}

/**
 * Retourne les contraintes associées au délai de re-contrôle des liens.
 *
 * @since 1.1.0
 *
 * @return array<string, int>
 */
function blc_get_recheck_interval_days_constraints() {
    return array(
        'min'     => 1,
        'max'     => 30,
        'default' => 7,
    );
}

/**
 * Retourne la liste des fréquences prédéfinies disponibles dans l'interface.
 *
 * @since 1.1.0
 *
 * @return array<string, string> Tableau associatif `valeur => libellé`.
 */
function blc_get_frequency_preset_options() {
    $default_displays = blc_get_default_cron_schedules();

    $base_options = array(
        'blc_hourly'       => isset($default_displays['blc_hourly']['display'])
            ? $default_displays['blc_hourly']['display']
            : __('Toutes les heures', 'liens-morts-detector-jlg'),
        'blc_six_hours'    => isset($default_displays['blc_six_hours']['display'])
            ? $default_displays['blc_six_hours']['display']
            : __('Toutes les 6 heures', 'liens-morts-detector-jlg'),
        'blc_twelve_hours' => isset($default_displays['blc_twelve_hours']['display'])
            ? $default_displays['blc_twelve_hours']['display']
            : __('Toutes les 12 heures', 'liens-morts-detector-jlg'),
        'daily'            => __('Quotidienne', 'liens-morts-detector-jlg'),
        'weekly'           => isset($default_displays['weekly']['display'])
            ? $default_displays['weekly']['display']
            : __('Hebdomadaire', 'liens-morts-detector-jlg'),
        'monthly'          => isset($default_displays['monthly']['display'])
            ? $default_displays['monthly']['display']
            : __('Mensuelle', 'liens-morts-detector-jlg'),
    );

    /**
     * Permet de personnaliser les options affichées dans le sélecteur de fréquence.
     *
     * @since 1.1.0
     *
     * @param array $base_options Tableau associatif de valeurs => libellés ou définitions.
     */
    $options = apply_filters('blc_frequency_preset_options', $base_options);

    $normalized_options = array();

    foreach ($options as $value => $definition) {
        if (!is_scalar($value)) {
            continue;
        }

        $value = (string) $value;
        if ('' === $value) {
            continue;
        }

        if (is_array($definition)) {
            if (isset($definition['label']) && is_scalar($definition['label'])) {
                $label = (string) $definition['label'];
            } elseif (isset($definition['display']) && is_scalar($definition['display'])) {
                $label = (string) $definition['display'];
            } else {
                continue;
            }
        } else {
            $label = (string) $definition;
        }

        if ('' === $label) {
            continue;
        }

        $normalized_options[$value] = $label;
    }

    return $normalized_options;
}

/**
 * Affiche le champ de sélection de la fréquence de vérification.
 *
 * @return void
 */
function blc_render_frequency_field() {
    $frequency         = get_option('blc_frequency', 'daily');
    $custom_hours_raw  = get_option('blc_frequency_custom_hours', 24);
    $custom_hours      = blc_get_custom_frequency_hours($custom_hours_raw);
    $custom_time_raw   = get_option('blc_frequency_custom_time', '00:00');
    $custom_time_value = blc_prepare_time_input_value($custom_time_raw, '00:00');
    $max_hours         = (int) apply_filters('blc_custom_frequency_max_hours', 24 * 30);

    if ($max_hours < 1) {
        $max_hours = 1;
    }

    $is_custom_selected = ('custom' === $frequency);

    $preset_options = blc_get_frequency_preset_options();

    if (empty($preset_options)) {
        $preset_options = array(
            'daily' => __('Quotidienne', 'liens-morts-detector-jlg'),
        );
    }
    ?>
    <fieldset id="blc_frequency_fieldset" class="blc-frequency-field">
        <legend class="screen-reader-text"><?php esc_html_e('Fréquence de vérification des liens', 'liens-morts-detector-jlg'); ?></legend>
        <div class="blc-frequency-options">
            <?php foreach ($preset_options as $value => $label) : ?>
                <label class="blc-frequency-option">
                    <input type="radio" name="blc_frequency" value="<?php echo esc_attr($value); ?>" <?php checked($frequency, $value); ?>>
                    <span><?php echo esc_html($label); ?></span>
                </label>
            <?php endforeach; ?>
            <label class="blc-frequency-option blc-frequency-option--custom">
                <input type="radio" name="blc_frequency" value="custom" <?php checked($is_custom_selected); ?>>
                <span><?php esc_html_e('Intervalle personnalisé', 'liens-morts-detector-jlg'); ?></span>
            </label>
        </div>
        <div class="blc-frequency-custom-controls" aria-live="polite">
            <div class="blc-frequency-custom-row">
                <label for="blc_frequency_custom_hours" class="blc-frequency-custom-label">
                    <?php esc_html_e('Toutes les', 'liens-morts-detector-jlg'); ?>
                </label>
                <input
                    type="range"
                    min="1"
                    max="<?php echo esc_attr($max_hours); ?>"
                    step="1"
                    id="blc_frequency_custom_hours_slider"
                    value="<?php echo esc_attr($custom_hours); ?>"
                    aria-labelledby="blc_frequency_custom_hours_label"
                    <?php disabled(!$is_custom_selected); ?>
                >
                <label id="blc_frequency_custom_hours_label" class="screen-reader-text" for="blc_frequency_custom_hours">
                    <?php esc_html_e('Nombre d\'heures entre deux analyses automatiques', 'liens-morts-detector-jlg'); ?>
                </label>
                <input
                    type="number"
                    min="1"
                    max="<?php echo esc_attr($max_hours); ?>"
                    step="1"
                    id="blc_frequency_custom_hours"
                    name="blc_frequency_custom_hours"
                    value="<?php echo esc_attr($custom_hours); ?>"
                    <?php disabled(!$is_custom_selected); ?>
                >
                <span class="blc-frequency-custom-suffix"><?php esc_html_e('heures', 'liens-morts-detector-jlg'); ?></span>
            </div>
            <div class="blc-frequency-custom-row">
                <label for="blc_frequency_custom_time" class="blc-frequency-custom-label">
                    <?php esc_html_e('À déclencher à partir de', 'liens-morts-detector-jlg'); ?>
                </label>
                <input
                    type="time"
                    id="blc_frequency_custom_time"
                    name="blc_frequency_custom_time"
                    value="<?php echo esc_attr($custom_time_value); ?>"
                    <?php disabled(!$is_custom_selected); ?>
                >
                <span class="blc-frequency-custom-suffix blc-frequency-custom-timezone">
                    <?php echo esc_html(blc_get_timezone_label()); ?>
                </span>
            </div>
        </div>
    </fieldset>
    <p class="description">
        <?php
        echo wp_kses(
            __('Fréquence de la vérification automatique des <strong>liens</strong>.', 'liens-morts-detector-jlg'),
            array('strong' => array())
        );
        ?>
    </p>
    <script>
        (function () {
            var fieldset = document.getElementById('blc_frequency_fieldset');
            if (!fieldset) {
                return;
            }

            var radios = fieldset.querySelectorAll('input[name="blc_frequency"]');
            var slider = fieldset.querySelector('#blc_frequency_custom_hours_slider');
            var numberInput = fieldset.querySelector('#blc_frequency_custom_hours');
            var timeInput = fieldset.querySelector('#blc_frequency_custom_time');

            var syncInputs = function (source, target) {
                if (!source || !target) {
                    return;
                }

                target.value = source.value;
            };

            if (slider && numberInput) {
                slider.addEventListener('input', function () {
                    syncInputs(slider, numberInput);
                });

                numberInput.addEventListener('input', function () {
                    if (numberInput.value !== '') {
                        syncInputs(numberInput, slider);
                    }
                });
            }

            var toggleCustomControls = function () {
                var customRadio = fieldset.querySelector('input[name="blc_frequency"][value="custom"]');
                var isCustom = customRadio && customRadio.checked;
                var method = isCustom ? 'removeAttribute' : 'setAttribute';

                if (slider) {
                    slider[method]('disabled', 'disabled');
                }

                if (numberInput) {
                    numberInput[method]('disabled', 'disabled');
                }

                if (timeInput) {
                    timeInput[method]('disabled', 'disabled');
                }

                fieldset.classList.toggle('blc-frequency-field--custom', !!isCustom);
            };

            Array.prototype.forEach.call(radios, function (radio) {
                radio.addEventListener('change', toggleCustomControls);
            });

            toggleCustomControls();
        })();
    </script>
    <?php
}

/**
 * Affiche le champ de délai avant re-contrôle des liens.
 *
 * @return void
 */
function blc_render_recheck_interval_field() {
    $constraints = blc_get_recheck_interval_days_constraints();
    $min_days    = isset($constraints['min']) ? (int) $constraints['min'] : 1;
    $max_days    = isset($constraints['max']) ? (int) $constraints['max'] : 30;
    $default     = isset($constraints['default']) ? (int) $constraints['default'] : $min_days;

    $stored_value = get_option('blc_recheck_interval_days', $default);
    $value        = is_numeric($stored_value) ? (int) $stored_value : $default;

    if ($value < $min_days) {
        $value = $min_days;
    } elseif ($value > $max_days) {
        $value = $max_days;
    }

    ?>
    <div class="blc-recheck-interval-field">
        <label class="screen-reader-text" for="blc_recheck_interval_days"><?php esc_html_e('Nombre de jours avant re-vérification automatique', 'liens-morts-detector-jlg'); ?></label>
        <input
            type="range"
            name="blc_recheck_interval_days"
            id="blc_recheck_interval_days"
            value="<?php echo esc_attr($value); ?>"
            min="<?php echo esc_attr($min_days); ?>"
            max="<?php echo esc_attr($max_days); ?>"
            step="1"
        >
        <output id="blc_recheck_interval_days_output" for="blc_recheck_interval_days"><?php echo esc_html($value); ?></output>
        <?php esc_html_e('jours', 'liens-morts-detector-jlg'); ?>
        <p class="description">
            <?php esc_html_e('Détermine après combien de jours un lien non vérifié est de nouveau signalé comme à recontrôler dans la liste.', 'liens-morts-detector-jlg'); ?>
        </p>
    </div>
    <script>
        (function() {
            var slider = document.getElementById('blc_recheck_interval_days');
            var output = document.getElementById('blc_recheck_interval_days_output');

            if (!slider || !output) {
                return;
            }

            var update = function() {
                output.textContent = slider.value;
            };

            slider.addEventListener('input', update);
            update();
        })();
    </script>
    <?php
}

/**
 * Affiche les champs de plage horaire de repos.
 *
 * @return void
 */
function blc_render_rest_period_field() {
    $rest_start_hour_option = get_option('blc_rest_start_hour', '08');
    $rest_end_hour_option   = get_option('blc_rest_end_hour', '20');

    $rest_start_hour = blc_prepare_time_input_value($rest_start_hour_option, '08');
    $rest_end_hour   = blc_prepare_time_input_value($rest_end_hour_option, '20');
    $timezone_label  = blc_get_timezone_label();

    ?>
    <?php esc_html_e('Ne pas lancer de scan entre', 'liens-morts-detector-jlg'); ?>
    <label class="screen-reader-text" for="blc_rest_start_hour"><?php esc_html_e('Heure de début de la plage de repos', 'liens-morts-detector-jlg'); ?></label>
    <input type="time" name="blc_rest_start_hour" id="blc_rest_start_hour" value="<?php echo esc_attr($rest_start_hour); ?>">
    <?php esc_html_e('et', 'liens-morts-detector-jlg'); ?>
    <label class="screen-reader-text" for="blc_rest_end_hour"><?php esc_html_e('Heure de fin de la plage de repos', 'liens-morts-detector-jlg'); ?></label>
    <input type="time" name="blc_rest_end_hour" id="blc_rest_end_hour" value="<?php echo esc_attr($rest_end_hour); ?>">
    <p class="description">
        <?php
        $timezone_information = sprintf(
            /* translators: %s: timezone label. */
            __('Fuseau horaire : %s', 'liens-morts-detector-jlg'),
            esc_html($timezone_label)
        );

        $timezone_description = sprintf(
            /* translators: %s: formatted timezone information. */
            __('Le scan automatique des <strong>liens</strong> ne s\'exécutera pas durant cette période. %s', 'liens-morts-detector-jlg'),
            $timezone_information
        );

        echo wp_kses(
            $timezone_description,
            array('strong' => array())
        );
        ?>
    </p>
    <?php
}

/**
 * Affiche un champ numérique générique.
 *
 * @param array $args Arguments de configuration du champ.
 *
 * @return void
 */
function blc_render_number_field($args) {
    $option_name = isset($args['option_name']) ? (string) $args['option_name'] : '';
    if ('' === $option_name) {
        return;
    }

    if (isset($args['value'])) {
        $value = $args['value'];
    } else {
        $value = get_option($option_name, 0);
    }
    $min   = isset($args['min']) ? $args['min'] : null;
    $max   = isset($args['max']) ? $args['max'] : null;
    $step  = isset($args['step']) ? $args['step'] : 1;
    $unit  = isset($args['unit']) ? (string) $args['unit'] : '';

    $attributes = array(
        'type="number"',
        'name="' . esc_attr($option_name) . '"',
        'id="' . esc_attr($option_name) . '"',
        'value="' . esc_attr($value) . '"',
        'step="' . esc_attr($step) . '"',
    );

    if (null !== $min) {
        $attributes[] = 'min="' . esc_attr($min) . '"';
    }

    if (null !== $max) {
        $attributes[] = 'max="' . esc_attr($max) . '"';
    }

    echo '<input ' . implode(' ', $attributes) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    if ('' !== $unit) {
        echo ' ' . esc_html($unit);
    }

    if (!empty($args['description'])) {
        echo '<p class="description">' . esc_html($args['description']) . '</p>';
    }
}

/**
 * Affiche un champ numérique spécifique aux timeouts.
 *
 * @param array $args Arguments contenant notamment les contraintes à utiliser.
 *
 * @return void
 */
function blc_render_timeout_field($args) {
    $option_name = isset($args['option_name']) ? (string) $args['option_name'] : '';
    if ('' === $option_name) {
        return;
    }

    $constraints_key = isset($args['constraints']) ? (string) $args['constraints'] : '';
    $timeout_constraints = blc_get_request_timeout_constraints();

    if (!isset($timeout_constraints[$constraints_key])) {
        $limits = array('min' => 0, 'max' => 0, 'default' => 0);
    } else {
        $limits = $timeout_constraints[$constraints_key];
    }

    $value = blc_normalize_timeout_option(
        get_option($option_name, $limits['default']),
        $limits['default'],
        $limits['min'],
        $limits['max']
    );

    blc_render_number_field(
        array(
            'option_name' => $option_name,
            'min'         => $limits['min'],
            'max'         => $limits['max'],
            'step'        => 0.5,
            'unit'        => __('secondes', 'liens-morts-detector-jlg'),
            'description' => isset($args['description']) ? $args['description'] : '',
            'value'       => $value,
        )
    );
}

/**
 * Affiche la liste des statuts de contenus sélectionnables.
 *
 * @return void
 */
function blc_render_post_statuses_field() {
    $selected_post_statuses_option = get_option('blc_post_statuses', array('publish'));
    if (!is_array($selected_post_statuses_option)) {
        $selected_post_statuses_option = array($selected_post_statuses_option);
    }

    $selected_post_statuses = array();
    foreach ($selected_post_statuses_option as $status_value) {
        if (!is_scalar($status_value)) {
            continue;
        }

        $status_key = sanitize_key((string) $status_value);
        if ('' === $status_key) {
            continue;
        }

        $selected_post_statuses[$status_key] = $status_key;
    }

    if (array() === $selected_post_statuses) {
        $selected_post_statuses = array('publish');
    }

    $status_objects = get_post_stati(array(), 'objects');
    if (!is_array($status_objects)) {
        $status_objects = array();
    }

    foreach ($status_objects as $status_key => $status_object) {
        $sanitized_key = sanitize_key((string) $status_key);
        if ('' === $sanitized_key) {
            continue;
        }

        $label = '';
        if (is_object($status_object) && isset($status_object->label) && '' !== $status_object->label) {
            $label = (string) $status_object->label;
        }

        if ('' === $label) {
            $label = ucwords(str_replace(array('-', '_'), ' ', $sanitized_key));
        }

        ?>
        <label>
            <input type="checkbox" name="blc_post_statuses[]" value="<?php echo esc_attr($sanitized_key); ?>" <?php checked(in_array($sanitized_key, $selected_post_statuses, true)); ?>>
            <?php echo esc_html($label); ?>
        </label><br>
        <?php
    }

    ?>
    <p class="description"><?php esc_html_e('Sélectionnez les statuts des contenus à inclure dans l’analyse. Par défaut, seuls les contenus publiés sont examinés.', 'liens-morts-detector-jlg'); ?></p>
    <?php
}

/**
 * Affiche la liste des types de contenus sélectionnables.
 *
 * @return void
 */
function blc_render_post_types_field() {
    $selected_post_types_option = get_option('blc_post_types', array());
    if (!is_array($selected_post_types_option)) {
        $selected_post_types_option = array($selected_post_types_option);
    }

    $selected_post_types = array();
    foreach ($selected_post_types_option as $post_type_value) {
        if (!is_scalar($post_type_value)) {
            continue;
        }

        $post_type_key = sanitize_key((string) $post_type_value);
        if ('' === $post_type_key) {
            continue;
        }

        $selected_post_types[$post_type_key] = $post_type_key;
    }

    $fallback_post_types = get_post_types(array('public' => true), 'names');
    if (!is_array($fallback_post_types)) {
        $fallback_post_types = array();
    }

    $fallback_post_types = array_values(array_filter(array_map('sanitize_key', $fallback_post_types), static function ($post_type) {
        return '' !== $post_type;
    }));

    if (array() === $fallback_post_types) {
        $fallback_post_types = array('post');
    }

    if (array() === $selected_post_types) {
        foreach ($fallback_post_types as $post_type_key) {
            $selected_post_types[$post_type_key] = $post_type_key;
        }
    }

    $post_type_objects = get_post_types(array(), 'objects');
    if (!is_array($post_type_objects)) {
        $post_type_objects = array();
    }

    foreach ($post_type_objects as $post_type_key => $post_type_object) {
        $sanitized_key = sanitize_key((string) $post_type_key);
        if ('' === $sanitized_key) {
            continue;
        }

        $label = '';
        if (is_object($post_type_object)) {
            if (isset($post_type_object->labels) && is_object($post_type_object->labels) && isset($post_type_object->labels->name) && '' !== $post_type_object->labels->name) {
                $label = (string) $post_type_object->labels->name;
            } elseif (isset($post_type_object->label) && '' !== $post_type_object->label) {
                $label = (string) $post_type_object->label;
            }
        }

        if ('' === $label) {
            $label = ucwords(str_replace(array('-', '_'), ' ', $sanitized_key));
        }

        ?>
        <label>
            <input type="checkbox" name="blc_post_types[]" value="<?php echo esc_attr($sanitized_key); ?>" <?php checked(isset($selected_post_types[$sanitized_key])); ?>>
            <?php echo esc_html($label); ?>
        </label><br>
        <?php
    }
}

/**
 * Affiche les options de méthode d'analyse.
 *
 * @return void
 */
function blc_render_scan_method_field() {
    $scan_method = get_option('blc_scan_method', 'precise');
    ?>
    <fieldset>
        <div class="blc-scan-method-option">
            <label>
                <input
                    type="radio"
                    name="blc_scan_method"
                    value="precise"
                    <?php checked($scan_method, 'precise'); ?>
                    aria-describedby="blc-scan-method-precise-desc"
                >
                <strong><?php esc_html_e('Précise (recommandé)', 'liens-morts-detector-jlg'); ?></strong>
            </label>
            <p id="blc-scan-method-precise-desc" class="description"><?php esc_html_e('Simule un navigateur. Réduit les faux positifs, mais est un peu plus lent.', 'liens-morts-detector-jlg'); ?></p>
        </div>
        <div class="blc-scan-method-option">
            <label>
                <input
                    type="radio"
                    name="blc_scan_method"
                    value="fast"
                    <?php checked($scan_method, 'fast'); ?>
                    aria-describedby="blc-scan-method-fast-desc"
                >
                <strong><?php esc_html_e('Rapide', 'liens-morts-detector-jlg'); ?></strong>
            </label>
            <p id="blc-scan-method-fast-desc" class="description"><?php esc_html_e('Vérification basique. Très léger, mais peut générer des faux positifs.', 'liens-morts-detector-jlg'); ?></p>
        </div>
    </fieldset>
    <?php
}

/**
 * Affiche le champ de liste d'exclusion.
 *
 * @return void
 */
function blc_render_excluded_domains_field() {
    $excluded_domains = get_option('blc_excluded_domains', "x.com\ntwitter.com\nlinkedin.com");
    ?>
    <textarea name="blc_excluded_domains" id="blc_excluded_domains" rows="5" class="large-text"><?php echo esc_textarea($excluded_domains); ?></textarea>
    <p class="description"><?php esc_html_e('Domaines à ignorer pendant l’analyse. Un domaine par ligne (ex: amazon.fr).', 'liens-morts-detector-jlg'); ?></p>
    <?php
}

/**
 * Affiche le champ d'activation de l'analyse des images distantes.
 *
 * @return void
 */
function blc_render_remote_image_scan_field() {
    $remote_image_scan_enabled = (bool) get_option('blc_remote_image_scan_enabled', false);
    ?>
    <fieldset>
        <label for="blc_remote_image_scan_enabled">
            <input type="checkbox" name="blc_remote_image_scan_enabled" id="blc_remote_image_scan_enabled" value="1" <?php checked($remote_image_scan_enabled, true); ?>>
            <?php esc_html_e('Vérifier aussi les images servies depuis un domaine ou un CDN distinct.', 'liens-morts-detector-jlg'); ?>
        </label>
        <p class="description">
            <?php
            echo wp_kses(
                __('Activez cette option si vos images sont délivrées via un CDN ou un sous-domaine dédié. Le plugin s\'appuie toujours sur les fichiers présents dans <code>wp-content/uploads</code> pour détecter les absences. Cette vérification supplémentaire peut rallonger la durée du scan et consommer davantage de quotas côté CDN (latence, limitations de requêtes).', 'liens-morts-detector-jlg'),
                array('code' => array())
            );
            ?>
        </p>
    </fieldset>
    <?php
}

/**
 * Affiche le champ des destinataires de notification.
 *
 * @return void
 */
function blc_render_notification_recipients_field() {
    $notification_recipients = (string) get_option('blc_notification_recipients', '');
    ?>
    <textarea name="blc_notification_recipients" id="blc_notification_recipients" rows="3" class="large-text"><?php echo esc_textarea($notification_recipients); ?></textarea>
    <p class="description"><?php esc_html_e('Indiquez une adresse e-mail par ligne ou séparez-les par des virgules pour recevoir un résumé après chaque analyse.', 'liens-morts-detector-jlg'); ?></p>
    <p class="description"><?php esc_html_e('Le bouton de test ci-dessus utilisera ces destinataires pour l’envoi par e-mail. Laissez vide pour vous reposer uniquement sur les webhooks.', 'liens-morts-detector-jlg'); ?></p>
    <?php
}

/**
 * Affiche les réglages d'activation des notifications et le bouton de test.
 *
 * @return void
 */
function blc_render_notification_channels_field() {
    $links_enabled  = (bool) get_option('blc_notification_links_enabled', true);
    $images_enabled = (bool) get_option('blc_notification_images_enabled', true);
    $webhook_channel = blc_normalize_notification_webhook_channel(get_option('blc_notification_webhook_channel', 'disabled'));
    $webhook_url = (string) get_option('blc_notification_webhook_url', '');
    $message_template = (string) get_option('blc_notification_message_template', "{{subject}}\n\n{{message}}");
    $channel_choices = blc_get_notification_webhook_channel_choices();
    ?>
    <fieldset>
        <legend class="screen-reader-text"><span><?php esc_html_e('Canaux de notification', 'liens-morts-detector-jlg'); ?></span></legend>
        <label for="blc_notification_links_enabled" class="blc-toggle">
            <input type="checkbox" name="blc_notification_links_enabled" id="blc_notification_links_enabled" value="1" <?php checked($links_enabled, true); ?>>
            <?php esc_html_e('Envoyer une notification après un scan des liens', 'liens-morts-detector-jlg'); ?>
        </label>
        <br>
        <label for="blc_notification_images_enabled" class="blc-toggle">
            <input type="checkbox" name="blc_notification_images_enabled" id="blc_notification_images_enabled" value="1" <?php checked($images_enabled, true); ?>>
            <?php esc_html_e('Envoyer une notification après un scan des images', 'liens-morts-detector-jlg'); ?>
        </label>
        <p class="description"><?php esc_html_e('Choisissez les analyses qui déclenchent l’envoi du résumé (e-mail ou webhook).', 'liens-morts-detector-jlg'); ?></p>
        <hr>
        <p>
            <label for="blc_notification_webhook_channel"><strong><?php esc_html_e('Canal de webhook', 'liens-morts-detector-jlg'); ?></strong></label><br>
            <select name="blc_notification_webhook_channel" id="blc_notification_webhook_channel">
                <?php foreach ($channel_choices as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($webhook_channel, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="blc_notification_webhook_url"><strong><?php esc_html_e('URL du webhook', 'liens-morts-detector-jlg'); ?></strong></label><br>
            <input type="url" name="blc_notification_webhook_url" id="blc_notification_webhook_url" class="regular-text" value="<?php echo esc_attr($webhook_url); ?>" placeholder="https://example.com/webhook">
        </p>
        <p class="description"><?php esc_html_e('Le message est envoyé au format JSON. Les intégrations Slack et Teams utilisent la clé « text » tandis que le format générique inclut plusieurs champs (message, sujet…).', 'liens-morts-detector-jlg'); ?></p>
        <p>
            <label for="blc_notification_message_template"><strong><?php esc_html_e('Modèle de message', 'liens-morts-detector-jlg'); ?></strong></label><br>
            <textarea name="blc_notification_message_template" id="blc_notification_message_template" rows="4" class="large-text code"><?php echo esc_textarea($message_template); ?></textarea>
        </p>
        <p class="description"><?php esc_html_e('Placeholders disponibles : {{subject}}, {{message}}, {{dataset_type}}, {{dataset_label}}, {{broken_count}}, {{report_url}}, {{site_name}}.', 'liens-morts-detector-jlg'); ?></p>
        <p>
            <button type="button" class="button" id="blc-send-test-email"><?php esc_html_e('Envoyer une notification de test', 'liens-morts-detector-jlg'); ?></button>
            <span class="spinner" id="blc-test-email-spinner" aria-hidden="true"></span>
        </p>
        <div id="blc-test-email-feedback" class="blc-test-email-feedback" aria-live="polite"></div>
    </fieldset>
    <?php
}

/**
 * Affiche le champ permettant d'activer le mode débogage.
 *
 * @return void
 */
function blc_render_debug_mode_field() {
    $debug_mode = (bool) get_option('blc_debug_mode', false);
    ?>
    <fieldset>
        <label for="blc_debug_mode">
            <input type="checkbox" name="blc_debug_mode" id="blc_debug_mode" value="1" <?php checked($debug_mode, true); ?>>
            <?php esc_html_e('Activer le journal de débogage', 'liens-morts-detector-jlg'); ?>
        </label>
        <p class="description">
            <?php
            echo wp_kses_post(
                __('Écrit des informations dans <code>/wp-content/debug.log</code>. Nécessite que <code>WP_DEBUG_LOG</code> soit à <code>true</code> dans <code>wp-config.php</code>.', 'liens-morts-detector-jlg')
            );
            ?>
        </p>
    </fieldset>
    <?php
}

/**
 * Normalise la valeur d'heures soumise pour l'intervalle personnalisé.
 *
 * @param mixed $value    Valeur brute.
 * @param int   $fallback Valeur de repli.
 *
 * @return int
 */
function blc_normalize_custom_frequency_hours($value, $fallback) {
    $fallback_hours = blc_get_custom_frequency_hours($fallback);

    return blc_get_custom_frequency_hours(null === $value ? $fallback_hours : $value);
}

/**
 * Normalise l'heure de départ soumise pour l'intervalle personnalisé.
 *
 * @param mixed  $value    Valeur brute.
 * @param string $fallback Valeur de repli.
 *
 * @return string
 */
function blc_normalize_custom_frequency_time($value, $fallback) {
    $candidate = (null === $value || '' === $value) ? $fallback : $value;

    return blc_get_custom_frequency_time($candidate);
}

/**
 * Sanitize le nombre d'heures pour la fréquence personnalisée.
 *
 * @param mixed $value Valeur brute.
 *
 * @return int
 */
function blc_sanitize_frequency_custom_hours_option($value) {
    $previous = get_option('blc_frequency_custom_hours', 24);

    return blc_normalize_custom_frequency_hours($value, $previous);
}

/**
 * Sanitize l'heure de départ pour la fréquence personnalisée.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_frequency_custom_time_option($value) {
    $previous = get_option('blc_frequency_custom_time', '00:00');

    return blc_normalize_custom_frequency_time($value, $previous);
}

/**
 * Valide et normalise la fréquence de vérification.
 *
 * @param mixed $value Valeur brute soumise.
 *
 * @return string
 */
function blc_sanitize_frequency_option($value) {
    $preset_options     = blc_get_frequency_preset_options();
    $allowed_frequencies = array_keys($preset_options);
    $allowed_frequencies[] = 'custom';

    $previous_frequency_option    = get_option('blc_frequency', 'daily');
    $previous_frequency_sanitized = sanitize_text_field($previous_frequency_option);
    $fallback_frequency           = in_array($previous_frequency_sanitized, $allowed_frequencies, true)
        ? $previous_frequency_sanitized
        : 'daily';

    $frequency_raw       = is_scalar($value) ? (string) $value : '';
    $submitted_frequency = sanitize_text_field($frequency_raw);

    if (in_array($submitted_frequency, $allowed_frequencies, true)) {
        $frequency = $submitted_frequency;
    } else {
        $frequency        = $fallback_frequency;
        $frequency_labels = $preset_options;
        $frequency_labels['custom'] = __('Intervalle personnalisé', 'liens-morts-detector-jlg');
        $frequency_label = isset($frequency_labels[$frequency]) ? $frequency_labels[$frequency] : $frequency;
        $message         = sprintf(
            /* translators: %s: fallback frequency label. */
            esc_html__('La fréquence choisie est invalide. La valeur "%s" a été conservée.', 'liens-morts-detector-jlg'),
            esc_html($frequency_label)
        );
        add_settings_error('blc_settings', 'blc_frequency_warning', $message, 'warning');
    }

    $custom_hours_option = get_option('blc_frequency_custom_hours', 24);
    $custom_time_option  = get_option('blc_frequency_custom_time', '00:00');

    $custom_hours_override = null;
    if (isset($_POST['blc_frequency_custom_hours'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- géré via l'API Settings.
        $custom_hours_override = blc_normalize_custom_frequency_hours(
            wp_unslash($_POST['blc_frequency_custom_hours']),
            $custom_hours_option
        );
    }

    $custom_time_override = null;
    if (isset($_POST['blc_frequency_custom_time'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- géré via l'API Settings.
        $custom_time_override = blc_normalize_custom_frequency_time(
            wp_unslash($_POST['blc_frequency_custom_time']),
            $custom_time_option
        );
    }

    $schedule_result = blc_reset_link_check_schedule(
        array(
            'frequency'    => $frequency,
            'custom_hours' => $custom_hours_override,
            'custom_time'  => $custom_time_override,
            'context'      => 'settings',
        )
    );

    if (!$schedule_result['success']) {
        if ($schedule_result['error_message'] !== '') {
            error_log($schedule_result['error_message']);
        }

        $error_message = esc_html__(
            "La nouvelle planification n'a pas pu être programmée. Vérifiez la configuration de WP-Cron.",
            'liens-morts-detector-jlg'
        );

        add_settings_error('blc_settings', 'blc_frequency_schedule_error', $error_message, 'error');

        if ($schedule_result['restore_attempted']) {
            if ($schedule_result['restored']) {
                $restore_notice = esc_html__(
                    'La planification précédente a été restaurée automatiquement. Vérifiez que les prochaines analyses se lanceront correctement.',
                    'liens-morts-detector-jlg'
                );
                add_settings_error('blc_settings', 'blc_frequency_restore_notice', $restore_notice, 'warning');
            } else {
                $restore_warning = esc_html__(
                    "L'ancienne planification n'a pas pu être restaurée. Une intervention manuelle est nécessaire.",
                    'liens-morts-detector-jlg'
                );
                add_settings_error('blc_settings', 'blc_frequency_restore_warning', $restore_warning, 'warning');
            }
        } else {
            $restore_warning = esc_html__(
                "Aucune ancienne planification n'a été trouvée. Veuillez configurer manuellement la vérification automatique.",
                'liens-morts-detector-jlg'
            );
            add_settings_error('blc_settings', 'blc_frequency_restore_warning', $restore_warning, 'warning');
        }
    } else {
        add_settings_error('blc_settings', 'blc_settings_saved', esc_html__('Réglages enregistrés !', 'liens-morts-detector-jlg'), 'updated');
    }

    return $frequency;
}

function blc_sanitize_rest_hour_option($value, $option_name, $default) {
    $previous = get_option($option_name, $default);
    $raw      = is_scalar($value) ? (string) $value : '';
    $clean    = sanitize_text_field($raw);

    return blc_normalize_hour_option($clean, $previous ? $previous : $default);
}

/**
 * Normalise l'heure de début de repos.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_rest_start_hour_option($value) {
    return blc_sanitize_rest_hour_option($value, 'blc_rest_start_hour', '08');
}

/**
 * Normalise l'heure de fin de repos.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_rest_end_hour_option($value) {
    return blc_sanitize_rest_hour_option($value, 'blc_rest_end_hour', '20');
}

/**
 * Sanitize le délai avant re-contrôle automatique.
 *
 * @param mixed $value Valeur brute.
 *
 * @return int
 */
function blc_sanitize_recheck_interval_days_option($value) {
    $constraints = blc_get_recheck_interval_days_constraints();

    $min     = isset($constraints['min']) ? (int) $constraints['min'] : 1;
    $max     = isset($constraints['max']) ? (int) $constraints['max'] : 30;
    $default = isset($constraints['default']) ? (int) $constraints['default'] : $min;

    $sanitized = is_scalar($value) ? (int) $value : $default;

    if ($sanitized < $min) {
        $sanitized = $min;
    } elseif ($sanitized > $max) {
        $sanitized = $max;
    }

    return $sanitized;
}

/**
 * Sanitize le délai entre deux liens.
 *
 * @param mixed $value Valeur brute.
 *
 * @return int
 */
function blc_sanitize_link_delay_option($value) {
    return max(0, (int) $value);
}

/**
 * Sanitize le délai entre deux lots.
 *
 * @param mixed $value Valeur brute.
 *
 * @return int
 */
function blc_sanitize_batch_delay_option($value) {
    return max(0, (int) $value);
}

/**
 * Sanitize le timeout des requêtes HEAD.
 *
 * @param mixed $value Valeur brute.
 *
 * @return float
 */
function blc_sanitize_head_timeout_option($value) {
    $constraints = blc_get_request_timeout_constraints();
    $limits      = isset($constraints['head']) ? $constraints['head'] : array('default' => 5, 'min' => 1, 'max' => 10);

    $previous = blc_normalize_timeout_option(
        get_option('blc_head_request_timeout', $limits['default']),
        $limits['default'],
        $limits['min'],
        $limits['max']
    );

    return blc_normalize_timeout_option($value, $previous, $limits['min'], $limits['max']);
}

/**
 * Sanitize le timeout des requêtes GET.
 *
 * @param mixed $value Valeur brute.
 *
 * @return float
 */
function blc_sanitize_get_timeout_option($value) {
    $constraints = blc_get_request_timeout_constraints();
    $limits      = isset($constraints['get']) ? $constraints['get'] : array('default' => 10, 'min' => 1, 'max' => 30);

    $previous = blc_normalize_timeout_option(
        get_option('blc_get_request_timeout', $limits['default']),
        $limits['default'],
        $limits['min'],
        $limits['max']
    );

    return blc_normalize_timeout_option($value, $previous, $limits['min'], $limits['max']);
}

/**
 * Sanitize la méthode d'analyse choisie.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_scan_method_option($value) {
    $allowed_methods = array('precise', 'fast');
    $method_raw      = is_scalar($value) ? (string) $value : '';
    $method          = sanitize_text_field($method_raw);

    if (!in_array($method, $allowed_methods, true)) {
        $method = 'precise';
    }

    return $method;
}

/**
 * Sanitize l'activation de l'analyse des images distantes.
 *
 * @param mixed $value Valeur brute.
 *
 * @return bool
 */
function blc_sanitize_remote_image_scan_option($value) {
    return (bool) $value;
}

/**
 * Sanitize la liste des types de contenus sélectionnés.
 *
 * @param mixed $value Valeur brute.
 *
 * @return array
 */
function blc_sanitize_post_types_option($value) {
    $available_post_types = get_post_types(array(), 'names');
    if (!is_array($available_post_types)) {
        $available_post_types = array();
    }

    $available_lookup = array();
    foreach ($available_post_types as $post_type_name) {
        $normalized_post_type = sanitize_key((string) $post_type_name);
        if ('' === $normalized_post_type) {
            continue;
        }

        $available_lookup[$normalized_post_type] = true;
    }

    $raw_post_types = $value;
    if (!is_array($raw_post_types)) {
        $raw_post_types = array($raw_post_types);
    }

    $selected_post_types = array();
    foreach ($raw_post_types as $post_type_value) {
        if (!is_scalar($post_type_value)) {
            continue;
        }

        $post_type_key = sanitize_key((string) $post_type_value);
        if ('' === $post_type_key) {
            continue;
        }

        if (array() !== $available_lookup && !isset($available_lookup[$post_type_key])) {
            continue;
        }

        $selected_post_types[$post_type_key] = $post_type_key;
    }

    return array_values($selected_post_types);
}

/**
 * Sanitize les statuts de contenus sélectionnés.
 *
 * @param mixed $value Valeur brute.
 *
 * @return array
 */
function blc_sanitize_post_statuses_option($value) {
    $available_status_names = get_post_stati(array(), 'names');
    if (!is_array($available_status_names)) {
        $available_status_names = array();
    }

    $available_status_lookup = array();
    foreach ($available_status_names as $status_name) {
        $normalized_status = sanitize_key((string) $status_name);
        if ('' === $normalized_status) {
            continue;
        }

        $available_status_lookup[$normalized_status] = true;
    }

    $raw_statuses = $value;
    if (!is_array($raw_statuses)) {
        $raw_statuses = array($raw_statuses);
    }

    $selected_statuses = array();
    foreach ($raw_statuses as $status_value) {
        if (!is_scalar($status_value)) {
            continue;
        }

        $status_key = sanitize_key((string) $status_value);
        if ('' === $status_key) {
            continue;
        }

        if (array() !== $available_status_lookup && !isset($available_status_lookup[$status_key])) {
            continue;
        }

        $selected_statuses[$status_key] = $status_key;
    }

    if (array() === $selected_statuses) {
        if (isset($available_status_lookup['publish'])) {
            $selected_statuses = array('publish');
        } elseif (array() !== $available_status_lookup) {
            foreach ($available_status_lookup as $status_key => $_unused) {
                $selected_statuses = array($status_key);
                break;
            }
        } else {
            $selected_statuses = array('publish');
        }
    }

    return array_values($selected_statuses);
}

/**
 * Sanitize la liste des domaines exclus.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_excluded_domains_option($value) {
    $raw = is_scalar($value) ? (string) $value : '';

    return sanitize_textarea_field($raw);
}

/**
 * Sanitize le mode débogage.
 *
 * @param mixed $value Valeur brute.
 *
 * @return bool
 */
function blc_sanitize_debug_mode_option($value) {
    return (bool) $value;
}

/**
 * Sanitize les options booléennes liées aux canaux de notification.
 *
 * @param mixed $value Valeur brute.
 *
 * @return bool
 */
function blc_sanitize_notification_channel_option($value) {
    return (bool) $value;
}

/**
 * Sanitize la liste des destinataires de notification.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_notification_recipients_option($value) {
    $raw = is_scalar($value) ? (string) $value : '';

    return sanitize_textarea_field($raw);
}

/**
 * Retourne la liste des canaux de webhook disponibles.
 *
 * @return array<string, string>
 */
function blc_get_notification_webhook_channel_choices() {
    return array(
        'disabled' => __('Désactivé', 'liens-morts-detector-jlg'),
        'generic'  => __('Webhook générique (JSON)', 'liens-morts-detector-jlg'),
        'slack'    => __('Slack', 'liens-morts-detector-jlg'),
        'teams'    => __('Microsoft Teams', 'liens-morts-detector-jlg'),
    );
}

/**
 * Normalise le canal de webhook sélectionné.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_normalize_notification_webhook_channel($value) {
    if (!is_scalar($value)) {
        $value = 'disabled';
    }

    $value = sanitize_key((string) $value);
    $choices = blc_get_notification_webhook_channel_choices();

    if (!isset($choices[$value])) {
        $value = 'disabled';
    }

    return $value;
}

/**
 * Normalise l'URL de webhook configurée.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_normalize_notification_webhook_url($value) {
    if (!is_scalar($value)) {
        return '';
    }

    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = esc_url_raw($value);

    return (string) $value;
}

/**
 * Normalise le modèle de message des webhooks.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_normalize_notification_message_template($value) {
    if (!is_scalar($value)) {
        $value = '';
    }

    $value = (string) $value;
    $value = wp_unslash($value);
    $value = str_replace(array("\r\n", "\r"), "\n", $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value);
    $value = trim($value);

    if ($value === '') {
        $value = "{{subject}}\n\n{{message}}";
    }

    return $value;
}

/**
 * Sanitize l'URL du webhook de notification.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_notification_webhook_url_option($value) {
    return blc_normalize_notification_webhook_url($value);
}

/**
 * Sanitize le canal de webhook sélectionné.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_notification_webhook_channel_option($value) {
    return blc_normalize_notification_webhook_channel($value);
}

/**
 * Sanitize le modèle de message des webhooks.
 *
 * @param mixed $value Valeur brute.
 *
 * @return string
 */
function blc_sanitize_notification_message_template_option($value) {
    return blc_normalize_notification_message_template($value);
}

