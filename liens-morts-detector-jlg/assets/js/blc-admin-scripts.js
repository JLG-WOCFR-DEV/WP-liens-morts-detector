jQuery(document).ready(function($) {
    var ACTION_FOCUS_SELECTOR = '.blc-edit-link, .blc-unlink, .blc-ignore';

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
        bulkSuccessAnnouncement: 'Les actions groupées ont été appliquées avec succès.'
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
            isSubmitting: false
        };

        function clearError() {
            $error.removeClass('is-visible').text('');
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

            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('blc-modal-open');

            setSubmitting(false);
            clearError();

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

            $cancel.text(options.cancelText || messages.cancelButton || 'Annuler');
            $close.attr('aria-label', options.closeLabel || messages.closeLabel || 'Fermer');

            clearError();
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

        accessibility.speak(getAnnouncementMessage(response), 'polite');

        var nextFocusTarget = findNextFocusTarget($row);

        if (helpers && typeof helpers.close === 'function') {
            helpers.close(nextFocusTarget);
        }

        if ($row && $row.length) {
            $row.fadeOut(300, function() {
                var $currentRow = $(this);
                var $tbody = $currentRow.closest('tbody');
                $currentRow.remove();

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
                        var colspan = determineColumnCount($tbody, $currentRow);
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
            });
        } else {
            $(document).trigger('blcAdmin:listUpdated', {
                response: response,
                tbody: $('#the-list'),
                table: $('#the-list').closest('table'),
                messageRow: null
            });
        }
    }

    window.blcAdmin.listActions = $.extend({}, window.blcAdmin.listActions, {
        handleSuccessfulResponse: handleSuccessfulResponse,
        findNextFocusTarget: findNextFocusTarget
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

        var promptMessage = (messages.editPromptMessage || '').replace('%s', oldUrl || '');

        modal.open({
            title: messages.editModalTitle,
            message: promptMessage,
            label: messages.editModalLabel,
            defaultValue: oldUrl || messages.editPromptDefault,
            placeholder: messages.editPromptDefault,
            confirmText: messages.editModalConfirm,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            onConfirm: function(inputValue, helpers) {
                var trimmedValue = (inputValue || '').trim();

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
                    post_id: postId,
                    row_id: rowId,
                    occurrence_index: occurrenceIndex,
                    old_url: oldUrl,
                    new_url: trimmedValue,
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
});
