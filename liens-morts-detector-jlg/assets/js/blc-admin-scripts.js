jQuery(document).ready(function($) {
    var ACTION_FOCUS_SELECTOR = '.blc-edit-link, .blc-unlink, .blc-ignore, .blc-suggest-redirect, .blc-view-context, .blc-recheck';

    var defaultMessages = {
        editPromptMessage: "Entrez la nouvelle URL pour :\n%s",
        editPromptDefault: 'https://',
        unlinkConfirmation: "Êtes-vous sûr de vouloir supprimer ce lien ? Le texte sera conservé.",
        errorPrefix: 'Erreur : ',
        editModalTitle: 'Modifier le lien',
        editModalLabel: 'Nouvelle URL',
        editModalConfirm: 'Mettre à jour',
        unlinkModalTitle: 'Supprimer le lien',
        unlinkModalConfirm: 'Supprimer',
        cancelButton: 'Annuler',
        closeButton: 'Fermer',
        closeLabel: 'Fermer la fenêtre modale',
        emptyUrlMessage: 'Veuillez saisir une URL.',
        invalidUrlMessage: 'Veuillez saisir une URL valide.',
        sameUrlMessage: "La nouvelle URL doit être différente de l'URL actuelle.",
        genericError: 'Une erreur est survenue. Veuillez réessayer.',
        successAnnouncement: 'La ligne a été mise à jour avec succès.',
        noItemsMessage: 'Aucun élément à afficher.',
        ignoreModalTitle: 'Ignorer le lien',
        ignoreModalMessage: 'Voulez-vous ignorer ce lien ?\n%s',
        ignoreModalConfirm: 'Ignorer',
        restoreModalTitle: 'Ne plus ignorer',
        restoreModalMessage: 'Voulez-vous réintégrer ce lien dans la liste ?\n%s',
        restoreModalConfirm: 'Réintégrer',
        ignoredAnnouncement: 'Le lien est désormais ignoré.',
        restoredAnnouncement: "Le lien n'est plus ignoré.",
        bulkIgnoreModalMessage: 'Voulez-vous ignorer les %s liens sélectionnés ?',
        bulkRestoreModalMessage: 'Voulez-vous réintégrer les %s liens sélectionnés ?',
        bulkUnlinkModalMessage: 'Voulez-vous dissocier les %s liens sélectionnés ?',
        bulkGenericModalMessage: 'Voulez-vous appliquer cette action aux %s éléments sélectionnés ?',
        bulkNoSelectionMessage: 'Veuillez sélectionner au moins un lien avant de lancer une action groupée.',
        bulkSuccessAnnouncement: 'Les actions groupées ont été appliquées avec succès.',
        suggestRedirectModalTitle: 'Proposer une redirection',
        suggestRedirectModalLabel: 'URL proposée',
        suggestRedirectModalConfirm: 'Enregistrer',
        contextModalTitle: 'Contexte du lien',
        contextModalEmpty: 'Aucun extrait disponible pour ce lien.',
        contextLabel: 'Contexte',
        recheckInProgress: 'Re-vérification du lien en cours…',
        recheckSuccess: 'La re-vérification du lien est terminée.',
        recheckError: 'Impossible de re-vérifier le lien. Veuillez réessayer.'
    };

    var messages = $.extend({}, defaultMessages, window.blcAdminMessages || {});

    function formatTemplate(template, value) {
        if (typeof template !== 'string') {
            return '';
        }

        var replacement = (typeof value === 'undefined' || value === null) ? '' : String(value);
        var result = template.replace(/%1\$s/g, replacement);

        return result.replace(/%s/g, replacement);
    }

    var accessibility = (function() {
        var $liveRegion = null;

        function ensureLiveRegion() {
            if ($liveRegion && $liveRegion.length && document.body.contains($liveRegion[0])) {
                return $liveRegion;
            }

            $liveRegion = $('<div>', {
                class: 'blc-aria-live screen-reader-text',
                'aria-live': 'polite',
                'aria-atomic': 'true'
            });

            $('body').append($liveRegion);

            return $liveRegion;
        }

        function speak(message, politeness) {
            if (!message) {
                return;
            }

            if (window.wp && wp.a11y && typeof wp.a11y.speak === 'function') {
                wp.a11y.speak(message, politeness || 'polite');
                return;
            }

            var $region = ensureLiveRegion();
            $region.text('');

            window.setTimeout(function() {
                $region.text(message);
            }, 50);
        }

        return {
            speak: speak,
            ensureLiveRegion: ensureLiveRegion
        };
    })();

    window.blcAdmin = window.blcAdmin || {};
    window.blcAdmin.accessibility = accessibility;

    function createNoticeElement(type, message) {
        var classes = 'notice';
        switch (type) {
            case 'success':
                classes += ' notice-success';
                break;
            case 'error':
                classes += ' notice-error';
                break;
            case 'warning':
                classes += ' notice-warning';
                break;
            default:
                classes += ' notice-info';
                break;
        }

        var $notice = $('<div>', { class: classes });
        if (message) {
            $('<p>').text(message).appendTo($notice);
        }

        return $notice;
    }

    (function setupTestEmailButton() {
        var config = window.blcAdminNotifications || null;
        if (!config || !config.nonce) {
            return;
        }

        var $button = $('#blc-send-test-email');
        if (!$button.length) {
            return;
        }

        var $spinner = $('#blc-test-email-spinner');
        var $feedback = $('#blc-test-email-feedback');
        var $recipients = $('#blc_notification_recipients');
        var $linkToggle = $('#blc_notification_links_enabled');
        var $imageToggle = $('#blc_notification_images_enabled');
        var $webhookUrl = $('#blc_notification_webhook_url');
        var $webhookChannel = $('#blc_notification_webhook_channel');
        var $messageTemplate = $('#blc_notification_message_template');
        var isSending = false;

        function ensureFeedbackContainer() {
            if ($feedback && $feedback.length) {
                return $feedback;
            }

            var $container = $('<div>', {
                id: 'blc-test-email-feedback',
                class: 'blc-test-email-feedback',
                'aria-live': 'polite'
            });

            var $targetCell = $button.closest('td');
            if ($targetCell.length) {
                $targetCell.append($container);
            } else {
                $button.after($container);
            }

            $feedback = $container;
            return $feedback;
        }

        function showFeedback(type, message) {
            var $container = ensureFeedbackContainer();
            $container.empty();

            if (!message) {
                return;
            }

            var $notice = createNoticeElement(type, message);
            $container.append($notice);

            var politeness = type === 'error' ? 'assertive' : 'polite';
            accessibility.speak(message, politeness);
        }

        function setSending(state) {
            isSending = state;
            $button.prop('disabled', state);

            if ($spinner.length) {
                $spinner.toggleClass('is-active', state);
            }

            if (state) {
                $button.attr('aria-busy', 'true');
            } else {
                $button.removeAttr('aria-busy');
            }
        }

        $button.on('click', function(event) {
            event.preventDefault();

            if (isSending) {
                return;
            }

            var recipientsValue = '';
            if ($recipients.length) {
                recipientsValue = $recipients.val();
            }

            var hasRecipients = recipientsValue && $.trim(String(recipientsValue)) !== '';
            var webhookUrlValue = '';
            var webhookChannelValue = '';

            if ($webhookUrl.length) {
                webhookUrlValue = $.trim(String($webhookUrl.val()));
            }

            if ($webhookChannel.length) {
                webhookChannelValue = String($webhookChannel.val());
            }

            var hasWebhook = webhookUrlValue !== '' && webhookChannelValue && webhookChannelValue !== 'disabled';

            if (!hasRecipients && !hasWebhook) {
                showFeedback('warning', config.missingRecipientsText || '');
                return;
            }

            var datasetTypes = [];
            if ($linkToggle.length && $linkToggle.is(':checked')) {
                datasetTypes.push('link');
            }
            if ($imageToggle.length && $imageToggle.is(':checked')) {
                datasetTypes.push('image');
            }

            if (!datasetTypes.length) {
                showFeedback('warning', config.missingChannelText || '');
                return;
            }

            var ajaxEndpoint = config.ajaxUrl || (typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '');
            if (!ajaxEndpoint) {
                showFeedback('error', config.errorText || '');
                return;
            }

            setSending(true);
            if (config.sendingText) {
                showFeedback('info', config.sendingText);
            }

            $.post(ajaxEndpoint, {
                action: config.action,
                _ajax_nonce: config.nonce,
                recipients: recipientsValue,
                dataset_types: datasetTypes,
                webhook_url: webhookUrlValue,
                webhook_channel: webhookChannelValue,
                message_template: $messageTemplate.length ? $messageTemplate.val() : ''
            }).done(function(response) {
                if (response && response.success) {
                    var message = (response.data && response.data.message) ? response.data.message : (config.successText || '');
                    var type = (response.data && response.data.partial) ? 'warning' : 'success';
                    if (response.data && response.data.partial && !response.data.message && config.partialSuccessText) {
                        message = config.partialSuccessText;
                    }
                    showFeedback(type, message);
                } else {
                    var errorMessage = (response && response.data && response.data.message)
                        ? response.data.message
                        : (config.errorText || '');
                    showFeedback('error', errorMessage);
                }
            }).fail(function() {
                showFeedback('error', config.errorText || '');
            }).always(function() {
                setSending(false);
            });
        });
    })();

    (function announceBulkNotice() {
        var $notice = $('.blc-bulk-notice');

        if (!$notice.length) {
            return;
        }

        var announcement = $notice.data('blcBulkAnnouncement');

        if (!announcement) {
            announcement = $.trim($notice.text());
        }

        if (!announcement) {
            announcement = messages.bulkSuccessAnnouncement || '';
        }

        if (announcement) {
            accessibility.speak(announcement, 'polite');
        }
    })();

    var modal = (function() {
        var $modal = $('#blc-modal');

        if (!$modal.length) {
            return {
                open: function() {},
                close: function() {},
                helpers: {
                    showError: function() {},
                    clearError: function() {},
                    setSubmitting: function() {},
                    close: function() {}
                }
            };
        }

        if (!$modal.attr('tabindex')) {
            $modal.attr('tabindex', '-1');
        }

        var $title = $modal.find('.blc-modal__title');
        var $message = $modal.find('.blc-modal__message');
        var $context = $modal.find('.blc-modal__context');
        var $error = $modal.find('.blc-modal__error');
        var $field = $modal.find('.blc-modal__field');
        var $label = $modal.find('.blc-modal__label');
        var $input = $modal.find('.blc-modal__input');
        var $confirm = $modal.find('.blc-modal__confirm');
        var $cancel = $modal.find('.blc-modal__cancel');
        var $close = $modal.find('.blc-modal__close');

        var lastFocusedElement = null;
        var focusableSelectors = 'a[href], area[href], input:not([type="hidden"]), select, textarea, button, [tabindex], [contenteditable="true"]';

        function getFocusableElements() {
            return $modal.find(focusableSelectors).filter(function() {
                var $element = $(this);
                if (!$element.is(':visible')) {
                    return false;
                }

                if ($element.is(':disabled') || $element.attr('disabled')) {
                    return false;
                }

                var tabindex = $element.attr('tabindex');
                if (typeof tabindex !== 'undefined' && parseInt(tabindex, 10) < 0) {
                    return false;
                }

                if ($element.attr('aria-hidden') === 'true') {
                    return false;
                }

                return true;
            });
        }

        var state = {
            isOpen: false,
            onConfirm: null,
            showInput: true,
            showCancel: true,
            isSubmitting: false
        };

        function clearError() {
            $error.removeClass('is-visible').text('');
        }

        function clearContext() {
            if (!$context.length) {
                return;
            }

            $context.empty().addClass('is-hidden');
        }

        function setContext(options) {
            if (!$context.length) {
                return;
            }

            clearContext();

            if (!options) {
                return;
            }

            var rawHtml = typeof options.contextHtml === 'string' ? options.contextHtml : '';
            var rawText = typeof options.context === 'string' ? options.context : '';
            var hasHtml = rawHtml.trim() !== '';
            var hasText = rawText.trim() !== '';

            if (!hasHtml && !hasText) {
                return;
            }

            var label = typeof options.contextLabel === 'string' ? options.contextLabel : (messages.contextLabel || '');
            var $wrapper = $('<div>', { class: 'blc-modal__context-inner' });

            if (label) {
                $('<strong>', { class: 'blc-modal__context-label' }).text(label).appendTo($wrapper);
            }

            if (hasHtml) {
                $('<div>', { class: 'blc-modal__context-html' }).html(rawHtml).appendTo($wrapper);
            } else if (hasText) {
                $('<p>', { class: 'blc-modal__context-text' }).text(rawText).appendTo($wrapper);
            }

            $context.append($wrapper).removeClass('is-hidden');
        }

        function showError(message) {
            if (message) {
                $error.text(message).addClass('is-visible');
            } else {
                clearError();
            }
        }

        function setSubmitting(isSubmitting) {
            state.isSubmitting = isSubmitting;
            $confirm.prop('disabled', isSubmitting);
            $cancel.prop('disabled', isSubmitting);
            $close.prop('disabled', isSubmitting);
            $modal.toggleClass('is-submitting', isSubmitting);
        }

        function normalizeFocusTarget(focusTarget) {
            if (!focusTarget) {
                return null;
            }

            if (focusTarget.jquery) {
                focusTarget = focusTarget.get(0);
            }

            if (focusTarget && typeof focusTarget.focus === 'function') {
                return focusTarget;
            }

            return null;
        }

        function close(focusTarget) {
            if (!state.isOpen) {
                return;
            }

            state.isOpen = false;
            state.onConfirm = null;
            state.showInput = true;
            state.showCancel = true;

            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('blc-modal-open');

            setSubmitting(false);
            clearError();
            clearContext();

            $title.text('');
            $message.text('');
            $label.text('');
            $input.val('').attr('type', 'url');
            $field.removeClass('is-hidden');

            var finalFocusTarget = normalizeFocusTarget(focusTarget);

            var body = document.body;

            if (!finalFocusTarget && lastFocusedElement && body && typeof body.contains === 'function' && body.contains(lastFocusedElement) && typeof lastFocusedElement.focus === 'function') {
                finalFocusTarget = lastFocusedElement;
            }

            if (finalFocusTarget) {
                window.setTimeout(function() {
                    finalFocusTarget.focus();
                }, 0);
            }

            lastFocusedElement = null;
        }

        function open(options) {
            if (!$modal.length) {
                return;
            }

            options = options || {};

            state.onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : null;
            state.showInput = options.showInput !== false;
            state.showCancel = options.showCancel !== false;

            lastFocusedElement = document.activeElement;

            $title.text(options.title || '');
            $message.text(options.message || '');

            var labelText = options.label || (state.showInput ? messages.editModalLabel : '');
            $label.text(labelText);

            var placeholder = options.placeholder || messages.editPromptDefault || '';
            $input.attr('placeholder', placeholder);

            if (state.showInput) {
                $field.removeClass('is-hidden');
                $input.val(options.defaultValue || '').attr('type', options.inputType || 'url');
            } else {
                $field.addClass('is-hidden');
                $input.val('');
            }

            var confirmText = options.confirmText;
            if (!confirmText) {
                confirmText = state.showInput ? messages.editModalConfirm : messages.unlinkModalConfirm;
            }
            $confirm.text(confirmText || messages.editModalConfirm || 'Confirmer');

            var cancelText = options.cancelText || messages.cancelButton || 'Annuler';
            $cancel.text(cancelText);

            if (state.showCancel) {
                $cancel.show().prop('hidden', false).removeAttr('hidden').removeAttr('aria-hidden');
            } else {
                $cancel.hide().prop('hidden', true).attr('hidden', 'hidden').attr('aria-hidden', 'true');
            }
            $close.attr('aria-label', options.closeLabel || messages.closeLabel || 'Fermer');

            clearError();
            setContext(options);
            setSubmitting(false);

            $modal.addClass('is-open').attr('aria-hidden', 'false');
            $('body').addClass('blc-modal-open');
            state.isOpen = true;

            window.setTimeout(function() {
                if (state.showInput) {
                    $input.trigger('focus').select();
                } else {
                    $confirm.trigger('focus');
                }
            }, 10);
        }

        var helpers = {
            showError: showError,
            clearError: clearError,
            setSubmitting: setSubmitting,
            close: close
        };

        $confirm.on('click', function() {
            if (!state.isOpen || state.isSubmitting) {
                return;
            }

            var value = state.showInput ? $input.val() : '';

            if (state.onConfirm) {
                state.onConfirm(value, helpers);
            }
        });

        $cancel.on('click', function() {
            if (!state.isSubmitting) {
                close();
            }
        });

        $close.on('click', function() {
            if (!state.isSubmitting) {
                close();
            }
        });

        $modal.on('click', function(event) {
            if (event.target === $modal[0] && !state.isSubmitting) {
                close();
            }
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape' && state.isOpen && !state.isSubmitting) {
                close();
            }
        });

        $modal.on('keydown', function(event) {
            if (event.key !== 'Tab' || !state.isOpen) {
                return;
            }

            var focusableElements = getFocusableElements();

            if (!focusableElements.length) {
                event.preventDefault();
                $modal.focus();
                return;
            }

            var activeElement = document.activeElement;
            var currentIndex = focusableElements.index(activeElement);
            var direction = event.shiftKey ? -1 : 1;
            var nextIndex;

            if (currentIndex === -1) {
                nextIndex = direction > 0 ? 0 : focusableElements.length - 1;
            } else {
                nextIndex = currentIndex + direction;
                if (nextIndex < 0) {
                    nextIndex = focusableElements.length - 1;
                } else if (nextIndex >= focusableElements.length) {
                    nextIndex = 0;
                }
            }

            event.preventDefault();

            var $nextElement = focusableElements.eq(nextIndex);
            if ($nextElement.length && typeof $nextElement[0].focus === 'function') {
                $nextElement[0].focus();
            }
        });

        $input.on('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $confirm.trigger('click');
            }
        });

        return {
            open: open,
            close: close,
            helpers: helpers
        };
    })();

    function getAnnouncementMessage(response) {
        if (!response || !response.data) {
            return messages.successAnnouncement || '';
        }

        var data = response.data;

        if (typeof data.announcement === 'string' && data.announcement.trim()) {
            return data.announcement.trim();
        }

        if (typeof data.message === 'string' && data.message.trim()) {
            return data.message.trim();
        }

        return messages.successAnnouncement || '';
    }

    function findNextFocusTarget(row) {
        var $row = row && row.jquery ? row : $(row);

        if (!$row || !$row.length) {
            return null;
        }

        var $candidate = $row.nextAll('tr').filter(':visible').find(ACTION_FOCUS_SELECTOR).filter(':visible').first();

        if (!$candidate.length) {
            $candidate = $row.prevAll('tr').filter(':visible').find(ACTION_FOCUS_SELECTOR).filter(':visible').first();
        }

        if (!$candidate.length) {
            $candidate = $('#post-query-submit').filter(':visible').first();
        }

        if (!$candidate.length) {
            $candidate = $('.tablenav .button, .tablenav input[type="submit"]').filter(':visible').first();
        }

        return $candidate.length ? $candidate[0] : null;
    }

    function determineColumnCount($tbody, $row) {
        var columnCount = 0;
        var $normalizedTbody = $tbody && $tbody.jquery ? $tbody : $();
        var $table = $normalizedTbody.length ? $normalizedTbody.closest('table') : $();

        if ($table.length) {
            var $headerCells = $table.find('thead tr:first').children('th:visible, td:visible');
            columnCount = $headerCells.length;

            if (!columnCount) {
                $headerCells = $table.find('thead tr:first').children('th, td');
                columnCount = $headerCells.length;
            }
        }

        var $normalizedRow = $row && $row.jquery ? $row : $();

        if (!columnCount && $normalizedRow.length) {
            columnCount = $normalizedRow.children('td, th').length;
        }

        if (!columnCount && $table.length) {
            columnCount = $table.find('tr').first().children('td, th').length;
        }

        if (!columnCount) {
            columnCount = 1;
        }

        return columnCount;
    }

    function handleSuccessfulResponse(response, row, helpers) {
        var $row = row && row.jquery ? row : $(row);
        var prefersReducedMotion = false;

        if (typeof window.matchMedia === 'function') {
            var mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            prefersReducedMotion = !!(mediaQuery && mediaQuery.matches);
        }

        accessibility.speak(getAnnouncementMessage(response), 'polite');

        var nextFocusTarget = findNextFocusTarget($row);

        if (helpers && typeof helpers.close === 'function') {
            helpers.close(nextFocusTarget);
        }

        function finalizeListUpdate($currentRow) {
            var $normalizedRow = $currentRow && $currentRow.jquery ? $currentRow : $();
            var $tbody = $normalizedRow.closest('tbody');

            if ($normalizedRow.length) {
                $normalizedRow.remove();
            }

            if (!$tbody.length) {
                $tbody = $('#the-list');
            }

            var $remainingRows = $tbody.children('tr').filter(function() {
                var $candidate = $(this);
                return !$candidate.hasClass('no-items') && !$candidate.hasClass('inline-edit-row');
            });

            var messageRow = null;

            if (!$remainingRows.length) {
                var messageText = messages.noItemsMessage || '';

                if (messageText) {
                    var colspan = determineColumnCount($tbody, $normalizedRow.length ? $normalizedRow : $tbody.children('tr').first());
                    var $existingNoItems = $tbody.children('tr.no-items');
                    if ($existingNoItems.length) {
                        $existingNoItems.remove();
                    }

                    messageRow = $('<tr>', { class: 'no-items' });
                    $('<td>', { colspan: colspan }).text(messageText).appendTo(messageRow);
                    $tbody.append(messageRow);
                }
            }

            $(document).trigger('blcAdmin:listUpdated', {
                response: response,
                tbody: $tbody,
                table: $tbody.closest('table'),
                messageRow: messageRow
            });
        }

        if ($row && $row.length) {
            if (prefersReducedMotion) {
                finalizeListUpdate($row);
            } else {
                $row.fadeOut(300, function() {
                    finalizeListUpdate($(this));
                });
            }
        } else {
            finalizeListUpdate($());
        }
    }

    window.blcAdmin.listActions = $.extend({}, window.blcAdmin.listActions, {
        handleSuccessfulResponse: handleSuccessfulResponse,
        findNextFocusTarget: findNextFocusTarget
    });

    $('#the-list').on('click', '.blc-suggest-redirect', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var oldUrl = linkElement.data('url');
        var postId = linkElement.data('postid');
        var rowId = linkElement.data('rowId');
        if (typeof rowId === 'undefined') {
            rowId = '';
        }
        var occurrenceIndex = linkElement.data('occurrenceIndex');
        if (typeof occurrenceIndex === 'undefined') {
            occurrenceIndex = '';
        }
        var nonce = linkElement.data('nonce');
        var detectedTarget = linkElement.data('detectedTarget');
        if (typeof detectedTarget !== 'string') {
            detectedTarget = '';
        }
        detectedTarget = detectedTarget.trim();
        var contextExcerpt = linkElement.data('contextExcerpt');
        if (typeof contextExcerpt !== 'string') {
            contextExcerpt = '';
        }
        var contextHtml = linkElement.data('contextHtml');
        if (typeof contextHtml !== 'string') {
            contextHtml = '';
        }

        var defaultValue = detectedTarget || oldUrl || messages.editPromptDefault;
        var promptMessage = (messages.editPromptMessage || '').replace('%s', oldUrl || '');

        modal.open({
            title: messages.suggestRedirectModalTitle || messages.editModalTitle,
            message: promptMessage,
            label: messages.suggestRedirectModalLabel || messages.editModalLabel,
            defaultValue: defaultValue,
            placeholder: messages.editPromptDefault,
            confirmText: messages.suggestRedirectModalConfirm || messages.editModalConfirm,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            context: contextExcerpt,
            contextHtml: contextHtml,
            contextLabel: messages.contextLabel,
            onConfirm: function(inputValue, helpers) {
                processLinkUpdate(linkElement, {
                    helpers: helpers,
                    value: inputValue,
                    oldUrl: oldUrl,
                    postId: postId,
                    rowId: rowId,
                    occurrenceIndex: occurrenceIndex,
                    nonce: nonce
                });
            }
        });
    });

    function getSelectedBulkAction($form) {
        var action = $form.find('select[name="action"]').val();

        if (action && action !== '-1') {
            return action;
        }

        action = $form.find('select[name="action2"]').val();

        if (action && action !== '-1') {
            return action;
        }

        return null;
    }

    function buildBulkModalConfig(action, count) {
        var title = '';
        var confirmText = '';
        var template = '';

        if (action === 'ignore') {
            title = messages.ignoreModalTitle || '';
            confirmText = messages.ignoreModalConfirm || '';
            template = messages.bulkIgnoreModalMessage || '';
        } else if (action === 'restore') {
            title = messages.restoreModalTitle || '';
            confirmText = messages.restoreModalConfirm || '';
            template = messages.bulkRestoreModalMessage || '';
        } else {
            title = messages.unlinkModalTitle || '';
            confirmText = messages.unlinkModalConfirm || '';
            template = messages.bulkUnlinkModalMessage || '';
        }

        if (!template) {
            template = messages.bulkGenericModalMessage || '';
        }

        var message = template ? formatTemplate(template, count) : '';

        return {
            title: title,
            message: message,
            confirmText: confirmText,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            showInput: false
        };
    }

    var BULK_SUPPORTED_ACTIONS = ['ignore', 'restore', 'unlink'];

    $('.blc-links-filter-form').on('submit', function(e) {
        var $form = $(this);

        if ($form.data('blcBulkConfirmed')) {
            $form.removeData('blcBulkConfirmed');
            return;
        }

        var action = getSelectedBulkAction($form);

        if (!action || $.inArray(action, BULK_SUPPORTED_ACTIONS) === -1) {
            return;
        }

        var $modalElement = $('#blc-modal');
        if (!$modalElement.length) {
            return;
        }

        var $selected = $form.find('input[name="link_ids[]"]:checked');

        if (!$selected.length) {
            e.preventDefault();
            var noSelectionMessage = messages.bulkNoSelectionMessage || '';
            if (!noSelectionMessage && messages.genericError) {
                noSelectionMessage = messages.genericError;
            }

            if (noSelectionMessage) {
                accessibility.speak(noSelectionMessage, 'assertive');
            }

            return;
        }

        e.preventDefault();

        var modalConfig = buildBulkModalConfig(action, $selected.length);

        modal.open($.extend({}, modalConfig, {
            onConfirm: function(_value, helpers) {
                helpers.setSubmitting(true);
                $(document).trigger('blcAdmin:bulkActionConfirmed', {
                    action: action,
                    count: $selected.length,
                    form: $form
                });
                $form.data('blcBulkConfirmed', true);
                window.setTimeout(function() {
                    $form.get(0).submit();
                }, 0);
            }
        }));
    });

    function hasWhitespace(value) {
        return /\s/.test(value);
    }

    function processLinkUpdate(linkElement, params) {
        var helpers = params.helpers;
        var oldUrl = params.oldUrl || '';
        var trimmedValue = (params.value || '').trim();

        if (!trimmedValue) {
            helpers.showError(messages.emptyUrlMessage);
            return;
        }

        if (hasWhitespace(trimmedValue)) {
            helpers.showError(messages.invalidUrlMessage);
            return;
        }

        if (trimmedValue === oldUrl) {
            helpers.showError(messages.sameUrlMessage);
            return;
        }

        helpers.setSubmitting(true);

        var row = linkElement.closest('tr');
        row.css('opacity', 0.5);

        $.post(ajaxurl, {
            action: 'blc_edit_link',
            post_id: params.postId,
            row_id: params.rowId,
            occurrence_index: params.occurrenceIndex,
            old_url: oldUrl,
            new_url: trimmedValue,
            _ajax_nonce: params.nonce
        }).done(function(response) {
            if (response && response.success) {
                handleSuccessfulResponse(response, row, helpers);
            } else {
                var errorMessage = response && response.data && response.data.message
                    ? response.data.message
                    : messages.genericError;
                helpers.setSubmitting(false);
                helpers.showError((messages.errorPrefix || '') + errorMessage);
                row.css('opacity', 1);
            }
        }).fail(function() {
            helpers.setSubmitting(false);
            helpers.showError(messages.genericError);
            row.css('opacity', 1);
        });
    }

    /**
     * Gère le clic sur le bouton "Modifier le lien".
     */
    // On utilise la délégation d'événements pour s'assurer que ça fonctionne même avec la pagination AJAX (si on l'ajoute un jour)
    $('#the-list').on('click', '.blc-edit-link', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var oldUrl = linkElement.data('url');
        var postId = linkElement.data('postid');
        var rowId = linkElement.data('rowId');
        if (typeof rowId === 'undefined') {
            rowId = '';
        }
        var occurrenceIndex = linkElement.data('occurrenceIndex');
        if (typeof occurrenceIndex === 'undefined') {
            occurrenceIndex = '';
        }
        var nonce = linkElement.data('nonce');
        var detectedTarget = linkElement.data('detectedTarget');
        if (typeof detectedTarget === 'undefined' || detectedTarget === null) {
            detectedTarget = '';
        }
        detectedTarget = String(detectedTarget).trim();
        var contextExcerpt = linkElement.data('contextExcerpt');
        if (typeof contextExcerpt !== 'string') {
            contextExcerpt = '';
        }
        var contextHtml = linkElement.data('contextHtml');
        if (typeof contextHtml !== 'string') {
            contextHtml = '';
        }

        var modalDefaultValue = detectedTarget || oldUrl || messages.editPromptDefault;

        var promptMessage = (messages.editPromptMessage || '').replace('%s', oldUrl || '');

        modal.open({
            title: messages.editModalTitle,
            message: promptMessage,
            label: messages.editModalLabel,
            defaultValue: modalDefaultValue,
            placeholder: messages.editPromptDefault,
            confirmText: messages.editModalConfirm,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            context: contextExcerpt,
            contextHtml: contextHtml,
            contextLabel: messages.contextLabel,
            onConfirm: function(inputValue, helpers) {
                processLinkUpdate(linkElement, {
                    helpers: helpers,
                    value: inputValue,
                    oldUrl: oldUrl,
                    postId: postId,
                    rowId: rowId,
                    occurrenceIndex: occurrenceIndex,
                    nonce: nonce
                });
            }
        });
    });

    /**
     * Gère le clic sur le bouton "Dissocier".
     */
    $('#the-list').on('click', '.blc-unlink', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var urlToUnlink = linkElement.data('url');
        var postId = linkElement.data('postid');
        var rowId = linkElement.data('rowId');
        if (typeof rowId === 'undefined') {
            rowId = '';
        }
        var occurrenceIndex = linkElement.data('occurrenceIndex');
        if (typeof occurrenceIndex === 'undefined') {
            occurrenceIndex = '';
        }
        var nonce = linkElement.data('nonce');

        var unlinkMessage = messages.unlinkConfirmation || '';
        if (urlToUnlink) {
            unlinkMessage = unlinkMessage ? unlinkMessage + '\n' + urlToUnlink : urlToUnlink;
        }

        modal.open({
            title: messages.unlinkModalTitle,
            message: unlinkMessage,
            showInput: false,
            confirmText: messages.unlinkModalConfirm,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            onConfirm: function(_value, helpers) {
                helpers.setSubmitting(true);

                var row = linkElement.closest('tr');
                row.css('opacity', 0.5);

                $.post(ajaxurl, {
                    action: 'blc_unlink',
                    post_id: postId,
                    row_id: rowId,
                    occurrence_index: occurrenceIndex,
                    url_to_unlink: urlToUnlink,
                    _ajax_nonce: nonce
                }).done(function(response) {
                    if (response && response.success) {
                        handleSuccessfulResponse(response, row, helpers);
                    } else {
                        var errorMessage = response && response.data && response.data.message
                            ? response.data.message
                            : messages.genericError;
                        helpers.setSubmitting(false);
                        helpers.showError((messages.errorPrefix || '') + errorMessage);
                        row.css('opacity', 1);
                    }
                }).fail(function() {
                    helpers.setSubmitting(false);
                    helpers.showError(messages.genericError);
                    row.css('opacity', 1);
                });
            }
        });
    });

    $('#the-list').on('click', '.blc-view-context', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var contextExcerpt = linkElement.data('contextExcerpt');
        if (typeof contextExcerpt !== 'string') {
            contextExcerpt = '';
        }
        var contextHtml = linkElement.data('contextHtml');
        if (typeof contextHtml !== 'string') {
            contextHtml = '';
        }

        if (!contextExcerpt && !contextHtml) {
            var emptyMessage = messages.contextModalEmpty || '';
            if (emptyMessage) {
                accessibility.speak(emptyMessage, 'polite');
            }
            return;
        }

        modal.open({
            title: messages.contextModalTitle || '',
            message: '',
            showInput: false,
            showCancel: false,
            confirmText: messages.closeButton || messages.cancelButton || 'Fermer',
            closeLabel: messages.closeLabel,
            context: contextExcerpt,
            contextHtml: contextHtml,
            contextLabel: messages.contextLabel,
            onConfirm: function(_value, helpers) {
                helpers.close();
            }
        });
    });

    $('#the-list').on('click', '.blc-ignore', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var urlValue = linkElement.data('url');
        var postId = linkElement.data('postid');
        var rowId = linkElement.data('rowId');
        if (typeof rowId === 'undefined') {
            rowId = '';
        }
        var occurrenceIndex = linkElement.data('occurrenceIndex');
        if (typeof occurrenceIndex === 'undefined') {
            occurrenceIndex = '';
        }
        var nonce = linkElement.data('nonce');
        var mode = linkElement.data('ignoreMode');
        if (typeof mode === 'undefined' || mode === null) {
            mode = 'ignore';
        } else {
            mode = String(mode).toLowerCase();
        }

        var isRestore = (mode === 'restore' || mode === 'unignore');
        if (!isRestore && mode !== 'ignore') {
            mode = 'ignore';
        }

        var title = isRestore ? messages.restoreModalTitle : messages.ignoreModalTitle;
        var messageTemplate = isRestore ? messages.restoreModalMessage : messages.ignoreModalMessage;
        var confirmText = isRestore ? messages.restoreModalConfirm : messages.ignoreModalConfirm;
        var announcementFallback = isRestore ? messages.restoredAnnouncement : messages.ignoredAnnouncement;

        var modalMessage = formatTemplate(messageTemplate || '', urlValue || '');

        modal.open({
            title: title,
            message: modalMessage,
            showInput: false,
            confirmText: confirmText,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            onConfirm: function(_value, helpers) {
                helpers.setSubmitting(true);

                var row = linkElement.closest('tr');
                row.css('opacity', 0.5);

                $.post(ajaxurl, {
                    action: 'blc_ignore_link',
                    post_id: postId,
                    row_id: rowId,
                    occurrence_index: occurrenceIndex,
                    mode: mode,
                    _ajax_nonce: nonce
                }).done(function(response) {
                    if (response && response.success) {
                        if (!response.data) {
                            response.data = {};
                        }
                        if (!response.data.announcement && announcementFallback) {
                            response.data.announcement = announcementFallback;
                        }
                        handleSuccessfulResponse(response, row, helpers);
                    } else {
                        var errorMessage = response && response.data && response.data.message
                            ? response.data.message
                            : messages.genericError;
                        helpers.setSubmitting(false);
                        helpers.showError((messages.errorPrefix || '') + errorMessage);
                        row.css('opacity', 1);
                    }
                }).fail(function() {
                    helpers.setSubmitting(false);
                    helpers.showError(messages.genericError);
                    row.css('opacity', 1);
                });
            }
        });
    });

    function initManualScanPanel(config) {
        if (!config) {
            return;
        }

        var selectors = config.selectors || {};
        var $panel = selectors.panel ? $(selectors.panel) : $();
        if (!$panel.length) {
            return;
        }

        var $form = selectors.form ? $(selectors.form) : $();
        var $submit = $form.length ? $form.find('input[type="submit"]') : $();
        var fullScanSelector = selectors.fullScan || '';
        var $fullScan = fullScanSelector ? $form.find(fullScanSelector) : $();
        var supportsFullScan = $fullScan.length > 0;

        var $state = $panel.find('.blc-scan-status__state');
        var $details = $panel.find('.blc-scan-status__details');
        var $progress = $panel.find('.blc-scan-status__progress');
        var $progressFill = $panel.find('.blc-scan-status__progress-fill');
        var $message = $panel.find('.blc-scan-status__message');
        var $cancel = selectors.cancel ? $(selectors.cancel) : $();
        var $restart = selectors.restart ? $(selectors.restart) : $();

        var pollInterval = parseInt(config.pollInterval, 10);
        if (isNaN(pollInterval) || pollInterval < 2000) {
            pollInterval = 5000;
        }

        var defaults = {
            state: 'idle',
            current_batch: 0,
            processed_batches: 0,
            total_batches: 0,
            remaining_batches: 0,
            total_items: 0,
            processed_items: 0,
            is_full_scan: supportsFullScan ? false : true,
            message: '',
            last_error: ''
        };

        var currentStatus = $.extend(true, {}, defaults, config.status || {});
        var lastState = null;
        var lastMessage = '';
        var pollTimer = null;
        var isFetching = false;
        var lastRequestedFullScan = supportsFullScan ? !!currentStatus.is_full_scan : true;

        function canUseRest() {
            return typeof config.restUrl === 'string' && config.restUrl.length > 0;
        }

        function canUseAjaxStatus() {
            return !!config.getStatusNonce && window.wp && wp.ajax && typeof wp.ajax.post === 'function';
        }

        function safeInt(value) {
            var intVal = parseInt(value, 10);
            return isNaN(intVal) ? 0 : intVal;
        }

        function getStateLabel(state) {
            var key = typeof state === 'string' ? state : '';
            key = key ? key : 'idle';
            if (config.i18n && config.i18n.states && config.i18n.states[key]) {
                return config.i18n.states[key];
            }
            if (config.i18n && config.i18n.states && config.i18n.states.idle) {
                return config.i18n.states.idle;
            }
            return key;
        }

        function computeProgress(status) {
            var total = safeInt(status.total_batches);
            var processed = safeInt(status.processed_batches);
            if (total > 0) {
                var percent = Math.round((processed / total) * 100);
                if (status.state === 'completed') {
                    percent = 100;
                }
                return Math.max(0, Math.min(100, percent));
            }

            if (status.state === 'completed') {
                return 100;
            }

            if (status.state === 'running' || status.state === 'queued') {
                return 10;
            }

            return 0;
        }

        function formatDetails(status) {
            var details = [];
            var state = status.state || 'idle';
            var total = safeInt(status.total_batches);
            var processed = safeInt(status.processed_batches);

            if ((state === 'running' || state === 'queued') && total > 0) {
                if (config.i18n && config.i18n.batchSummary) {
                    details.push(
                        config.i18n.batchSummary
                            .replace('%1$d', Math.max(processed, 1))
                            .replace('%2$d', Math.max(total, 1))
                    );
                } else {
                    details.push(processed + ' / ' + total);
                }
            }

            var remaining = safeInt(status.remaining_batches);
            if (remaining > 0 && config.i18n && config.i18n.remainingBatches) {
                details.push(config.i18n.remainingBatches.replace('%d', remaining));
            }

            var nextTimestamp = safeInt(status.next_batch_timestamp);
            if (nextTimestamp > 0 && config.i18n && config.i18n.nextBatch) {
                var nextDate = new Date(nextTimestamp * 1000);
                details.push(config.i18n.nextBatch.replace('%s', nextDate.toLocaleString()));
            }

            if (details.length === 0) {
                if (state === 'queued' && config.i18n && config.i18n.queueMessage) {
                    details.push(config.i18n.queueMessage);
                } else if (status.message) {
                    details.push(String(status.message));
                }
            }

            return details.join(' ');
        }

        function formatMessage(status) {
            if (status && typeof status.message === 'string') {
                return status.message;
            }
            return '';
        }

        function updateButtonsState(status) {
            var state = (status && status.state) ? String(status.state) : 'idle';
            var isActive = (state === 'running' || state === 'queued');
            if ($cancel.length) {
                $cancel.prop('disabled', !isActive);
            }
            if ($restart.length) {
                $restart.prop('disabled', state === 'running');
            }
        }

        function updatePanel(status) {
            if (!status || typeof status !== 'object') {
                return;
            }

            currentStatus = $.extend(true, {}, currentStatus, status);
            var state = currentStatus.state || 'idle';
            var progress = computeProgress(currentStatus);
            var details = formatDetails(currentStatus);
            var messageText = formatMessage(currentStatus);

            $panel.attr('data-scan-state', state);
            $panel.attr('data-is-full-scan', currentStatus.is_full_scan ? '1' : '0');

            if (lastState !== state) {
                $panel.removeClass(function(index, className) {
                    return (className.match(/blc-scan-status--state-[^\s]+/g) || []).join(' ');
                });
                $panel.addClass('blc-scan-status--state-' + state);
            }

            $panel.toggleClass('is-completed', state === 'completed');
            $panel.toggleClass('is-failed', state === 'failed');
            $panel.toggleClass('is-cancelled', state === 'cancelled');
            $panel.toggleClass('is-active', state === 'running' || state === 'queued');

            if ($state.length) {
                $state.text(getStateLabel(state));
            }

            if ($details.length) {
                $details.text(details);
            }

            if ($progress.length && $progressFill.length) {
                $progress.attr('aria-valuenow', progress);
                $progressFill.css('width', progress + '%');
            }

            if ($message.length) {
                $message.text(messageText);
            }

            if (messageText && messageText !== lastMessage && lastMessage !== '') {
                accessibility.speak(messageText, 'polite');
            }

            lastState = state;
            lastMessage = messageText;
            lastRequestedFullScan = supportsFullScan ? !!currentStatus.is_full_scan : true;
            updateButtonsState(currentStatus);
        }

        function fetchStatus() {
            if (isFetching) {
                return;
            }

            if (canUseRest()) {
                isFetching = true;
                window.fetch(config.restUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': config.restNonce
                    }
                }).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                }).then(function(data) {
                    if (data) {
                        updatePanel(data);
                    }
                }).catch(function() {
                    if (canUseAjaxStatus()) {
                        var statusAction = (config.ajax && config.ajax.status) ? config.ajax.status : 'blc_get_scan_status';
                        wp.ajax.post(statusAction, {
                            _ajax_nonce: config.getStatusNonce
                        }).done(function(response) {
                            if (response && response.status) {
                                updatePanel(response.status);
                            }
                        });
                    }
                }).finally(function() {
                    isFetching = false;
                    refreshPolling(false);
                });
                return;
            }

            if (!canUseAjaxStatus()) {
                return;
            }

            isFetching = true;
            var statusAction = (config.ajax && config.ajax.status) ? config.ajax.status : 'blc_get_scan_status';
            wp.ajax.post(statusAction, {
                _ajax_nonce: config.getStatusNonce
            }).done(function(response) {
                if (response && response.status) {
                    updatePanel(response.status);
                }
            }).fail(function(error) {
                if (error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.status) {
                    updatePanel(error.responseJSON.data.status);
                }
            }).always(function() {
                isFetching = false;
                refreshPolling(false);
            });
        }

        function schedulePoll(immediate) {
            if (pollTimer) {
                window.clearTimeout(pollTimer);
            }

            var delay = immediate ? 0 : pollInterval;
            pollTimer = window.setTimeout(fetchStatus, delay);
        }

        function refreshPolling(immediate) {
            schedulePoll(immediate);
        }

        function setFormBusy(state) {
            if (!$submit.length) {
                return;
            }

            $submit.prop('disabled', state);
            if (state) {
                $submit.attr('aria-busy', 'true');
            } else {
                $submit.removeAttr('aria-busy');
            }
        }

        function showConfirmation(options) {
            if (!options || typeof options.onConfirm !== 'function') {
                return;
            }

            var title = options.title || '';
            var message = options.message || '';
            var confirmText = options.confirmText || '';
            var cancelText = options.cancelText || messages.cancelButton || 'Annuler';
            var closeLabel = options.closeLabel || messages.closeLabel || 'Fermer';

            if (!modal || typeof modal.open !== 'function') {
                if (!message || window.confirm(message)) {
                    options.onConfirm({
                        setSubmitting: function() {},
                        close: function() {}
                    });
                }
                return;
            }

            modal.open({
                showInput: false,
                showCancel: true,
                title: title,
                message: message,
                confirmText: confirmText || messages.editModalConfirm || 'Confirmer',
                cancelText: cancelText,
                closeLabel: closeLabel,
                onConfirm: function(value, helpers) {
                    options.onConfirm(helpers || {
                        setSubmitting: function() {},
                        close: function() {}
                    });
                }
            });
        }

        function startScan(isFullScan) {
            if (!config.startScanNonce || !window.wp || !wp.ajax || typeof wp.ajax.post !== 'function') {
                return;
            }

            setFormBusy(true);

            var startAction = (config.ajax && config.ajax.start) ? config.ajax.start : 'blc_start_manual_scan';
            var requestData = {
                _ajax_nonce: config.startScanNonce
            };

            if (supportsFullScan) {
                requestData.full_scan = isFullScan ? 1 : 0;
            }

            wp.ajax.post(startAction, requestData).done(function(response) {
                lastRequestedFullScan = supportsFullScan ? !!isFullScan : true;

                if (response && response.status) {
                    updatePanel(response.status);
                }

                if (response && response.message) {
                    accessibility.speak(response.message, 'polite');
                }

                if (response && response.warning) {
                    accessibility.speak(response.warning, 'assertive');
                }

                refreshPolling(false);
            }).fail(function(error) {
                var message = config.i18n && config.i18n.startError ? config.i18n.startError : (messages.genericError || '');
                if (error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
                    message = error.responseJSON.data.message;
                }

                $message.text(message);
                if (message) {
                    accessibility.speak(message, 'assertive');
                }
            }).always(function() {
                setFormBusy(false);
                refreshPolling(false);
            });
        }

        function cancelScan() {
            if (!config.cancelScanNonce || !window.wp || !wp.ajax || typeof wp.ajax.post !== 'function') {
                return;
            }

            var cancelAction = (config.ajax && config.ajax.cancel) ? config.ajax.cancel : 'blc_cancel_manual_scan';
            var confirmMessage = config.i18n && config.i18n.cancelConfirm;
            var confirmLabel = config.i18n && config.i18n.cancelConfirmLabel;
            var confirmTitle = config.i18n && config.i18n.cancelTitle;

            showConfirmation({
                title: confirmTitle || '',
                message: confirmMessage || '',
                confirmText: confirmLabel || messages.cancelButton || 'Annuler',
                onConfirm: function(helpers) {
                    $cancel.prop('disabled', true).attr('aria-busy', 'true');
                    helpers.setSubmitting(true);

                    wp.ajax.post(cancelAction, {
                        _ajax_nonce: config.cancelScanNonce
                    }).done(function(response) {
                        if (response && response.status) {
                            updatePanel(response.status);
                        }

                        var message = (response && response.message) || (config.i18n && config.i18n.cancelSuccess) || '';
                        if (message) {
                            $message.text(message);
                            accessibility.speak(message, 'polite');
                        }

                        refreshPolling(false);
                    }).fail(function(error) {
                        var message = (config.i18n && config.i18n.cancelError) || messages.genericError || '';
                        if (error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
                            message = error.responseJSON.data.message;
                        }

                        $message.text(message);
                        if (message) {
                            accessibility.speak(message, 'assertive');
                        }
                    }).always(function() {
                        helpers.setSubmitting(false);
                        helpers.close();
                        $cancel.prop('disabled', false).removeAttr('aria-busy');
                        refreshPolling(false);
                    });
                }
            });
        }

        function restartScan() {
            var confirmMessage = config.i18n && config.i18n.restartConfirm;
            var confirmLabel = config.i18n && config.i18n.restartConfirmLabel;
            var confirmTitle = config.i18n && config.i18n.restartTitle;

            showConfirmation({
                title: confirmTitle || '',
                message: confirmMessage || '',
                confirmText: confirmLabel || messages.editModalConfirm || 'Confirmer',
                onConfirm: function(helpers) {
                    helpers.close();
                    startScan(lastRequestedFullScan);
                }
            });
        }

        if ($form.length) {
            $form.on('submit', function(event) {
                if (!window.wp || !wp.ajax || typeof wp.ajax.post !== 'function' || !config.startScanNonce) {
                    return;
                }

                event.preventDefault();
                var isFullScan = supportsFullScan ? $fullScan.is(':checked') : true;
                startScan(isFullScan);
            });
        }

        if ($cancel.length) {
            $cancel.on('click', function(event) {
                event.preventDefault();
                cancelScan();
            });
        }

        if ($restart.length) {
            $restart.on('click', function(event) {
                event.preventDefault();
                restartScan();
            });
        }

        lastState = currentStatus.state || 'idle';
        lastMessage = typeof currentStatus.message === 'string' ? currentStatus.message : '';
        updatePanel(currentStatus);
        refreshPolling(false);
    }

    if (window.blcAdminScanConfig) {
        initManualScanPanel(window.blcAdminScanConfig);
    }

    if (window.blcAdminImageScanConfig) {
        initManualScanPanel(window.blcAdminImageScanConfig);
    }

    $('#the-list').on('click', '.blc-recheck', function(e) {
        e.preventDefault();

        var button = $(this);
        var postId = button.data('postid');
        var rowId = button.data('rowId');
        if (typeof rowId === 'undefined') {
            rowId = '';
        }
        var occurrenceIndex = button.data('occurrenceIndex');
        if (typeof occurrenceIndex === 'undefined') {
            occurrenceIndex = '';
        }
        var nonce = button.data('nonce');

        if (!nonce) {
            return;
        }

        var row = button.closest('tr');
        var inProgressMessage = messages.recheckInProgress || '';
        if (inProgressMessage) {
            accessibility.speak(inProgressMessage, 'polite');
        }

        button.prop('disabled', true).attr('aria-busy', 'true');
        row.css('opacity', 0.5);

        var requestData = {
            action: 'blc_recheck_link',
            post_id: postId,
            row_id: rowId,
            _ajax_nonce: nonce
        };

        if (occurrenceIndex !== '') {
            requestData.occurrence_index = occurrenceIndex;
        }

        $.post(ajaxurl, requestData).done(function(response) {
            button.prop('disabled', false).removeAttr('aria-busy');
            row.css('opacity', 1);

            if (response && response.success) {
                var successMessage = (response.data && response.data.message) || messages.recheckSuccess || '';
                if (successMessage) {
                    accessibility.speak(successMessage, 'polite');
                }
                window.setTimeout(function() {
                    window.location.reload();
                }, 300);
            } else {
                var errorMessage = (response && response.data && response.data.message) || messages.recheckError || messages.genericError;
                if (errorMessage) {
                    accessibility.speak(errorMessage, 'assertive');
                    window.alert(errorMessage);
                }
            }
        }).fail(function() {
            button.prop('disabled', false).removeAttr('aria-busy');
            row.css('opacity', 1);

            var errorMessage = messages.recheckError || messages.genericError;
            if (errorMessage) {
                accessibility.speak(errorMessage, 'assertive');
                window.alert(errorMessage);
            }
        });
    });

