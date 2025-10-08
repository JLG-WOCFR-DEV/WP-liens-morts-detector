<?php

namespace JLG\BrokenLinks\Admin;

class AdminScriptLocalizations
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, array<string, mixed>>
     */
    public function getScriptData(array $context)
    {
        return array(
            'blcAdminMessages'        => $this->getMessages(),
            'blcAdminUi'              => $this->getUiConfig($context),
            'blcAdminScanConfig'      => $this->getLinkScanConfig($context),
            'blcAdminNotifications'   => $this->getNotificationConfig(),
            'blcAdminImageScanConfig' => $this->getImageScanConfig($context),
            'blcAdminSoft404Config'   => $this->getSoft404Config($context),
        );
    }

    private function getMessages()
    {
        return array(
            /* translators: %s: original URL displayed in the edit prompt. */
            'editPromptMessage'  => __("Entrez la nouvelle URL pour :\n%s", 'liens-morts-detector-jlg'),
            'editPromptDefault'  => __('https://', 'liens-morts-detector-jlg'),
            'unlinkConfirmation' => __('Êtes-vous sûr de vouloir supprimer ce lien ? Le texte sera conservé.', 'liens-morts-detector-jlg'),
            'errorPrefix'        => __('Erreur : ', 'liens-morts-detector-jlg'),
            'editModalTitle'     => __('Modifier le lien', 'liens-morts-detector-jlg'),
            'editModalLabel'     => __('Nouvelle URL', 'liens-morts-detector-jlg'),
            'editModalConfirm'   => __('Mettre à jour', 'liens-morts-detector-jlg'),
            'unlinkModalTitle'   => __('Supprimer le lien', 'liens-morts-detector-jlg'),
            'unlinkModalConfirm' => __('Supprimer', 'liens-morts-detector-jlg'),
            'cancelButton'       => __('Annuler', 'liens-morts-detector-jlg'),
            'closeButton'        => __('Fermer', 'liens-morts-detector-jlg'),
            'closeLabel'         => __('Fermer la fenêtre modale', 'liens-morts-detector-jlg'),
            'simpleConfirmModalConfirm' => __('Confirmer', 'liens-morts-detector-jlg'),
            'simpleConfirmModalCancel'  => __('Annuler', 'liens-morts-detector-jlg'),
            'emptyUrlMessage'    => __('Veuillez saisir une URL.', 'liens-morts-detector-jlg'),
            'invalidUrlMessage'  => __('Veuillez saisir une URL valide.', 'liens-morts-detector-jlg'),
            'sameUrlMessage'     => __('La nouvelle URL doit être différente de l\'URL actuelle.', 'liens-morts-detector-jlg'),
            'genericError'        => __('Une erreur est survenue. Veuillez réessayer.', 'liens-morts-detector-jlg'),
            'successAnnouncement' => __('Action effectuée avec succès. La ligne a été retirée de la liste.', 'liens-morts-detector-jlg'),
            'noItemsMessage'      => __('Aucun lien cassé à afficher.', 'liens-morts-detector-jlg'),
            'ignoreModalTitle'    => __('Ignorer le lien', 'liens-morts-detector-jlg'),
            /* translators: %s: URL that will be ignored. */
            'ignoreModalMessage'  => __('Voulez-vous ignorer ce lien ? Il ne sera plus signalé.\n%s', 'liens-morts-detector-jlg'),
            'ignoreModalConfirm'  => __('Ignorer', 'liens-morts-detector-jlg'),
            'restoreModalTitle'   => __('Ne plus ignorer', 'liens-morts-detector-jlg'),
            /* translators: %s: URL that will be restored. */
            'restoreModalMessage' => __('Voulez-vous réintégrer ce lien dans la liste ?\n%s', 'liens-morts-detector-jlg'),
            'restoreModalConfirm' => __('Réintégrer', 'liens-morts-detector-jlg'),
            'ignoredAnnouncement' => __('Le lien est désormais ignoré.', 'liens-morts-detector-jlg'),
            'restoredAnnouncement' => __('Le lien n\'est plus ignoré.', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkIgnoreModalMessage'   => __('Voulez-vous ignorer les %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkRestoreModalMessage'  => __('Voulez-vous réintégrer les %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkUnlinkModalMessage'   => __('Voulez-vous dissocier les %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected items. */
            'bulkGenericModalMessage'  => __('Voulez-vous appliquer cette action aux %s éléments sélectionnés ?', 'liens-morts-detector-jlg'),
            'bulkNoSelectionMessage'   => __('Veuillez sélectionner au moins un lien avant d\'appliquer une action groupée.', 'liens-morts-detector-jlg'),
            'bulkSuccessAnnouncement'  => __('Les actions groupées ont été appliquées avec succès.', 'liens-morts-detector-jlg'),
            'applyRedirectConfirmation' => __('Appliquer la redirection détectée vers %s ?', 'liens-morts-detector-jlg'),
            'applyRedirectSuccess'      => __('La redirection détectée a été appliquée.', 'liens-morts-detector-jlg'),
            'applyRedirectError'        => __('Impossible d\'appliquer la redirection détectée.', 'liens-morts-detector-jlg'),
            'applyRedirectMissingTarget' => __('Aucune redirection détectée n\'est disponible pour ce lien.', 'liens-morts-detector-jlg'),
            'applyRedirectModalTitle'   => __('Appliquer la redirection détectée', 'liens-morts-detector-jlg'),
            'applyRedirectModalConfirm' => __('Appliquer', 'liens-morts-detector-jlg'),
            /* translators: %s: detected redirect target URL. */
            'applyRedirectModalMessage' => __('Voulez-vous appliquer la redirection détectée vers %s ?', 'liens-morts-detector-jlg'),
            'applyRedirectMissingModalTitle' => __('Redirection indisponible', 'liens-morts-detector-jlg'),
            'applyRedirectMissingModalMessage' => __('Aucune redirection détectée n\'est disponible pour ce lien.', 'liens-morts-detector-jlg'),
            /* translators: %s: number of selected links. */
            'bulkApplyRedirectModalMessage' => __('Voulez-vous appliquer la redirection détectée aux %s liens sélectionnés ?', 'liens-morts-detector-jlg'),
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function getUiConfig(array $context)
    {
        return array(
            'preset'      => isset($context['uiPresetKey']) ? (string) $context['uiPresetKey'] : 'default',
            'presetClass' => isset($context['presetClass']) ? (string) $context['presetClass'] : '',
            'enhanced'    => true,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function getLinkScanConfig(array $context)
    {
        $pollInterval = isset($context['pollInterval']) ? (int) $context['pollInterval'] : 10000;

        return array(
            'restUrl'         => isset($context['restUrl']) ? esc_url_raw((string) $context['restUrl']) : '',
            'restNonce'       => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            'startScanNonce'  => function_exists('wp_create_nonce') ? wp_create_nonce('blc_start_manual_scan') : '',
            'cancelScanNonce' => function_exists('wp_create_nonce') ? wp_create_nonce('blc_cancel_manual_scan') : '',
            'getStatusNonce'  => function_exists('wp_create_nonce') ? wp_create_nonce('blc_get_scan_status') : '',
            'pollInterval'    => max(2000, $pollInterval),
            'status'          => isset($context['scanStatus']) && is_array($context['scanStatus']) ? $context['scanStatus'] : array(),
            'scanType'        => 'link',
            'ajax'            => array(
                'start'  => 'blc_start_manual_scan',
                'cancel' => 'blc_cancel_manual_scan',
                'status' => 'blc_get_scan_status',
            ),
            'selectors'       => array(
                'panel'   => '#blc-scan-status-panel',
                'form'    => '#blc-manual-scan-form',
                'cancel'  => '#blc-cancel-scan',
                'restart' => '#blc-restart-scan',
                'fullScan'=> 'input[name="blc_full_scan"]',
            ),
            'i18n'            => array(
                'panelTitle'        => __('Statut du scan manuel', 'liens-morts-detector-jlg'),
                'states'            => array(
                    'idle'      => __('Inactif', 'liens-morts-detector-jlg'),
                    'queued'    => __('En file d\'attente', 'liens-morts-detector-jlg'),
                    'running'   => __('Analyse en cours', 'liens-morts-detector-jlg'),
                    'completed' => __('Terminée', 'liens-morts-detector-jlg'),
                    'failed'    => __('Échec', 'liens-morts-detector-jlg'),
                    'cancelled' => __('Annulée', 'liens-morts-detector-jlg'),
                ),
                'batchSummary'     => __('Lot %1$d sur %2$d', 'liens-morts-detector-jlg'),
                'remainingBatches' => __('Lots restants : %d', 'liens-morts-detector-jlg'),
                'nextBatch'        => __('Prochain lot prévu à %s', 'liens-morts-detector-jlg'),
                'queueMessage'     => __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg'),
                'startError'       => __('Impossible de lancer l\'analyse. Veuillez réessayer.', 'liens-morts-detector-jlg'),
                'cancelSuccess'    => __('Les lots planifiés ont été annulés.', 'liens-morts-detector-jlg'),
                'cancelError'      => __('Impossible d\'annuler l\'analyse. Veuillez réessayer.', 'liens-morts-detector-jlg'),
                'cancelConfirm'    => __('Voulez-vous annuler les lots planifiés ?', 'liens-morts-detector-jlg'),
                'cancelTitle'      => __('Annuler le scan', 'liens-morts-detector-jlg'),
                'cancelConfirmLabel' => __('Annuler', 'liens-morts-detector-jlg'),
                'restartConfirm'   => __('Voulez-vous reprogrammer immédiatement un nouveau scan ?', 'liens-morts-detector-jlg'),
                'restartTitle'     => __('Replanifier un scan', 'liens-morts-detector-jlg'),
                'restartConfirmLabel' => __('Replanifier', 'liens-morts-detector-jlg'),
                'unknownState'     => __('Statut inconnu', 'liens-morts-detector-jlg'),
            ),
        );
    }

    private function getNotificationConfig()
    {
        return array(
            'action'                 => 'blc_send_test_email',
            'nonce'                  => function_exists('wp_create_nonce') ? wp_create_nonce('blc_send_test_email') : '',
            'ajaxUrl'                => function_exists('admin_url') ? admin_url('admin-ajax.php') : '',
            'sendingText'            => __('Envoi du message de test…', 'liens-morts-detector-jlg'),
            'successText'            => __('Notifications de test envoyées avec succès.', 'liens-morts-detector-jlg'),
            'partialSuccessText'     => __('Notifications de test envoyées avec des avertissements.', 'liens-morts-detector-jlg'),
            'errorText'              => __('Échec de l’envoi de la notification de test. Veuillez vérifier vos réglages.', 'liens-morts-detector-jlg'),
            'missingRecipientsText'  => __('Ajoutez un destinataire ou configurez un webhook avant d’envoyer un test.', 'liens-morts-detector-jlg'),
            'missingChannelText'     => __('Sélectionnez au moins un type de résumé à tester.', 'liens-morts-detector-jlg'),
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function getImageScanConfig(array $context)
    {
        $pollInterval = isset($context['imagePollInterval']) ? (int) $context['imagePollInterval'] : 10000;

        return array(
            'restUrl'         => isset($context['imageRestUrl']) ? esc_url_raw((string) $context['imageRestUrl']) : '',
            'restNonce'       => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            'startScanNonce'  => function_exists('wp_create_nonce') ? wp_create_nonce('blc_start_manual_image_scan') : '',
            'cancelScanNonce' => function_exists('wp_create_nonce') ? wp_create_nonce('blc_cancel_manual_image_scan') : '',
            'getStatusNonce'  => function_exists('wp_create_nonce') ? wp_create_nonce('blc_get_image_scan_status') : '',
            'pollInterval'    => max(2000, $pollInterval),
            'status'          => isset($context['imageScanStatus']) && is_array($context['imageScanStatus']) ? $context['imageScanStatus'] : array(),
            'scanType'        => 'image',
            'ajax'            => array(
                'start'  => 'blc_start_manual_image_scan',
                'cancel' => 'blc_cancel_manual_image_scan',
                'status' => 'blc_get_image_scan_status',
            ),
            'selectors'       => array(
                'panel'   => '#blc-image-scan-status-panel',
                'form'    => '#blc-image-manual-scan-form',
                'cancel'  => '#blc-image-cancel-scan',
                'restart' => '#blc-image-restart-scan',
                'fullScan'=> '',
            ),
            'i18n'            => array(
                'panelTitle'        => __('Statut du scan des images', 'liens-morts-detector-jlg'),
                'states'            => array(
                    'idle'      => __('Inactif', 'liens-morts-detector-jlg'),
                    'queued'    => __('En file d\'attente', 'liens-morts-detector-jlg'),
                    'running'   => __('Analyse en cours', 'liens-morts-detector-jlg'),
                    'completed' => __('Terminée', 'liens-morts-detector-jlg'),
                    'failed'    => __('Échec', 'liens-morts-detector-jlg'),
                    'cancelled' => __('Annulée', 'liens-morts-detector-jlg'),
                ),
                'batchSummary'     => __('Lot %1$d sur %2$d', 'liens-morts-detector-jlg'),
                'remainingBatches' => __('Lots restants : %d', 'liens-morts-detector-jlg'),
                'nextBatch'        => __('Prochain lot prévu à %s', 'liens-morts-detector-jlg'),
                'queueMessage'     => __('Analyse programmée. Le premier lot démarrera sous peu.', 'liens-morts-detector-jlg'),
                'startError'       => __('Impossible de lancer l\'analyse des images. Veuillez réessayer.', 'liens-morts-detector-jlg'),
                'cancelSuccess'    => __('Les lots planifiés ont été annulés.', 'liens-morts-detector-jlg'),
                'cancelError'      => __('Impossible d\'annuler l\'analyse des images. Veuillez réessayer.', 'liens-morts-detector-jlg'),
                'cancelConfirm'    => __('Voulez-vous annuler les lots planifiés ?', 'liens-morts-detector-jlg'),
                'cancelTitle'      => __('Annuler le scan', 'liens-morts-detector-jlg'),
                'cancelConfirmLabel' => __('Annuler', 'liens-morts-detector-jlg'),
                'restartConfirm'   => __('Voulez-vous reprogrammer immédiatement un nouveau scan ?', 'liens-morts-detector-jlg'),
                'restartTitle'     => __('Replanifier un scan', 'liens-morts-detector-jlg'),
                'restartConfirmLabel' => __('Replanifier', 'liens-morts-detector-jlg'),
                'unknownState'     => __('Statut inconnu', 'liens-morts-detector-jlg'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function getSoft404Config(array $context)
    {
        $config = isset($context['soft404Config']) && is_array($context['soft404Config']) ? $context['soft404Config'] : array();

        $minLength = isset($config['min_length']) ? (int) $config['min_length'] : 0;
        $titleWeight = isset($config['title_weight']) ? (float) $config['title_weight'] : 0.0;
        $titleIndicators = isset($config['title_indicators']) && is_array($config['title_indicators']) ? array_values($config['title_indicators']) : array();
        $bodyIndicators = isset($config['body_indicators']) && is_array($config['body_indicators']) ? array_values($config['body_indicators']) : array();
        $ignorePatterns = isset($config['ignore_patterns']) && is_array($config['ignore_patterns']) ? array_values($config['ignore_patterns']) : array();

        return array(
            'minLength'       => $minLength,
            'titleWeight'     => $titleWeight,
            'titleIndicators' => $titleIndicators,
            'bodyIndicators'  => $bodyIndicators,
            'ignorePatterns'  => $ignorePatterns,
            'labels'          => array(
                'length' => __('Contenu trop court', 'liens-morts-detector-jlg'),
                'title'  => __('Titre suspect', 'liens-morts-detector-jlg'),
                'body'   => __('Message d’erreur détecté', 'liens-morts-detector-jlg'),
                'titleWeight' => __('Pondération du titre', 'liens-morts-detector-jlg'),
            ),
        );
    }
}
