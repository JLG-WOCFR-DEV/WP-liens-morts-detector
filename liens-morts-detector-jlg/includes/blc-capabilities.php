<?php

// Sécurité : empêche l'accès direct au fichier.
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('blc_get_capabilities_definition')) {
    /**
     * Retourne la liste des capacités personnalisées utilisées par le plugin.
     *
     * @return array<string,string>
     */
    function blc_get_capabilities_definition() {
        $capabilities = array(
            'view_reports'    => 'blc_view_reports',
            'fix_links'       => 'blc_fix_links',
            'manage_settings' => 'blc_manage_settings',
        );

        /**
         * Filtre la définition des capacités utilisées par le plugin.
         *
         * @param array<string,string> $capabilities Liste des capacités par contexte.
         */
        return apply_filters('blc_capabilities_definition', $capabilities);
    }
}

if (!function_exists('blc_get_required_capability')) {
    /**
     * Retourne le nom de la capacité requise pour un contexte donné.
     *
     * @param string $context Contexte de vérification (view_reports, fix_links, manage_settings, etc.).
     *
     * @return string
     */
    function blc_get_required_capability($context) {
        $definition = blc_get_capabilities_definition();
        $context    = is_string($context) ? strtolower($context) : '';

        if ($context !== '' && isset($definition[$context])) {
            return $definition[$context];
        }

        return 'manage_options';
    }
}

if (!function_exists('blc_user_can')) {
    /**
     * Vérifie si l'utilisateur courant (ou un utilisateur donné) possède une capacité.
     *
     * Le rôle administrateur (manage_options) conserve l'accès complet pour garantir la compatibilité
     * avec les installations existantes.
     *
     * @param string        $capability Capacité à vérifier.
     * @param int|WP_User|null $user Utilisateur cible (ID, instance ou null pour l'utilisateur courant).
     *
     * @return bool
     */
    function blc_user_can($capability, $user = null) {
        $capability = is_string($capability) ? $capability : '';
        if ($capability === '') {
            return current_user_can('manage_options');
        }

        $wp_user = null;

        if (is_object($user) && class_exists('WP_User') && $user instanceof WP_User) {
            $wp_user = $user;
        } elseif (is_numeric($user) && function_exists('get_user_by') && class_exists('WP_User')) {
            $wp_user = get_user_by('id', (int) $user);
        }

        if ($wp_user && class_exists('WP_User') && $wp_user instanceof WP_User) {
            if ($wp_user->has_cap($capability)) {
                return true;
            }

            if ($capability !== 'manage_options' && $wp_user->has_cap('manage_options')) {
                return true;
            }

            return false;
        }

        if (current_user_can($capability)) {
            return true;
        }

        if ($capability !== 'manage_options' && current_user_can('manage_options')) {
            return true;
        }

        return false;
    }
}

if (!function_exists('blc_current_user_can_view_reports')) {
    /**
     * Indique si l'utilisateur courant peut consulter les rapports du plugin.
     *
     * @return bool
     */
    function blc_current_user_can_view_reports() {
        return blc_user_can(blc_get_required_capability('view_reports'));
    }
}

if (!function_exists('blc_current_user_can_fix_links')) {
    /**
     * Indique si l'utilisateur courant peut appliquer des actions correctives sur les liens.
     *
     * @return bool
     */
    function blc_current_user_can_fix_links() {
        return blc_user_can(blc_get_required_capability('fix_links'));
    }
}

if (!function_exists('blc_current_user_can_manage_settings')) {
    /**
     * Indique si l'utilisateur courant peut gérer les réglages du plugin.
     *
     * @return bool
     */
    function blc_current_user_can_manage_settings() {
        return blc_user_can(blc_get_required_capability('manage_settings'));
    }
}

if (!function_exists('blc_register_default_capabilities')) {
    /**
     * Ajoute les capacités personnalisées aux rôles WordPress principaux.
     *
     * @return void
     */
    function blc_register_default_capabilities() {
        if (!function_exists('get_role')) {
            return;
        }

        $definition = blc_get_capabilities_definition();

        $role_map = array(
            'administrator' => array_values($definition),
            'editor'        => array(
                $definition['view_reports'],
                $definition['fix_links'],
            ),
        );

        /**
         * Filtre l'attribution des capacités par rôle.
         *
         * @param array<string,array<int,string>> $role_map Associations rôle → capacités.
         */
        $role_map = apply_filters('blc_role_capability_map', $role_map);

        foreach ($role_map as $role_name => $capabilities) {
            $role_name = is_string($role_name) ? $role_name : '';
            if ($role_name === '') {
                continue;
            }

            $role = get_role($role_name);
            if (!class_exists('WP_Role') || !$role instanceof WP_Role) {
                continue;
            }

            foreach ($capabilities as $capability) {
                $capability = is_string($capability) ? $capability : '';
                if ($capability === '') {
                    continue;
                }

                if (!$role->has_cap($capability)) {
                    $role->add_cap($capability);
                }
            }
        }
    }
}

if (!function_exists('blc_maybe_register_capabilities')) {
    /**
     * Garantit que les capacités personnalisées sont disponibles au chargement de WordPress.
     *
     * @return void
     */
    function blc_maybe_register_capabilities() {
        blc_register_default_capabilities();
    }

    add_action('init', 'blc_maybe_register_capabilities');
}