(function setupDashboardFilterPersistence() {
    var STORAGE_KEY = 'blcDashboardLinkType';
    var $dashboard = $('.blc-dashboard-links-page');

    if (!$dashboard.length) {
        return;
    }

    var storageAvailable = false;
    try {
        var testKey = STORAGE_KEY + '_test';
        window.localStorage.setItem(testKey, '1');
        window.localStorage.removeItem(testKey);
        storageAvailable = true;
    } catch (error) {
        storageAvailable = false;
    }

    if (!storageAvailable) {
        return;
    }

    var params;
    try {
        params = new URLSearchParams(window.location.search);
    } catch (error) {
        return;
    }

    if (params.get('page') !== 'blc-dashboard') {
        return;
    }

    var storedType = window.localStorage.getItem(STORAGE_KEY) || '';
    var hasLinkType = params.has('link_type') && params.get('link_type') !== null;

    if (!hasLinkType) {
        if (storedType && storedType !== 'all') {
            params.set('link_type', storedType);
            var redirectUrl = window.location.pathname + '?' + params.toString();
            if (window.location.hash) {
                redirectUrl += window.location.hash;
            }
            window.location.replace(redirectUrl);
            return;
        }
        storedType = 'all';
    } else {
        storedType = params.get('link_type') || '';
        if (!storedType) {
            storedType = 'all';
        }
    }

    window.localStorage.setItem(STORAGE_KEY, storedType);

    $dashboard.on('click', '.blc-stats-box a[data-link-type]', function() {
        var type = $(this).data('link-type');
        if (typeof type === 'undefined' || type === null || type === '') {
            type = 'all';
        }
        window.localStorage.setItem(STORAGE_KEY, String(type));
    });

    $(document).on('click', 'a[href*="page=blc-dashboard"]', function() {
        var href = $(this).attr('href');
        if (!href) {
            return;
        }

        var linkType = null;
        try {
            var url = new URL(href, window.location.origin);
            if (url.searchParams.get('page') !== 'blc-dashboard') {
                return;
            }
            if (url.searchParams.has('link_type')) {
                linkType = url.searchParams.get('link_type') || '';
            } else {
                linkType = 'all';
            }
        } catch (error) {
            return;
        }

        if (linkType === null) {
            return;
        }

        if (!linkType) {
            linkType = 'all';
        }

        window.localStorage.setItem(STORAGE_KEY, linkType);
    });
})();

});
