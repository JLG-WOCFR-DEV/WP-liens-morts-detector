jQuery(document).ready(function($) {
    var ACTION_FOCUS_SELECTOR = '.blc-edit-link, .blc-unlink, .blc-ignore, .blc-suggest-redirect, .blc-apply-redirect, .blc-view-context, .blc-recheck';

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
        simpleConfirmModalConfirm: 'Confirmer',
        simpleConfirmModalCancel: 'Annuler',
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
        recheckError: 'Impossible de re-vérifier le lien. Veuillez réessayer.',
        applyRedirectConfirmation: 'Appliquer la redirection détectée vers %s ?',
        applyRedirectSuccess: 'La redirection détectée a été appliquée.',
        applyRedirectError: 'Impossible d\'appliquer la redirection détectée.',
        applyRedirectMissingTarget: 'Aucune redirection détectée n\'est disponible pour ce lien.',
        applyRedirectModalTitle: 'Appliquer la redirection détectée',
        applyRedirectModalConfirm: 'Appliquer',
        applyRedirectModalMessage: 'Appliquer la redirection détectée vers %s ?',
        applyRedirectMissingModalTitle: 'Redirection indisponible',
        applyRedirectMissingModalMessage: 'Aucune redirection détectée n\'est disponible pour ce lien.',
        bulkApplyRedirectModalMessage: 'Voulez-vous appliquer la redirection détectée aux %s liens sélectionnés ?',
        applyGloballyLabel: 'Appliquer partout',
        applyGloballyHelp: 'Mettre à jour toutes les occurrences connues de cette URL.',
        massUpdatePreviewTitle: 'Contenus concernés',
        massUpdatePreviewEmpty: 'Seul cet élément sera mis à jour.',
        massUpdatePreviewInactive: 'Activez «\u00a0Appliquer partout\u00a0» pour voir les contenus concernés.',
        massUpdatePreviewNeedsUrl: 'Saisissez une nouvelle URL valide pour afficher les contenus concernés.',
        massUpdatePreviewLoading: 'Chargement de l\'aperçu…',
        massUpdatePreviewError: 'Impossible de récupérer l\'aperçu.',
        massUpdatePreviewRestricted: 'Certains contenus ne pourront pas être mis à jour faute de droits.',
        massUpdatePreviewRestrictedSingle: 'Non modifiable (droits insuffisants)',
        massUpdatePreviewCurrent: 'Élément actuel',
        massUpdateUntitled: 'Contenu sans titre',
        massUpdateSummarySuccess: '%1$s contenu(s) mis à jour.',
        massUpdateSummaryPartial: '%1$s contenu(s) mis à jour, %2$s échec(s).',
        massUpdateFailureListTitle: 'Échecs lors de la mise à jour\u00a0:',
        massUpdateFailureItem: '%1$s (ID %2$s)'
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

    function debounce(fn, delay) {
        var timeoutId = null;

        return function() {
            var context = this;
            var args = arguments;

            if (timeoutId) {
                window.clearTimeout(timeoutId);
            }

            timeoutId = window.setTimeout(function() {
                timeoutId = null;
                fn.apply(context, args);
            }, delay || 0);
        };
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

    var toast = (function() {
        var $container = null;
        var TOAST_DURATION = 7000;

        function ensureContainer() {
            if ($container && $container.length && document.body.contains($container[0])) {
                return $container;
            }

            $container = $('<div>', {
                class: 'blc-toast-container',
                'aria-live': 'polite',
                'aria-atomic': 'false'
            });

            $('body').append($container);

            return $container;
        }

        function closeToast($toast) {
            if (!$toast || !$toast.length) {
                return;
            }

            $toast.addClass('is-leaving');
            window.setTimeout(function() {
                $toast.remove();
            }, 200);
        }

        function show(message, variant) {
            if (!message) {
                return;
            }

            var type = variant || 'info';
            var $wrapper = ensureContainer();

            var $toast = $('<div>', {
                class: 'blc-toast blc-toast--' + type,
                role: type === 'error' ? 'alert' : 'status'
            });

            $('<span>', {
                class: 'blc-toast__message',
                text: message
            }).appendTo($toast);

            $('<button>', {
                type: 'button',
                class: 'blc-toast__close',
                'aria-label': messages.closeLabel || messages.closeButton || 'Fermer'
            }).append($('<span>', { 'aria-hidden': 'true', text: '×' })).on('click', function() {
                closeToast($toast);
            }).appendTo($toast);

            $wrapper.append($toast);

            accessibility.speak(message, type === 'error' ? 'assertive' : 'polite');

            window.setTimeout(function() {
                closeToast($toast);
            }, TOAST_DURATION);
        }

        return {
            show: show,
            success: function(message) {
                show(message, 'success');
            },
            error: function(message) {
                show(message, 'error');
            },
            warning: function(message) {
                show(message, 'warning');
            },
            info: function(message) {
                show(message, 'info');
            }
        };
    })();

    window.blcAdmin.toast = toast;

    var soft404Module = (function() {
        function normalizeList(value) {
            if (Array.isArray(value)) {
                return value
                    .map(function(item) { return typeof item === 'string' ? item : String(item); })
                    .filter(function(item) { return item.trim() !== ''; });
            }

            if (typeof value === 'string') {
                return value
                    .split(/\r?\n/)
                    .map(function(item) { return item.trim(); })
                    .filter(function(item) { return item !== ''; });
            }

            return [];
        }

        function decodeEntities(text) {
            if (typeof text !== 'string' || !text) {
                return '';
            }

            var textarea = document.createElement('textarea');
            textarea.innerHTML = text;
            return textarea.value;
        }

        function stripHtml(html) {
            if (typeof html !== 'string' || !html) {
                return '';
            }

            var withoutScripts = html.replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ');
            var withoutTags = withoutScripts.replace(/<[^>]+>/g, ' ');
            var decoded = decodeEntities(withoutTags);

            return decoded.replace(/\s+/g, ' ').trim();
        }

        function extractTitle(html) {
            if (typeof html !== 'string' || !html) {
                return '';
            }

            var match = html.match(/<title\b[^>]*>([\s\S]*?)<\/title>/i);
            if (!match || !match[1]) {
                return '';
            }

            return decodeEntities(match[1]).replace(/\s+/g, ' ').trim();
        }

        function matchesPattern(pattern, candidate) {
            if (typeof pattern !== 'string' || pattern === '' || typeof candidate !== 'string' || candidate === '') {
                return false;
            }

            if (pattern.charAt(0) === '/') {
                var lastSlash = pattern.lastIndexOf('/');
                if (lastSlash > 0) {
                    var body = pattern.slice(1, lastSlash);
                    var flags = pattern.slice(lastSlash + 1) || 'i';
                    try {
                        var regex = new RegExp(body, flags);
                        return regex.test(candidate);
                    } catch (error) {
                        return false;
                    }
                }
            }

            return candidate.toLowerCase().indexOf(pattern.toLowerCase()) !== -1;
        }

        function matchesAny(patterns, candidates) {
            if (!Array.isArray(patterns) || !patterns.length) {
                return false;
            }

            for (var i = 0; i < patterns.length; i += 1) {
                var pattern = patterns[i];
                if (typeof pattern !== 'string' || !pattern) {
                    continue;
                }

                for (var j = 0; j < candidates.length; j += 1) {
                    var candidate = candidates[j];
                    if (typeof candidate !== 'string' || !candidate) {
                        continue;
                    }

                    if (matchesPattern(pattern, candidate)) {
                        return true;
                    }
                }
            }

            return false;
        }

        function computeLength(text) {
            if (typeof text !== 'string') {
                return 0;
            }

            return text.length;
        }

        var rawConfig = window.blcAdminSoft404Config || {};
        var normalizedConfig = {
            minLength: Number.isFinite(parseInt(rawConfig.minLength, 10)) ? parseInt(rawConfig.minLength, 10) : 0,
            titleWeight: Number.isFinite(parseFloat(rawConfig.titleWeight)) ? parseFloat(rawConfig.titleWeight) : 0,
            titleIndicators: normalizeList(rawConfig.titleIndicators),
            bodyIndicators: normalizeList(rawConfig.bodyIndicators),
            ignorePatterns: normalizeList(rawConfig.ignorePatterns),
            labels: {
                length: rawConfig.labels && rawConfig.labels.length ? rawConfig.labels.length : 'Contenu trop court',
                title: rawConfig.labels && rawConfig.labels.title ? rawConfig.labels.title : 'Titre suspect',
                body: rawConfig.labels && rawConfig.labels.body ? rawConfig.labels.body : 'Message d’erreur détecté',
                titleWeight: rawConfig.labels && rawConfig.labels.titleWeight ? rawConfig.labels.titleWeight : 'Pondération du titre'
            }
        };

        if (!Number.isFinite(normalizedConfig.minLength) || normalizedConfig.minLength < 0) {
            normalizedConfig.minLength = 0;
        }

        if (!Number.isFinite(normalizedConfig.titleWeight) || normalizedConfig.titleWeight < 0) {
            normalizedConfig.titleWeight = 0;
        }

        function detectSoft404(response) {
            var payload = response;
            if (typeof payload === 'string') {
                payload = { body: payload };
            } else if (!payload || typeof payload !== 'object') {
                payload = {};
            }

            var body = typeof payload.body === 'string' ? payload.body : '';
            var explicitTitle = typeof payload.title === 'string' ? payload.title : '';
            var title = explicitTitle || extractTitle(body);
            var bodyText = stripHtml(body);
            var reasons = [];
            var ignored = matchesAny(normalizedConfig.ignorePatterns, [body, bodyText, title]);

            if (!ignored) {
                if (normalizedConfig.minLength > 0) {
                    var length = computeLength(bodyText);
                    if (length < normalizedConfig.minLength) {
                        reasons.push('length');
                    }
                }

                if (title && matchesAny(normalizedConfig.titleIndicators, [title])) {
                    reasons.push('title');
                }

                if (matchesAny(normalizedConfig.bodyIndicators, [body, bodyText])) {
                    reasons.push('body');
                }
            }

            var uniqueReasons = Array.from(new Set(reasons));

            return {
                isSoft404: uniqueReasons.length > 0,
                reasons: uniqueReasons,
                ignored: ignored,
                title: title,
                bodyText: bodyText,
                minLength: normalizedConfig.minLength,
                titleWeight: normalizedConfig.titleWeight,
                labels: normalizedConfig.labels
            };
        }

        return {
            detect: detectSoft404,
            getConfig: function() {
                return normalizedConfig;
            }
        };
    })();

    window.blcAdmin.soft404 = soft404Module;
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

    function ensureInlineNoticeContainer() {
        var $existing = $('.blc-inline-notices').first();
        if ($existing.length) {
            return $existing;
        }

        var $container = $('<div>', { class: 'blc-inline-notices' });
        var $wrap = $('.wrap').first();

        if ($wrap.length) {
            $wrap.prepend($container);
        } else {
            $('body').prepend($container);
        }

        return $container;
    }

    function displayMassUpdateSummary(summary) {
        if (!summary || !summary.applyGlobally) {
            return;
        }

        var updatedCount = parseInt(summary.updatedCount, 10);
        if (Number.isNaN(updatedCount)) {
            updatedCount = 0;
        }

        var failureCount = parseInt(summary.failureCount, 10);
        if (Number.isNaN(failureCount)) {
            failureCount = 0;
        }

        if (Array.isArray(summary.failures) && summary.failures.length && failureCount === 0) {
            failureCount = summary.failures.length;
        }

        var messageTemplate = failureCount > 0
            ? messages.massUpdateSummaryPartial
            : messages.massUpdateSummarySuccess;

        var message = '';
        if (typeof messageTemplate === 'string' && messageTemplate) {
            message = messageTemplate.replace('%1$s', updatedCount).replace('%2$s', failureCount);
        }

        var type = failureCount > 0 ? 'warning' : 'success';
        var $notice = createNoticeElement(type, message);

        if (failureCount > 0 && Array.isArray(summary.failures) && summary.failures.length) {
            var listTitle = messages.massUpdateFailureListTitle || '';
            if (listTitle) {
                $('<p>').text(listTitle).appendTo($notice);
            }

            var $list = $('<ul>');
            summary.failures.forEach(function(item) {
                if (!item) {
                    return;
                }

                var rawPostId = typeof item.postId !== 'undefined' ? item.postId : item.post_id;
                var postIdString = '';
                if (typeof rawPostId !== 'undefined' && rawPostId !== null && rawPostId !== '') {
                    postIdString = String(rawPostId);
                }

                var normalizedPostId = parseInt(postIdString, 10);
                if (postIdString === '' && !Number.isNaN(normalizedPostId)) {
                    postIdString = String(normalizedPostId);
                }

                var postTitle = item.postTitle || item.post_title || '';
                if (!postTitle) {
                    postTitle = messages.massUpdateUntitled || '';
                }

                var reason = item.reason || '';
                var itemTemplate = messages.massUpdateFailureItem || '%1$s (ID %2$s)';
                var label = itemTemplate.replace('%1$s', postTitle);
                label = label.replace('%2$s', postIdString || '?');
                label = $.trim(label);

                var $entry = $('<li>').text(label);
                if (reason) {
                    $('<div>').text(reason).appendTo($entry);
                }

                $list.append($entry);
            });

            $notice.append($list);
        }

        var $container = ensureInlineNoticeContainer();
        $container.append($notice);

        if (message) {
            var politeness = failureCount > 0 ? 'assertive' : 'polite';
            accessibility.speak(message, politeness);
        }
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
        var $statusFilterInputs = $('input[name="blc_notification_status_filters[]"]');
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

            var statusFilters = [];
            if ($statusFilterInputs.length) {
                $statusFilterInputs.each(function() {
                    var $input = $(this);
                    if ($input.is(':checked')) {
                        statusFilters.push(String($input.val()));
                    }
                });
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
                message_template: $messageTemplate.length ? $messageTemplate.val() : '',
                status_filters: statusFilters
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
        var backgroundElements = [];

        if (!$modal.length) {
            var fallbackHelpers = {
                showError: function() {},
                clearError: function() {},
                setSubmitting: function() {},
                close: function() {}
            };

            var resolvedPayload = { value: '', helpers: fallbackHelpers };

            return {
                open: function() {
                    return Promise.resolve(resolvedPayload);
                },
                close: function() {},
                confirm: function() {
                    return Promise.resolve(resolvedPayload);
                },
                helpers: fallbackHelpers
            };
        }

        function toggleBackgroundInert(activate) {
            if (!document.body) {
                return;
            }

            if (activate) {
                backgroundElements = [];

                if (!$modal.parent().is('body')) {
                    $modal.appendTo('body');
                }

                $('body > *').each(function() {
                    if (this === $modal[0]) {
                        return;
                    }

                    var $element = $(this);

                    backgroundElements.push({
                        element: this,
                        ariaHidden: $element.attr('aria-hidden'),
                        inert: $element.attr('inert')
                    });

                    $element.attr('aria-hidden', 'true');
                    $element.attr('inert', '');
                });

                return;
            }

            if (!backgroundElements.length) {
                return;
            }

            backgroundElements.forEach(function(entry) {
                var $element = $(entry.element);

                if (typeof entry.ariaHidden === 'string') {
                    $element.attr('aria-hidden', entry.ariaHidden);
                } else {
                    $element.removeAttr('aria-hidden');
                }

                if (typeof entry.inert === 'string') {
                    $element.attr('inert', entry.inert);
                } else {
                    $element.removeAttr('inert');
                }
            });

            backgroundElements = [];
        }

        if (!$modal.attr('tabindex')) {
            $modal.attr('tabindex', '-1');
        }

        var $title = $modal.find('.blc-modal__title');
        var $message = $modal.find('.blc-modal__message');
        var $context = $modal.find('.blc-modal__context');
        var $error = $modal.find('.blc-modal__error');
        var $options = $modal.find('.blc-modal__options');
        var $field = $modal.find('.blc-modal__field');
        var $label = $modal.find('.blc-modal__label');
        var $input = $modal.find('.blc-modal__input');
        var $preview = $modal.find('.blc-modal__preview');
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
            isSubmitting: false,
            confirmPromise: null,
            resolvePromise: null,
            rejectPromise: null,
            wasConfirmed: false,
            mode: 'input'
        };

        function applyMode(options) {
            var requestedMode = options && typeof options.mode === 'string' ? options.mode : '';

            if (requestedMode === 'simple') {
                state.mode = 'simple';
                state.showInput = false;
                return;
            }

            if (options && options.showInput === false) {
                state.mode = 'simple';
                state.showInput = false;
                return;
            }

            state.mode = 'input';
            state.showInput = true;
        }

        function resetPromiseState() {
            state.confirmPromise = null;
            state.resolvePromise = null;
            state.rejectPromise = null;
            state.wasConfirmed = false;
        }

        function createConfirmationPromise() {
            state.confirmPromise = new Promise(function(resolve, reject) {
                state.resolvePromise = resolve;
                state.rejectPromise = reject;
            });

            state.confirmPromise.catch(function() {});

            return state.confirmPromise;
        }

        function clearError() {
            $error.removeClass('is-visible').text('');
        }

        function clearContext() {
            if (!$context.length) {
                return;
            }

            $context.empty().addClass('is-hidden');
        }

        function clearOptions() {
            if ($options.length) {
                $options.empty().addClass('is-hidden');
            }
        }

        function clearPreview() {
            if ($preview.length) {
                $preview.empty().addClass('is-hidden');
            }
        }

        function appendContent($container, content) {
            if (!$container.length) {
                return;
            }

            if (typeof content === 'function') {
                content = content($container);
            }

            if (content === null || typeof content === 'undefined') {
                return;
            }

            if (content.jquery) {
                $container.append(content);
            } else if (typeof window.DocumentFragment !== 'undefined' && content instanceof window.DocumentFragment) {
                $container.append(content);
            } else if (content instanceof window.Element) {
                $container.append(content);
            } else if (typeof content === 'string') {
                $container.html(content);
            }

            if ($container.children().length) {
                $container.removeClass('is-hidden');
            }
        }

        function setOptionsContent(content) {
            clearOptions();
            appendContent($options, content);
        }

        function setPreviewContent(content) {
            clearPreview();
            appendContent($preview, content);
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
            state.mode = 'input';
            var shouldReject = !state.wasConfirmed && typeof state.rejectPromise === 'function';
            var rejectFn = state.rejectPromise;

            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            $('body').removeClass('blc-modal-open');
            toggleBackgroundInert(false);

            setSubmitting(false);
            clearError();
            clearContext();
            clearOptions();
            clearPreview();

            resetPromiseState();

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

            if (shouldReject) {
                rejectFn(new Error('modal_dismissed'));
            }
        }

        function open(options) {
            if (!$modal.length) {
                return Promise.resolve({ value: '', helpers: helpers });
            }

            options = options || {};

            state.onConfirm = typeof options.onConfirm === 'function' ? options.onConfirm : null;
            applyMode(options);
            state.showCancel = options.showCancel !== false;
            resetPromiseState();
            var promise = createConfirmationPromise();

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
                if (state.mode === 'simple') {
                    confirmText = messages.simpleConfirmModalConfirm || messages.editModalConfirm;
                } else {
                    confirmText = messages.editModalConfirm;
                }
            }
            $confirm.text(confirmText || messages.editModalConfirm || 'Confirmer');

            var cancelText = options.cancelText;
            if (!cancelText) {
                if (state.mode === 'simple') {
                    cancelText = messages.simpleConfirmModalCancel || messages.cancelButton;
                } else {
                    cancelText = messages.cancelButton;
                }
            }
            cancelText = cancelText || 'Annuler';
            $cancel.text(cancelText);

            if (state.showCancel) {
                $cancel.show().prop('hidden', false).removeAttr('hidden').removeAttr('aria-hidden');
            } else {
                $cancel.hide().prop('hidden', true).attr('hidden', 'hidden').attr('aria-hidden', 'true');
            }
            $close.attr('aria-label', options.closeLabel || messages.closeLabel || 'Fermer');

            clearError();
            setContext(options);
            setOptionsContent(options.optionsContent);
            setPreviewContent(options.previewContent);
            setSubmitting(false);

            toggleBackgroundInert(true);
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

            return promise;
        }

        var helpers = {
            showError: showError,
            clearError: clearError,
            setSubmitting: setSubmitting,
            close: close,
            setOptionsContent: setOptionsContent,
            clearOptions: clearOptions,
            getOptionsContainer: function() {
                return $options;
            },
            setPreviewContent: setPreviewContent,
            clearPreview: clearPreview,
            getPreviewContainer: function() {
                return $preview;
            }
        };

        $confirm.on('click', function() {
            if (!state.isOpen || state.isSubmitting) {
                return;
            }

            var value = state.showInput ? $input.val() : '';
            state.wasConfirmed = true;

            if (typeof state.resolvePromise === 'function') {
                state.resolvePromise({ value: value, helpers: helpers });
            }

            if (state.onConfirm) {
                state.onConfirm(value, helpers);
            }
        });

        $cancel.on('click', function() {
            if (!state.isSubmitting) {
                if (typeof state.rejectPromise === 'function') {
                    state.rejectPromise(new Error('modal_cancelled'));
                }
                resetPromiseState();
                close();
            }
        });

        $close.on('click', function() {
            if (!state.isSubmitting) {
                if (typeof state.rejectPromise === 'function') {
                    state.rejectPromise(new Error('modal_cancelled'));
                }
                resetPromiseState();
                close();
            }
        });

        $modal.on('click', function(event) {
            if (event.target === $modal[0] && !state.isSubmitting) {
                if (typeof state.rejectPromise === 'function') {
                    state.rejectPromise(new Error('modal_cancelled'));
                }
                resetPromiseState();
                close();
            }
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape' && state.isOpen && !state.isSubmitting) {
                if (typeof state.rejectPromise === 'function') {
                    state.rejectPromise(new Error('modal_cancelled'));
                }
                resetPromiseState();
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

        function confirm(options) {
            var config = $.extend({}, options, {
                mode: 'simple',
                showInput: false
            });

            if (typeof config.showCancel === 'undefined') {
                config.showCancel = true;
            }

            return open(config);
        }

        return {
            open: open,
            close: close,
            confirm: confirm,
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

    function normalizeElement(element) {
        if (!element) {
            return null;
        }

        if (element.jquery) {
            return element.length ? element[0] : null;
        }

        return element;
    }

    function shouldRestoreFocus(target) {
        var element = normalizeElement(target);

        if (!element || typeof element.focus !== 'function') {
            return false;
        }

        var activeElement = document.activeElement;

        if (!activeElement || activeElement === document.body) {
            return true;
        }

        if (activeElement === element) {
            return false;
        }

        if (!document.body.contains(activeElement)) {
            return true;
        }

        var $active = $(activeElement);

        if (!$active.length) {
            return true;
        }

        if ($active.is(':hidden') || $active.is(':disabled')) {
            return true;
        }

        return false;
    }

    function restoreFocusAfterUpdate(target) {
        var element = normalizeElement(target);

        if (!shouldRestoreFocus(element)) {
            return;
        }

        var applyFocus = function() {
            if (!element) {
                return;
            }

            if (!document.body.contains(element)) {
                return;
            }

            if (!shouldRestoreFocus(element)) {
                return;
            }

            try {
                element.focus({ preventScroll: true });
            } catch (error) {
                element.focus();
            }
        };

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(applyFocus);
        } else {
            window.setTimeout(applyFocus, 0);
        }
    }

    function handleSuccessfulResponse(response, row, helpers) {
        var $row = row && row.jquery ? row : $(row);
        var prefersReducedMotion = false;

        if (typeof window.matchMedia === 'function') {
            var mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            prefersReducedMotion = !!(mediaQuery && mediaQuery.matches);
        }

        accessibility.speak(getAnnouncementMessage(response), 'polite');

        var rowHtml = '';
        var rowRemoved = true;
        if (response && response.data) {
            if (typeof response.data.rowHtml === 'string') {
                rowHtml = response.data.rowHtml;
            }

            if (typeof response.data.rowRemoved !== 'undefined') {
                rowRemoved = !!response.data.rowRemoved;
            }
        }

        var $replacementRow = $();
        if (rowHtml) {
            var $candidate = $(rowHtml);
            if ($candidate.length) {
                $replacementRow = $candidate.filter('tr').first();
                if (!$replacementRow.length) {
                    $replacementRow = $candidate.find('tr').first();
                }
            }
        }

        if ($replacementRow && $replacementRow.length && response && response.data && typeof response.data.rowRemoved === 'undefined') {
            rowRemoved = false;
        }

        var nextFocusTarget = null;
        if ($replacementRow && $replacementRow.length) {
            var $firstAction = $replacementRow.find(ACTION_FOCUS_SELECTOR).filter(':visible').first();
            if ($firstAction.length) {
                nextFocusTarget = $firstAction[0];
            }
        }

        if (!nextFocusTarget) {
            nextFocusTarget = findNextFocusTarget($row);
        }

        if (helpers && typeof helpers.close === 'function') {
            helpers.close(nextFocusTarget);
        }

        function finalizeListUpdate($currentRow) {
            var $normalizedRow = $currentRow && $currentRow.jquery ? $currentRow : $();
            var $tbody = $normalizedRow.closest('tbody');

            if (!rowRemoved && $replacementRow && $replacementRow.length && $normalizedRow.length) {
                if (!$tbody.length) {
                    $tbody = $('#the-list');
                }

                $normalizedRow.replaceWith($replacementRow);

                $(document).trigger('blcAdmin:listUpdated', {
                    response: response,
                    tbody: $tbody,
                    table: $tbody.closest('table'),
                    messageRow: null,
                    replacedRow: $replacementRow
                });

                restoreFocusAfterUpdate(nextFocusTarget);

                return;
            }

            if (!rowRemoved) {
                $normalizedRow.css('opacity', 1);
                restoreFocusAfterUpdate(nextFocusTarget);
                return;
            }

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

            restoreFocusAfterUpdate(nextFocusTarget);
        }

        if ($row && $row.length) {
            if (!rowRemoved) {
                finalizeListUpdate($row);
            } else if (prefersReducedMotion) {
                finalizeListUpdate($row);
            } else {
                $row.fadeOut(300, function() {
                    finalizeListUpdate($(this));
                });
            }
        } else {
            finalizeListUpdate($());
        }

        if (response && response.data && response.data.massUpdate) {
            displayMassUpdateSummary(response.data.massUpdate);
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

    $('#the-list').on('click', '.blc-apply-redirect', function(e) {
        e.preventDefault();

        var linkElement = $(this);
        var detectedTarget = linkElement.data('detectedTarget');
        if (typeof detectedTarget !== 'string') {
            detectedTarget = '';
        }
        detectedTarget = detectedTarget.trim();

        if (!detectedTarget) {
            var missingMessage = messages.applyRedirectMissingModalMessage
                || messages.applyRedirectMissingTarget
                || messages.applyRedirectError
                || messages.genericError;

            if (missingMessage) {
                accessibility.speak(missingMessage, 'assertive');
            }

            var missingTitle = messages.applyRedirectMissingModalTitle || messages.applyRedirectModalTitle || '';
            var missingPromise = modal.open({
                mode: 'simple',
                title: missingTitle,
                message: missingMessage,
                confirmText: messages.closeButton || messages.cancelButton || 'Fermer',
                closeLabel: messages.closeLabel,
                showCancel: false
            });

            if (missingPromise && typeof missingPromise.then === 'function') {
                missingPromise.then(function(result) {
                    var modalHelpers = result && result.helpers ? result.helpers : modal.helpers;
                    if (modalHelpers && typeof modalHelpers.close === 'function') {
                        modalHelpers.close();
                    } else {
                        modal.close();
                    }
                }).catch(function() {});
            }

            return;
        }

        var oldUrl = linkElement.data('url');
        if (typeof oldUrl !== 'string') {
            oldUrl = '';
        }
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
        var contextExcerpt = linkElement.data('contextExcerpt');
        if (typeof contextExcerpt !== 'string') {
            contextExcerpt = '';
        }
        var contextHtml = linkElement.data('contextHtml');
        if (typeof contextHtml !== 'string') {
            contextHtml = '';
        }

        var confirmationTemplate = messages.applyRedirectModalMessage
            || messages.applyRedirectConfirmation
            || '';
        var confirmationMessage = confirmationTemplate ? formatTemplate(confirmationTemplate, detectedTarget) : '';

        var confirmPromise = modal.open({
            mode: 'simple',
            title: messages.applyRedirectModalTitle || messages.editModalTitle,
            message: confirmationMessage,
            confirmText: messages.applyRedirectModalConfirm || messages.editModalConfirm,
            cancelText: messages.cancelButton,
            closeLabel: messages.closeLabel,
            showCancel: true,
            context: contextExcerpt,
            contextHtml: contextHtml,
            contextLabel: messages.contextLabel
        });

        if (confirmPromise && typeof confirmPromise.then === 'function') {
            confirmPromise.then(function(result) {
                var modalHelpers = result && result.helpers ? result.helpers : modal.helpers;

                processLinkUpdate(linkElement, {
                    helpers: modalHelpers,
                    value: detectedTarget,
                    oldUrl: oldUrl,
                    postId: postId,
                    rowId: rowId,
                    occurrenceIndex: occurrenceIndex,
                    nonce: nonce,
                    action: 'blc_apply_detected_redirect'
                });
            }).catch(function() {});
        }
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
        } else if (action === 'apply_redirect') {
            title = messages.applyRedirectModalTitle || messages.editModalTitle || '';
            confirmText = messages.applyRedirectModalConfirm || messages.editModalConfirm || '';
            template = messages.bulkApplyRedirectModalMessage || '';
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

    function createInlineActionHelpers(button, options) {
        var $button = $(button);
        var settings = options || {};

        return {
            setSubmitting: function(state) {
                $button.prop('disabled', !!state);
                if (state) {
                    $button.attr('aria-busy', 'true');
                } else {
                    $button.removeAttr('aria-busy');
                }
            },
            showError: function(message) {
                var fallback = settings.errorMessage || messages.applyRedirectError || messages.genericError;
                var finalMessage = message || fallback;

                accessibility.speak(finalMessage, 'assertive');
                window.alert(finalMessage);
            },
            close: function(nextFocus) {
                $button.prop('disabled', false);
                $button.removeAttr('aria-busy');

                if (nextFocus && typeof nextFocus.focus === 'function') {
                    nextFocus.focus();
                } else if (settings.restoreFocus !== false) {
                    $button.focus();
                }
            }
        };
    }

    function initializeMassUpdateControls(config) {
        config = config || {};

        var helpers = config.modalHelpers;
        if (!helpers || typeof helpers.setOptionsContent !== 'function') {
            helpers = modal.helpers;
        }

        if (!helpers || typeof helpers.setOptionsContent !== 'function') {
            return;
        }

        var state = config.state || {};
        state.applyGlobally = false;
        state.previewData = null;
        state.lastPreviewKey = '';
        state.isLoadingPreview = false;

        var $optionsWrapper = $('<div>');
        var checkboxId = 'blc-modal-apply-globally-' + Date.now();

        var $checkbox = $('<input>', {
            type: 'checkbox',
            id: checkboxId,
            class: 'blc-modal__apply-globally'
        });

        var $checkboxLabel = $('<label>', {
            class: 'blc-modal__options-label',
            for: checkboxId
        });

        $checkboxLabel.append($checkbox);
        $('<span>').text(messages.applyGloballyLabel || 'Appliquer partout').appendTo($checkboxLabel);
        $optionsWrapper.append($checkboxLabel);

        if (messages.applyGloballyHelp) {
            $('<p>', { class: 'blc-modal__options-help' }).text(messages.applyGloballyHelp).appendTo($optionsWrapper);
        }

        helpers.setOptionsContent($optionsWrapper);

        function showPreviewInfo(message) {
            if (!message) {
                helpers.clearPreview();
                return;
            }

            var $info = $('<p>').text(message);
            helpers.setPreviewContent($info);
        }

        function showPreviewLoading() {
            var message = messages.massUpdatePreviewLoading || '';
            if (!message) {
                message = messages.recheckInProgress || '';
            }

            if (message) {
                var $loading = $('<p>').text(message);
                helpers.setPreviewContent($loading);
            } else {
                helpers.clearPreview();
            }
        }

        function showPreviewError(message) {
            var finalMessage = message || messages.massUpdatePreviewError || messages.genericError;
            var $error = $('<p>', { class: 'blc-modal__preview-note' }).text(finalMessage);
            helpers.setPreviewContent($error);
        }

        function renderPreview(preview) {
            if (!preview || !Array.isArray(preview.items)) {
                showPreviewInfo(messages.massUpdatePreviewEmpty || '');
                return;
            }

            var items = preview.items;
            var $container = $('<div>');

            var totalCount = parseInt(preview.totalCount, 10);
            if (Number.isNaN(totalCount)) {
                totalCount = items.length;
            }

            if (messages.massUpdatePreviewTitle) {
                var headingText = messages.massUpdatePreviewTitle;
                if (totalCount > 0) {
                    headingText += ' (' + totalCount + ')';
                }

                $('<strong>').text(headingText).appendTo($container);
            }

            if (totalCount <= 1 && messages.massUpdatePreviewEmpty) {
                $('<p>').text(messages.massUpdatePreviewEmpty).appendTo($container);
            }

            var $list = $('<ul>', { class: 'blc-modal__preview-list' });

            items.forEach(function(item) {
                if (!item) {
                    return;
                }

                var postIdValue = typeof item.postId !== 'undefined' ? item.postId : item.post_id;
                var postId = '';
                if (typeof postIdValue !== 'undefined' && postIdValue !== null && postIdValue !== '') {
                    postId = String(postIdValue);
                }

                var postTitle = item.postTitle || item.post_title || '';
                if (!postTitle) {
                    postTitle = messages.massUpdateUntitled || '';
                }

                var descriptor = postTitle;
                if (postId) {
                    descriptor += ' (#' + postId + ')';
                }

                var $entry = $('<li>', { class: 'blc-modal__preview-item' });
                if (!item.canEdit) {
                    $entry.addClass('is-disabled');
                }

                var permalink = item.permalink || '';
                if (permalink) {
                    $('<a>', {
                        href: permalink,
                        target: '_blank',
                        rel: 'noopener noreferrer'
                    }).text(descriptor).appendTo($entry);
                } else {
                    $entry.text(descriptor);
                }

                var noteParts = [];
                if ((item.rowId || item.row_id) === config.rowId) {
                    if (messages.massUpdatePreviewCurrent) {
                        noteParts.push(messages.massUpdatePreviewCurrent);
                    }
                }

                if (!item.canEdit && messages.massUpdatePreviewRestrictedSingle) {
                    noteParts.push(messages.massUpdatePreviewRestrictedSingle);
                }

                if (noteParts.length) {
                    $('<div>').text(noteParts.join(' – ')).appendTo($entry);
                }

                $list.append($entry);
            });

            $container.append($list);

            if (preview.nonEditableCount > 0 && messages.massUpdatePreviewRestricted) {
                $('<p>', { class: 'blc-modal__preview-note' }).text(messages.massUpdatePreviewRestricted).appendTo($container);
            }

            helpers.setPreviewContent($container);
        }

        var $modalElement = $('#blc-modal');
        var $input = $modalElement.find('.blc-modal__input');
        $input.off('.blcMassUpdate');

        var previewRequest = null;

        function abortPreviewRequest() {
            if (previewRequest && typeof previewRequest.abort === 'function') {
                previewRequest.abort();
            }

            previewRequest = null;
        }

        function requestPreview(force) {
            if (!state.applyGlobally) {
                return;
            }

            var inputValue = $input.val();
            var trimmedValue = inputValue ? String(inputValue).trim() : '';

            if (!trimmedValue) {
                showPreviewInfo(messages.massUpdatePreviewNeedsUrl || '');
                return;
            }

            if (trimmedValue === config.oldUrl) {
                showPreviewInfo(messages.massUpdatePreviewNeedsUrl || '');
                return;
            }

            var previewKey = trimmedValue;
            if (!force && state.lastPreviewKey === previewKey) {
                return;
            }

            state.lastPreviewKey = previewKey;
            state.isLoadingPreview = true;

            abortPreviewRequest();
            showPreviewLoading();

            var requestData = {
                action: config.action || 'blc_edit_link',
                post_id: config.postId,
                row_id: config.rowId,
                occurrence_index: config.occurrenceIndex,
                old_url: config.oldUrl,
                new_url: trimmedValue,
                apply_globally: 1,
                preview_only: 1,
                _ajax_nonce: config.nonce
            };

            previewRequest = $.post(ajaxurl, requestData).done(function(response) {
                state.isLoadingPreview = false;

                if (response && response.success && response.data && response.data.massUpdate) {
                    state.previewData = response.data.massUpdate;
                    renderPreview(state.previewData);
                    return;
                }

                if (response && response.data && response.data.message) {
                    showPreviewError(response.data.message);
                    return;
                }

                showPreviewError(messages.massUpdatePreviewError || '');
            }).fail(function(xhr) {
                state.isLoadingPreview = false;

                var errorMessage = '';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    errorMessage = xhr.responseJSON.data.message;
                }

                showPreviewError(errorMessage || messages.massUpdatePreviewError || '');
            }).always(function() {
                previewRequest = null;
            });
        }

        var schedulePreview = debounce(function() {
            requestPreview(false);
        }, 400);

        $input.on('input.blcMassUpdate blur.blcMassUpdate', function() {
            if (!state.applyGlobally) {
                return;
            }

            schedulePreview();
        });

        var inactiveMessage = messages.massUpdatePreviewInactive || '';
        if (inactiveMessage) {
            showPreviewInfo(inactiveMessage);
        } else {
            helpers.clearPreview();
        }

        $checkbox.on('change', function() {
            var isChecked = $checkbox.is(':checked');
            state.applyGlobally = isChecked;

            if (isChecked) {
                requestPreview(true);
            } else {
                abortPreviewRequest();
                state.lastPreviewKey = '';
                state.previewData = null;
                if (inactiveMessage) {
                    showPreviewInfo(inactiveMessage);
                } else {
                    helpers.clearPreview();
                }
            }
        });

        state.abortPreview = abortPreviewRequest;
        state.refreshPreview = function(force) {
            if (state.applyGlobally) {
                requestPreview(!!force);
            }
        };

        state.isApplyGloballyChecked = function() {
            return state.applyGlobally;
        };

        return state;
    }

    function processLinkUpdate(linkElement, params) {
        var helpers = params.helpers || {
            setSubmitting: function() {},
            showError: function() {},
            close: function() {}
        };
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

        if (typeof helpers.setSubmitting === 'function') {
            helpers.setSubmitting(true);
        }

        var row = linkElement.closest('tr');
        row.css('opacity', 0.5);

        var massUpdateState = params.massUpdateState || null;
        if (massUpdateState && typeof massUpdateState.abortPreview === 'function') {
            massUpdateState.abortPreview();
        }

        var requestData = {
            action: params.action || 'blc_edit_link',
            post_id: params.postId,
            row_id: params.rowId,
            occurrence_index: params.occurrenceIndex,
            old_url: oldUrl,
            new_url: trimmedValue,
            _ajax_nonce: params.nonce
        };

        var applyGlobally = false;
        if (massUpdateState) {
            if (typeof massUpdateState.isApplyGloballyChecked === 'function') {
                applyGlobally = !!massUpdateState.isApplyGloballyChecked();
            } else if (typeof massUpdateState.applyGlobally !== 'undefined') {
                applyGlobally = !!massUpdateState.applyGlobally;
            }
        }

        if (applyGlobally) {
            requestData.apply_globally = 1;
        }

        if (params.extraData && typeof params.extraData === 'object') {
            $.extend(requestData, params.extraData);
        }

        $.post(ajaxurl, requestData).done(function(response) {
            if (response && response.success) {
                handleSuccessfulResponse(response, row, helpers);
            } else {
                var errorMessage = response && response.data && response.data.message
                    ? response.data.message
                    : messages.genericError;
                if (typeof helpers.setSubmitting === 'function') {
                    helpers.setSubmitting(false);
                }
                helpers.showError((messages.errorPrefix || '') + errorMessage);
                row.css('opacity', 1);
            }
        }).fail(function() {
            if (typeof helpers.setSubmitting === 'function') {
                helpers.setSubmitting(false);
            }
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

        var massUpdateState = {};

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
                    nonce: nonce,
                    massUpdateState: massUpdateState
                });
            }
        });

        initializeMassUpdateControls({
            state: massUpdateState,
            modalHelpers: modal.helpers,
            postId: postId,
            rowId: rowId,
            occurrenceIndex: occurrenceIndex,
            oldUrl: oldUrl,
            nonce: nonce,
            action: 'blc_edit_link'
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
        var $submit = $form.length ? $form.find('input[type="submit"], button[type="submit"]') : $();
        if (!$submit.length && $form.length) {
            $submit = $form.find('.button-primary');
        }
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
        var $reschedule = selectors.reschedule ? $(selectors.reschedule) : $();
        var $refresh = $('#blc-refresh-scan-status');

        var pollInterval = parseInt(config.pollInterval, 10);
        if (isNaN(pollInterval) || pollInterval < 2000) {
            pollInterval = 5000;
        }

        var maxIdleCycles = typeof config.maxIdleCycles === 'number' ? config.maxIdleCycles : 2;

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
        var rescheduleNonce = config.rescheduleNonce || '';
        var idleCycles = 0;
        var pollingPaused = false;

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

            if (state === 'idle') {
                idleCycles += 1;
                if (idleCycles >= maxIdleCycles && !pollingPaused) {
                    pausePolling();
                    var pauseMessageParts = [];
                    if (config.i18n && config.i18n.idlePollingPaused) {
                        pauseMessageParts.push(config.i18n.idlePollingPaused);
                    }
                    if (config.i18n && config.i18n.idlePollingResume) {
                        pauseMessageParts.push(config.i18n.idlePollingResume);
                    }
                    if (pauseMessageParts.length) {
                        toast.info(pauseMessageParts.join(' '));
                    }
                }
            } else {
                idleCycles = 0;
                if (pollingPaused) {
                    resumePolling(false);
                }
            }
        }

        function fetchStatus() {
            if (isFetching || pollingPaused) {
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

        function refreshPolling(immediate, options) {
            options = options || {};

            if (pollingPaused && !options.force) {
                return;
            }

            schedulePoll(immediate);
        }

        function pausePolling() {
            pollingPaused = true;
            if (pollTimer) {
                window.clearTimeout(pollTimer);
                pollTimer = null;
            }
            $panel.attr('data-polling-paused', '1');
        }

        function resumePolling(immediate) {
            pollingPaused = false;
            idleCycles = 0;
            $panel.removeAttr('data-polling-paused');
            refreshPolling(immediate, { force: true });
        }

        function setFormBusy(state) {
            if ($submit.length) {
                $submit.prop('disabled', state);
                if (state) {
                    $submit.attr('aria-busy', 'true');
                } else {
                    $submit.removeAttr('aria-busy');
                }
            }

            if ($form.length) {
                if (state) {
                    $form.attr('aria-busy', 'true');
                } else {
                    $form.removeAttr('aria-busy');
                }
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

        function sendStartRequest(isFullScan, options) {
            options = options || {};

            if (!config.startScanNonce || !window.wp || !wp.ajax || typeof wp.ajax.post !== 'function') {
                if (options.confirmationHelpers) {
                    options.confirmationHelpers.setSubmitting(false);
                    options.confirmationHelpers.close();
                }

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

            if (options.forceCancel) {
                requestData.force_cancel = 1;
            }

            wp.ajax.post(startAction, requestData).done(function(response) {
                lastRequestedFullScan = supportsFullScan ? !!isFullScan : true;

                if (response && response.status) {
                    updatePanel(response.status);
                }

                var successMessage = (response && response.message) || (config.i18n && config.i18n.manualScanQueued) || '';
                if (successMessage) {
                    $message.text(successMessage);
                    toast.success(successMessage);
                }

                if (response && response.warning) {
                    toast.warning(response.warning);
                }

                if (response && response.manual_trigger_failed && config.i18n && config.i18n.queueMessage) {
                    toast.info(config.i18n.queueMessage);
                }

                if (options.confirmationHelpers) {
                    options.confirmationHelpers.setSubmitting(false);
                    options.confirmationHelpers.close();
                }

                resumePolling(true);
            }).fail(function(error) {
                var data = error && error.responseJSON && error.responseJSON.data ? error.responseJSON.data : {};
                var failureMessage = data && data.message
                    ? data.message
                    : (config.i18n && config.i18n.startError ? config.i18n.startError : (messages.genericError || ''));

                if (data && data.requires_confirmation && !options.forceCancel) {
                    if (options.confirmationHelpers) {
                        options.confirmationHelpers.setSubmitting(false);
                        options.confirmationHelpers.close();
                    }

                    showConfirmation({
                        title: (config.i18n && config.i18n.restartTitle) || '',
                        message: failureMessage || '',
                        confirmText: (config.i18n && config.i18n.forceStartConfirm) || messages.editModalConfirm || 'Confirmer',
                        onConfirm: function(helpers) {
                            helpers.setSubmitting(true);
                            sendStartRequest(isFullScan, {
                                forceCancel: true,
                                confirmationHelpers: helpers
                            });
                        }
                    });

                    return;
                }

                if (options.confirmationHelpers) {
                    options.confirmationHelpers.setSubmitting(false);
                    options.confirmationHelpers.close();
                }

                $message.text(failureMessage);
                if (failureMessage) {
                    toast.error(failureMessage);
                }

                if (data && data.resolution_hint) {
                    toast.info(data.resolution_hint);
                }
            }).always(function() {
                setFormBusy(false);
                refreshPolling(false, { force: true });
            });
        }

        function startScan(isFullScan) {
            sendStartRequest(isFullScan, {});
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
                            toast.success(message);
                        }

                        resumePolling(true);
                    }).fail(function(error) {
                        var message = (config.i18n && config.i18n.cancelError) || messages.genericError || '';
                        if (error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
                            message = error.responseJSON.data.message;
                        }

                        $message.text(message);
                        if (message) {
                            toast.error(message);
                        }
                    }).always(function() {
                        helpers.setSubmitting(false);
                        helpers.close();
                        $cancel.prop('disabled', false).removeAttr('aria-busy');
                        refreshPolling(false, { force: true });
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
                    sendStartRequest(lastRequestedFullScan, {
                        forceCancel: true,
                        confirmationHelpers: helpers
                    });
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

        if ($reschedule.length && rescheduleNonce && window.wp && wp.ajax && typeof wp.ajax.post === 'function') {
            $reschedule.on('submit', function(event) {
                event.preventDefault();

                var $rescheduleButton = $reschedule.find('button[type="submit"], input[type="submit"]');
                if ($rescheduleButton.length) {
                    $rescheduleButton.prop('disabled', true).attr('aria-busy', 'true');
                }

                var rescheduleAction = (config.ajax && config.ajax.reschedule) ? config.ajax.reschedule : 'blc_reschedule_cron';

                wp.ajax.post(rescheduleAction, {
                    _ajax_nonce: rescheduleNonce
                }).done(function(response) {
                    var successMessage = (response && response.message) || (config.i18n && config.i18n.rescheduleSuccess) || '';
                    if (successMessage) {
                        $message.text(successMessage);
                        toast.success(successMessage);
                    }

                    if (response && Array.isArray(response.warnings)) {
                        response.warnings.forEach(function(warning) {
                            toast.warning(warning);
                        });
                    }

                    if (response && response.resolution_hint) {
                        toast.info(response.resolution_hint);
                    }
                }).fail(function(error) {
                    var data = error && error.responseJSON && error.responseJSON.data ? error.responseJSON.data : {};
                    var failureMessage = data && data.message
                        ? data.message
                        : (config.i18n && config.i18n.rescheduleError ? config.i18n.rescheduleError : messages.genericError || '');

                    $message.text(failureMessage);
                    toast.error(failureMessage);

                    if (data && data.resolution_hint) {
                        toast.info(data.resolution_hint);
                    }

                    if (data && data.warnings) {
                        [].concat(data.warnings).forEach(function(warning) {
                            toast.warning(warning);
                        });
                    }
                }).always(function() {
                    if ($rescheduleButton.length) {
                        $rescheduleButton.prop('disabled', false).removeAttr('aria-busy');
                    }
                });
            });
        }

        if ($refresh.length && !$refresh.data('blcRefreshBound')) {
            $refresh.data('blcRefreshBound', true).on('click', function(event) {
                event.preventDefault();
                resumePolling(true);
            });
        }

        lastState = currentStatus.state || 'idle';
        lastMessage = typeof currentStatus.message === 'string' ? currentStatus.message : '';
        updatePanel(currentStatus);
        refreshPolling(false, { force: true });
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
