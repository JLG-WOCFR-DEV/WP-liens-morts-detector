<?php

// Sécurité : empêche l'accès direct au fichier
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ajoute des planifications personnalisées (hebdomadaire, mensuelle) à la liste des
 * fréquences de WP-Cron.
 *
 * @param array $schedules Le tableau des planifications existantes.
 * @return array Le tableau des planifications mis à jour.
 */
function blc_add_cron_schedules($schedules) {
    
    // Ajoute l'option "Une fois par semaine"
    $schedules['weekly'] = array(
        'interval' => 604800, // 7 jours en secondes (7 * 24 * 60 * 60)
        'display'  => __('Une fois par semaine', 'liens-morts-detector-jlg')
    );
    
    // Ajoute l'option "Une fois par mois"
    $schedules['monthly'] = array(
        'interval' => 2592000, // 30 jours en secondes (30 * 24 * 60 * 60)
        'display'  => __('Une fois par mois', 'liens-morts-detector-jlg')
    );
    
    return $schedules;
}
