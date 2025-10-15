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
        massUpdateFailureItem: '%1$s (ID %2$s)',
        personaApplied: 'Préréglage appliqué.',
        personaFailed: 'Ce préréglage ne peut pas être appliqué ici.',
        tableRefreshed: 'Liste des liens mise à jour.',
        tableFetchError: 'Impossible de rafraîchir la liste pour le moment.',
        savedViewPlaceholder: 'Sélectionnez une vue…',
        savedViewApplied: 'Vue « %s » appliquée.',
        savedViewCreated: 'Vue « %s » enregistrée.',
        savedViewUpdated: 'Vue « %s » mise à jour.',
        savedViewDeleted: 'Vue « %s » supprimée.',
        savedViewNameRequired: 'Veuillez saisir un nom pour enregistrer cette vue.',
        savedViewDeleteConfirm: 'Supprimer la vue « %s » ?',
        savedViewLimitReached: 'Limite de vues enregistrées atteinte.',
        savedViewGenericError: 'Impossible de gérer cette vue enregistrée pour le moment.',
        savedViewDefaultSuffix: ' (par défaut)',
        savedViewDefaultBadge: 'Vue par défaut',
        savedViewDefaultAssigned: 'Vue « %s » définie comme vue par défaut.',
        savedViewDefaultRemoved: 'Vue « %s » n’est plus la vue par défaut.'
    };

    var messages = $.extend({}, defaultMessages, window.blcAdminMessages || {});

    var uiConfig = window.blcAdminUi || {};
    var accessibilityPreferences = uiConfig.accessibility || {};
    var reduceMotionPreference = !!accessibilityPreferences.reduceMotion;

    function formatTemplate(template, value) {
        if (typeof template !== 'string') {
            return '';
        }

        var replacements;

        if (Array.isArray(value)) {
            replacements = value.map(function(item) {
                return (typeof item === 'undefined' || item === null) ? '' : String(item);
            });
        } else {
            replacements = [(typeof value === 'undefined' || value === null) ? '' : String(value)];
        }

        var replacementIndex = 0;

        return template.replace(/%([0-9]+\$)?s/g, function(match, position) {
            if (position) {
                var numericPosition = parseInt(position, 10) - 1;
                if (!Number.isNaN(numericPosition) && numericPosition >= 0 && numericPosition < replacements.length) {
                    return replacements[numericPosition];
                }

                return '';
            }

            var replacement = replacements[replacementIndex];
            replacementIndex += 1;

            return (typeof replacement === 'undefined') ? '' : replacement;
        });
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
    window.blcAdmin.integrations = window.blcAdmin.integrations || {};

    window.blcAdmin.integrations.hasStoredCredential = function(settings, key) {
        if (!settings || typeof key !== 'string') {
            return false;
        }

        var flag = 'has_' + key;
        if (Object.prototype.hasOwnProperty.call(settings, flag)) {
            return !!settings[flag];
        }

        var value = settings[key];

        return typeof value === 'string' && value !== '';
    };

    window.blcAdmin.integrations.getMaskedValue = function(settings, key, placeholder) {
        var hasFlag = false;

        if (settings && typeof key === 'string') {
            var flag = 'has_' + key;
            hasFlag = Object.prototype.hasOwnProperty.call(settings, flag);
        }

        if (hasFlag && window.blcAdmin.integrations.hasStoredCredential(settings, key)) {
            return typeof placeholder === 'string' && placeholder !== '' ? placeholder : '••••••••';
        }

        if (settings && typeof settings[key] === 'string') {
            return settings[key];
        }

        return '';
    };

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

            var exitDuration = reduceMotionPreference ? 0 : 200;

            if (exitDuration === 0) {
                $toast.removeClass('is-leaving');
                $toast.remove();
                return;
            }

            $toast.addClass('is-leaving');
            window.setTimeout(function() {
                $toast.remove();
            }, exitDuration);
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

    (function setupWebhookChannelAdvancedOptions() {
        var $channelField = $('#blc_notification_webhook_channel');
        if (!$channelField.length) {
            return;
        }

        var $containers = $('.blc-notification-channel-advanced');
        if (!$containers.length) {
            return;
        }

        function refreshVisibility() {
            var currentChannel = String($channelField.val() || '');

            $containers.each(function() {
                var $container = $(this);
                var targetChannel = String($container.data('blcWebhookChannel') || '');
                var shouldShow = !targetChannel || targetChannel === currentChannel;

                $container.toggleClass('is-active', shouldShow);

                if (shouldShow) {
                    $container.removeAttr('hidden');
                    $container.attr('aria-hidden', 'false');
                } else {
                    $container.attr('hidden', 'hidden');
                    $container.attr('aria-hidden', 'true');
                }
            });
        }

        $channelField.on('change', refreshVisibility);
        refreshVisibility();
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
        var prefersReducedMotion = reduceMotionPreference;

        if (!reduceMotionPreference && typeof window.matchMedia === 'function') {
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
        var $queueIndicator = $panel.find('#blc-manual-queue-indicator');
        var $queueList = $queueIndicator.find('.blc-scan-status__queue-list');
        var $queueWarning = $panel.find('#blc-queue-warning');
        var $support = $panel.find('#blc-scan-support');
        var $supportMessage = $support.find('.blc-scan-status__assist-message');
        var $supportCopy = $support.find('.blc-scan-status__assist-copy');
        var $log = $panel.find('#blc-scan-error-log');
        var $logList = $log.find('.blc-scan-status__log-list');
        var $logEmpty = $log.find('.blc-scan-status__log-empty');

        var pollInterval = parseInt(config.pollInterval, 10);
        if (isNaN(pollInterval) || pollInterval < 2000) {
            pollInterval = 5000;
        }

        var maxIdleCycles = typeof config.maxIdleCycles === 'number' ? config.maxIdleCycles : 2;
        var supportConfig = config.support || {};

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
        var summaryConfig = (config.i18n && config.i18n.summary) || {};
        var $summary = $('.blc-dashboard-summary');
        var summaryVariants = ['neutral', 'info', 'success', 'warning', 'danger'];

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

        function getLocale() {
            if (document.documentElement && document.documentElement.lang) {
                return document.documentElement.lang;
            }

            return 'fr';
        }

        function formatNumber(value, options) {
            var number = typeof value === 'number' ? value : parseFloat(value);
            if (!isFinite(number)) {
                number = 0;
            }

            var locale = getLocale();

            try {
                if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
                    return new Intl.NumberFormat(locale, options || {}).format(number);
                }
            } catch (error) {
                // Fallback to default formatting below.
            }

            if (typeof number.toLocaleString === 'function') {
                return number.toLocaleString(locale);
            }

            return String(number);
        }

        function getUnitTemplate(unitKey, isPlural) {
            if (!summaryConfig.units || !summaryConfig.units[unitKey]) {
                return isPlural ? '%s ' + unitKey + 's' : '%s ' + unitKey;
            }

            var templates = summaryConfig.units[unitKey];
            var template = isPlural ? templates.plural : templates.singular;

            if (typeof template !== 'string' || template === '') {
                return isPlural ? '%s ' + unitKey + 's' : '%s ' + unitKey;
            }

            return template;
        }

        function formatDuration(seconds) {
            var totalSeconds = Math.max(0, parseInt(seconds, 10));
            var remaining = totalSeconds;
            var parts = [];
            var hourSeconds = 3600;
            var minuteSeconds = 60;

            var hours = Math.floor(remaining / hourSeconds);
            if (hours > 0) {
                remaining -= hours * hourSeconds;
                parts.push(formatTemplate(getUnitTemplate('hour', hours !== 1), formatNumber(hours)));
            }

            var minutes = Math.floor(remaining / minuteSeconds);
            if (minutes > 0) {
                remaining -= minutes * minuteSeconds;
                parts.push(formatTemplate(getUnitTemplate('minute', minutes !== 1), formatNumber(minutes)));
            }

            var secondsPart = remaining;
            if (parts.length === 0 || secondsPart > 0) {
                parts.push(formatTemplate(getUnitTemplate('second', secondsPart !== 1), formatNumber(secondsPart)));
            }

            return parts.join(' ');
        }

        function formatRelativeTime(seconds, direction) {
            var totalSeconds = Math.max(0, parseInt(seconds, 10));
            var relativeDirection = direction === 'future' ? 'future' : 'past';

            if (totalSeconds === 0) {
                if (relativeDirection === 'future') {
                    return summaryConfig.relativeSoon || 'dans un instant';
                }

                return summaryConfig.relativeJustNow || 'à l’instant';
            }

            var units = [
                { key: 'day', seconds: 86400 },
                { key: 'hour', seconds: 3600 },
                { key: 'minute', seconds: 60 },
                { key: 'second', seconds: 1 }
            ];

            var unit = units[units.length - 1];
            for (var i = 0; i < units.length; i++) {
                if (totalSeconds >= units[i].seconds) {
                    unit = units[i];
                    break;
                }
            }

            var amount = Math.max(1, Math.round(totalSeconds / unit.seconds));

            if (typeof Intl !== 'undefined' && typeof Intl.RelativeTimeFormat === 'function') {
                try {
                    var rtf = new Intl.RelativeTimeFormat(getLocale(), { numeric: 'auto' });
                    return rtf.format(relativeDirection === 'future' ? amount : -amount, unit.key);
                } catch (error) {
                    // fall back to manual formatting below.
                }
            }

            var quantity = formatTemplate(getUnitTemplate(unit.key, amount !== 1), formatNumber(amount));
            var wrapper = relativeDirection === 'future'
                ? (summaryConfig.relativeFuture || 'dans %s')
                : (summaryConfig.relativePast || 'il y a %s');

            return formatTemplate(wrapper, quantity);
        }

        function formatSummaryQueueLabel(count) {
            var value = safeInt(count);
            if (value <= 0) {
                return '';
            }

            if (value === 1 && summaryConfig.queueSingle) {
                return formatTemplate(summaryConfig.queueSingle, formatNumber(value));
            }

            if (value > 1 && summaryConfig.queuePlural) {
                return formatTemplate(summaryConfig.queuePlural, formatNumber(value));
            }

            return formatNumber(value);
        }

        function mapStateVariant(state) {
            switch (state) {
                case 'running':
                    return 'info';
                case 'queued':
                    return 'warning';
                case 'completed':
                    return 'success';
                case 'failed':
                    return 'danger';
                case 'cancelled':
                    return 'warning';
                default:
                    return 'neutral';
            }
        }

        function mapProgressVariant(state) {
            switch (state) {
                case 'completed':
                    return 'success';
                case 'failed':
                    return 'danger';
                case 'queued':
                case 'running':
                    return 'info';
                case 'cancelled':
                    return 'warning';
                default:
                    return 'neutral';
            }
        }

        function formatSummaryStateDescription(status, details, nowTimestamp) {
            var parts = [];
            if (details) {
                parts.push(details);
            }

            var delta = safeInt(status.last_activity_delta);
            var updatedAt = safeInt(status.updated_at);
            var lastActivity = '';

            if (delta > 0) {
                lastActivity = formatTemplate(
                    summaryConfig.lastActivityRelative || 'Actualisé : %s',
                    formatRelativeTime(delta, 'past')
                );
            } else if (updatedAt > 0) {
                var direction = updatedAt > nowTimestamp ? 'future' : 'past';
                var difference = Math.abs(nowTimestamp - updatedAt);
                if (difference < 5) {
                    lastActivity = summaryConfig.lastActivityJustNow || 'Actualisé à l’instant';
                } else {
                    lastActivity = formatTemplate(
                        summaryConfig.lastActivityRelative || 'Actualisé : %s',
                        formatRelativeTime(difference, direction)
                    );
                }
            } else if (summaryConfig.lastActivityUnknown) {
                lastActivity = summaryConfig.lastActivityUnknown;
            }

            if (lastActivity) {
                parts.push(lastActivity);
            }

            var queueLabel = formatSummaryQueueLabel(status.manual_queue_length);
            if (queueLabel) {
                parts.push(queueLabel);
            }

            if (!parts.length) {
                return summaryConfig.stateDetailsFallback || '';
            }

            return parts.join(' ');
        }

        function formatSummaryProgressDescription(status) {
            var processed = safeInt(status.processed_items);
            var total = safeInt(status.total_items);

            if (total > 0) {
                return formatTemplate(
                    summaryConfig.progressWithTotal || '%1$s sur %2$s URL analysées',
                    [formatNumber(processed), formatNumber(total)]
                );
            }

            if (processed > 0) {
                return formatTemplate(
                    summaryConfig.progressWithoutTotal || '%s URL analysées',
                    formatNumber(processed)
                );
            }

            return summaryConfig.progressIdle || '';
        }

        function formatProgressValue(percent) {
            var value = parseFloat(percent);
            if (!isFinite(value)) {
                value = 0;
            }

            if (value < 0) {
                value = 0;
            }
            if (value > 100) {
                value = 100;
            }

            var decimals = value >= 10 ? 0 : 1;
            return formatNumber(value, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }) + ' %';
        }

        function formatSummaryThroughput(status) {
            var rate = typeof status.items_per_minute === 'number'
                ? status.items_per_minute
                : parseFloat(status.items_per_minute);
            if (!isFinite(rate)) {
                rate = 0;
            }

            var value;
            var variant = 'neutral';
            if (rate > 0) {
                var decimals = rate >= 10 ? 0 : 1;
                var formattedRate = formatNumber(rate, {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                });
                value = formatTemplate(summaryConfig.throughputValue || '%s URL/min', formattedRate);
                variant = 'info';
            } else {
                value = summaryConfig.placeholder || '—';
            }

            var duration = safeInt(status.duration_seconds);
            var description = '';
            if (duration > 0) {
                description = formatTemplate(
                    summaryConfig.durationLabel || 'Durée écoulée : %s',
                    formatDuration(duration)
                );
            } else if (summaryConfig.durationUnavailable) {
                description = summaryConfig.durationUnavailable;
            }

            return {
                value: value,
                description: description,
                variant: variant
            };
        }

        function setSummaryVariant($element, variant) {
            if (!$element || !$element.length) {
                return;
            }

            for (var i = 0; i < summaryVariants.length; i++) {
                $element.removeClass('blc-dashboard-summary__item--' + summaryVariants[i]);
            }

            var normalized = summaryVariants.indexOf(variant) !== -1 ? variant : 'neutral';
            $element.addClass('blc-dashboard-summary__item--' + normalized);
        }

        function updateDashboardSummary(status, details) {
            if (!$summary.length) {
                return;
            }

            var now = Math.floor(Date.now() / 1000);
            var current = status || {};
            var state = current.state || 'idle';

            var stateMetric = $summary.find('[data-summary-metric="state"]');
            if (stateMetric.length) {
                setSummaryVariant(stateMetric, mapStateVariant(state));
                stateMetric.find('[data-summary-field="state-value"]').text(getStateLabel(state));
                stateMetric.find('[data-summary-field="state-description"]').text(
                    formatSummaryStateDescription(current, details || '', now)
                );
            }

            var progressMetric = $summary.find('[data-summary-metric="progress"]');
            if (progressMetric.length) {
                setSummaryVariant(progressMetric, mapProgressVariant(state));
                var percent = (typeof current.progress_percentage === 'number')
                    ? current.progress_percentage
                    : computeProgress(current);
                progressMetric.find('[data-summary-field="progress-value"]').text(formatProgressValue(percent));
                progressMetric.find('[data-summary-field="progress-description"]').text(
                    formatSummaryProgressDescription(current)
                );
            }

            var throughputMetric = $summary.find('[data-summary-metric="throughput"]');
            if (throughputMetric.length) {
                var throughput = formatSummaryThroughput(current);
                setSummaryVariant(throughputMetric, throughput.variant);
                throughputMetric.find('[data-summary-field="throughput-value"]').text(throughput.value || '');
                throughputMetric.find('[data-summary-field="throughput-description"]').text(throughput.description || '');
            }
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

        function formatQueueLabel(count) {
            var label = '';

            if (config.i18n) {
                if (count === 0 && config.i18n.queueEmpty) {
                    label = config.i18n.queueEmpty;
                } else if (count === 1 && config.i18n.queueSingle) {
                    label = config.i18n.queueSingle.replace('%d', '1');
                } else if (count > 1 && config.i18n.queuePlural) {
                    label = config.i18n.queuePlural.replace('%d', String(count));
                }
            }

            if (!label && count > 0) {
                label = String(count);
            }

            return label;
        }

        function renderQueue(status) {
            if (!$queueIndicator.length) {
                return 0;
            }

            var queueLength = safeInt(status.manual_queue_length);
            var preview = Array.isArray(status.manual_queue_preview) ? status.manual_queue_preview : [];

            if (queueLength <= 0 && preview.length === 0) {
                $queueIndicator.attr('hidden', 'hidden').prop('hidden', true);
                $queueList.empty();

                return 0;
            }

            var label = formatQueueLabel(queueLength);
            if (label) {
                $queueIndicator.attr('data-queue-label', label);
            } else {
                $queueIndicator.removeAttr('data-queue-label');
            }

            $queueList.empty();

            preview.forEach(function(entry) {
                var parts = [];

                if (config.i18n) {
                    if (entry.is_full_scan && config.i18n.queueFullScan) {
                        parts.push(config.i18n.queueFullScan);
                    } else if (!entry.is_full_scan && config.i18n.queuePartialScan) {
                        parts.push(config.i18n.queuePartialScan);
                    }
                }

                if (entry.requested_at) {
                    var dateText = new Date(entry.requested_at * 1000).toLocaleString();
                    if (config.i18n && config.i18n.queueRequestedAt) {
                        parts.push(config.i18n.queueRequestedAt.replace('%s', dateText));
                    } else {
                        parts.push(dateText);
                    }
                }

                if (entry.requested_by_name && config.i18n && config.i18n.queueRequestedBy) {
                    parts.push(config.i18n.queueRequestedBy.replace('%s', entry.requested_by_name));
                }

                var itemText = parts.join(' · ');
                $('<li>', { class: 'blc-scan-status__queue-item' }).text(itemText).appendTo($queueList);
            });

            if ($queueList.children().length === 0 && queueLength > 0 && config.i18n && config.i18n.queueEmpty) {
                $('<li>', { class: 'blc-scan-status__queue-item blc-scan-status__queue-item--placeholder' })
                    .text(config.i18n.queueEmpty)
                    .appendTo($queueList);
            }

            $queueIndicator.removeAttr('hidden').prop('hidden', false);

            return queueLength;
        }

        function renderQueueWarning(queueLength) {
            if (!$queueWarning.length) {
                return;
            }

            if (queueLength > 0) {
                $queueWarning.removeAttr('hidden').prop('hidden', false);
            } else {
                $queueWarning.attr('hidden', 'hidden').prop('hidden', true);
            }
        }

        function renderLog(status) {
            if (!$log.length) {
                return;
            }

            var entries = Array.isArray(status.manual_error_log) ? status.manual_error_log : [];

            $logList.empty();

            if (entries.length === 0) {
                if ($logEmpty.length) {
                    var emptyMessage = config.i18n && config.i18n.logEmpty ? config.i18n.logEmpty : $logEmpty.text();
                    $logEmpty.text(emptyMessage).removeAttr('hidden').prop('hidden', false);
                }

                return;
            }

            if ($logEmpty.length) {
                $logEmpty.attr('hidden', 'hidden').prop('hidden', true);
            }

            entries.forEach(function(entry) {
                var $item = $('<li>', { class: 'blc-scan-status__log-item' });

                if (entry.timestamp) {
                    var timestamp = new Date(entry.timestamp * 1000).toLocaleString();
                    $('<span>', { class: 'blc-scan-status__log-time' }).text(timestamp).appendTo($item);
                }

                if (entry.message) {
                    $('<p>', { class: 'blc-scan-status__log-text' }).text(entry.message).appendTo($item);
                }

                if (entry.context) {
                    $('<span>', { class: 'blc-scan-status__log-context' }).text(entry.context).appendTo($item);
                }

                $logList.append($item);
            });
        }

        function hideSupportAssist() {
            if ($support.length) {
                $support.attr('hidden', 'hidden').prop('hidden', true);
            }
        }

        function showSupportAssist(message) {
            if (!$support.length) {
                return;
            }

            var assistMessage = message;
            if (!assistMessage && config.i18n && config.i18n.supportAssistMessage) {
                assistMessage = config.i18n.supportAssistMessage;
            }

            if ($supportMessage.length) {
                $supportMessage.text(assistMessage || '');
            }

            $support.removeAttr('hidden').prop('hidden', false);
        }

        function bindSupportCopy() {
            if (!$supportCopy.length || $supportCopy.data('blcCopyBound')) {
                return;
            }

            $supportCopy.data('blcCopyBound', true).on('click', function(event) {
                event.preventDefault();
                var command = $(this).data('command') || supportConfig.wpCliCommand || '';
                if (!command) {
                    return;
                }

                var successMessage = config.i18n && config.i18n.supportCopySuccess ? config.i18n.supportCopySuccess : '';
                var errorMessage = config.i18n && config.i18n.supportCopyError ? config.i18n.supportCopyError : '';

                var copyPromise;

                if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
                    copyPromise = navigator.clipboard.writeText(command);
                } else {
                    copyPromise = new Promise(function(resolve, reject) {
                        var $temp = $('<textarea>').val(command).appendTo('body').css({ position: 'fixed', top: '-1000px' });
                        try {
                            $temp[0].select();
                            var succeeded = document.execCommand('copy');
                            $temp.remove();
                            if (succeeded) {
                                resolve();
                            } else {
                                reject();
                            }
                        } catch (error) {
                            $temp.remove();
                            reject(error);
                        }
                    });
                }

                copyPromise.then(function() {
                    if (successMessage) {
                        toast.success(successMessage);
                    }
                }).catch(function() {
                    if (errorMessage) {
                        toast.error(errorMessage);
                    }
                });
            });
        }

        function showQueueDecisionModal(isFullScan, failureMessage, data) {
            if (!modal || typeof modal.open !== 'function') {
                if (failureMessage && window.confirm(failureMessage)) {
                    sendStartRequest(isFullScan, { forceCancel: true });
                }
                return;
            }

            var queueLength = safeInt(data && data.queue_length);
            var queueLabel = (config.i18n && config.i18n.queueAddLabel) || 'Ajouter à la file d’attente';
            var replaceLabel = (config.i18n && config.i18n.forceStartConfirm) || messages.editModalConfirm || 'Confirmer';
            var modalTitle = (config.i18n && config.i18n.queueDecisionTitle) || '';
            var noteText = (config.i18n && config.i18n.queueDecisionNote) || '';

            modal.open({
                title: modalTitle,
                message: failureMessage || '',
                showInput: false,
                showCancel: true,
                confirmText: replaceLabel,
                cancelText: messages.cancelButton || 'Annuler',
                optionsContent: function() {
                    var $wrapper = $('<div>', { class: 'blc-queue-modal__options' });
                    var $queueButton = $('<button>', {
                        type: 'button',
                        class: 'button button-primary blc-queue-modal__queue'
                    }).text(queueLabel).on('click', function(event) {
                        event.preventDefault();
                        var helpers = modal.helpers;
                        if (helpers && typeof helpers.setSubmitting === 'function') {
                            helpers.setSubmitting(true);
                        }
                        sendStartRequest(isFullScan, {
                            queueOnBusy: true,
                            confirmationHelpers: helpers
                        });
                    });

                    $wrapper.append($queueButton);

                    if (noteText) {
                        $('<p>', { class: 'blc-queue-modal__note' }).text(noteText).appendTo($wrapper);
                    }

                    if (queueLength > 0) {
                        var existingLabel = formatQueueLabel(queueLength);
                        if (existingLabel) {
                            $('<p>', { class: 'blc-queue-modal__note' }).text(existingLabel).appendTo($wrapper);
                        }
                    }

                    return $wrapper;
                },
                onConfirm: function(helpers) {
                    helpers.setSubmitting(true);
                    sendStartRequest(isFullScan, {
                        forceCancel: true,
                        confirmationHelpers: helpers
                    });
                }
            });
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

            var queueLength = renderQueue(currentStatus);
            renderQueueWarning(queueLength);
            renderLog(currentStatus);

            updateDashboardSummary(currentStatus, details);

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

            if (options.queueOnBusy) {
                requestData.queue_on_busy = 1;
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

                if (response && response.queue_cleared && config.i18n && config.i18n.queueCleared) {
                    toast.warning(config.i18n.queueCleared);
                }

                if (options.confirmationHelpers) {
                    options.confirmationHelpers.setSubmitting(false);
                    options.confirmationHelpers.close();
                }

                hideSupportAssist();
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

                    if (data.queue_available) {
                        showQueueDecisionModal(isFullScan, failureMessage, data);
                    } else {
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
                    }

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

                showSupportAssist(failureMessage);
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

                    if (response && response.status) {
                        updatePanel(response.status);
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

        bindSupportCopy();
        hideSupportAssist();

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

    (function setupDashboardSparklines() {
        var $sparklines = $('.blc-dashboard-links-page .blc-sparkline');

        if (!$sparklines.length) {
            return;
        }

        var SVG_NS = 'http://www.w3.org/2000/svg';

        function parsePoints($el) {
            var cached = $el.data('sparklinePoints');
            if (Array.isArray(cached)) {
                return cached;
            }

            var raw = $el.attr('data-blc-points');
            var parsed = [];

            if (typeof raw === 'string' && raw.trim() !== '') {
                try {
                    parsed = JSON.parse(raw);
                } catch (error) {
                    parsed = [];
                }
            } else if (Array.isArray(raw)) {
                parsed = raw;
            }

            if (!Array.isArray(parsed)) {
                parsed = [];
            }

            parsed = parsed
                .filter(function(point) {
                    return point && typeof point.value !== 'undefined';
                })
                .map(function(point) {
                    var value = Number(point.value);
                    if (!Number.isFinite(value)) {
                        value = 0;
                    }

                    return {
                        value: value,
                        label: point.label || '',
                        formatted: point.formatted || String(value)
                    };
                });

            $el.data('sparklinePoints', parsed);

            return parsed;
        }

        function createSvgElement(tagName, attributes) {
            var element = document.createElementNS(SVG_NS, tagName);

            Object.keys(attributes || {}).forEach(function(key) {
                element.setAttribute(key, String(attributes[key]));
            });

            return element;
        }

        function buildSparklineSvg(points, width, height) {
            var svg = createSvgElement('svg', {
                viewBox: '0 0 ' + width + ' ' + height,
                width: '100%',
                height: '100%',
                'aria-hidden': 'true',
                focusable: 'false'
            });

            if (!points.length) {
                return svg;
            }

            var values = points.map(function(point) {
                return point.value;
            });

            var min = Math.min.apply(Math, values);
            var max = Math.max.apply(Math, values);

            if (!Number.isFinite(min) || !Number.isFinite(max)) {
                min = 0;
                max = 0;
            }

            if (min === max) {
                var padding = max === 0 ? 1 : Math.abs(max) * 0.15;
                min -= padding;
                max += padding;
            }

            var range = max - min;
            if (range <= 0) {
                range = 1;
            }

            var coords = [];
            var stepX = points.length > 1 ? width / (points.length - 1) : 0;

            points.forEach(function(point, index) {
                var x;
                if (points.length === 1) {
                    x = width / 2;
                } else {
                    x = index * stepX;
                }

                var ratio = (point.value - min) / range;
                if (!Number.isFinite(ratio)) {
                    ratio = 0;
                }
                var y = height - (ratio * height);

                coords.push({
                    x: Number(x.toFixed(2)),
                    y: Number(y.toFixed(2)),
                    point: point
                });
            });

            if (coords.length > 1) {
                var areaPath = 'M ' + coords[0].x + ' ' + height + ' ';
                coords.forEach(function(coord) {
                    areaPath += 'L ' + coord.x + ' ' + coord.y + ' ';
                });
                areaPath += 'L ' + coords[coords.length - 1].x + ' ' + height + ' Z';

                svg.appendChild(createSvgElement('path', {
                    d: areaPath.trim(),
                    class: 'blc-sparkline__area'
                }));
            }

            var linePath = '';
            coords.forEach(function(coord, index) {
                linePath += (index === 0 ? 'M ' : ' L ') + coord.x + ' ' + coord.y;
            });

            svg.appendChild(createSvgElement('path', {
                d: linePath.trim(),
                class: 'blc-sparkline__path'
            }));

            coords.forEach(function(coord) {
                var circle = createSvgElement('circle', {
                    class: 'blc-sparkline__point',
                    cx: coord.x,
                    cy: coord.y,
                    r: 4
                });

                var labels = [];
                if (coord.point.label) {
                    labels.push(String(coord.point.label));
                }
                if (coord.point.formatted) {
                    labels.push(String(coord.point.formatted));
                }

                if (labels.length) {
                    var title = createSvgElement('title', {});
                    title.textContent = labels.join(' · ');
                    circle.appendChild(title);
                }

                svg.appendChild(circle);
            });

            return svg;
        }

        function renderSparkline($el) {
            var points = parsePoints($el);
            if (!points.length) {
                var emptyMessage = $el.attr('data-empty-message') || (messages && messages.noItemsMessage) || 'Données insuffisantes.';
                $el.empty().addClass('blc-sparkline--empty').text(emptyMessage);
                return;
            }

            var width = Math.max($el.innerWidth(), 220);
            var height = Math.max($el.innerHeight(), 120);

            var svg = buildSparklineSvg(points, width, height);

            $el.removeClass('blc-sparkline--empty');
            $el.empty().append(svg);
        }

        $sparklines.each(function() {
            renderSparkline($(this));
        });

        $(window).on('resize', debounce(function() {
            $sparklines.each(function() {
                var $el = $(this);
                if ($el.hasClass('blc-sparkline--empty')) {
                    return;
                }
                // Clear cached width-based rendering to recalculate.
                $el.empty();
                renderSparkline($el);
            });
        }, 200));
    })();

    function initFieldHelp() {
        var $openWrapper = null;
        var speak = (accessibility && typeof accessibility.speak === 'function') ? accessibility.speak : function() {};

        function closeWrapper($wrapper) {
            if (!$wrapper || !$wrapper.length) {
                return;
            }

            $wrapper.removeClass('is-active');
            var $button = $wrapper.find('.blc-field-help');
            if ($button.length) {
                $button.removeClass('is-active').attr('aria-expanded', 'false');
            }

            $openWrapper = null;
        }

        $(document).on('click', '.blc-field-help', function(event) {
            event.preventDefault();
            event.stopPropagation();

            var $button = $(this);
            var $wrapper = $button.closest('.blc-field-help-wrapper');

            if (!$wrapper.length) {
                return;
            }

            if ($openWrapper && $openWrapper.get(0) !== $wrapper.get(0)) {
                closeWrapper($openWrapper);
            }

            var isActive = $wrapper.hasClass('is-active');
            if (isActive) {
                closeWrapper($wrapper);
            } else {
                $wrapper.addClass('is-active');
                $button.addClass('is-active').attr('aria-expanded', 'true');
                var $bubble = $wrapper.find('.blc-field-help__bubble');
                if ($bubble.length) {
                    var announcement = ($bubble.text() || '').trim();
                    if (announcement) {
                        speak(announcement, 'polite');
                    }
                }
                $openWrapper = $wrapper;
            }
        });

        $(document).on('click', function(event) {
            if (!$openWrapper || !$openWrapper.length) {
                return;
            }

            if ($(event.target).closest('.blc-field-help-wrapper').length) {
                return;
            }

            closeWrapper($openWrapper);
        });

        $(document).on('keydown', function(event) {
            if (event.key === 'Escape' && $openWrapper) {
                closeWrapper($openWrapper);
            }
        });

        $(document).on('focusout', '.blc-field-help', function() {
            if ($openWrapper) {
                closeWrapper($openWrapper);
            }
        });
    }

    function applyPersonaSettings(settings, $scope) {
        if (!settings || typeof settings !== 'object') {
            return 0;
        }

        var applied = 0;
        var $context = $scope && $scope.length ? $scope : $('.blc-settings-form');

        $.each(settings, function(option, value) {
            if (!option) {
                return;
            }

            var selector = '[name="' + option + '"]';
            var $fields = $context.find(selector);
            if (!$fields.length) {
                $fields = $(selector);
            }

            if (!$fields.length) {
                return;
            }

            if ($fields.is(':radio')) {
                var stringValue = value;
                if (typeof stringValue !== 'string') {
                    stringValue = String(stringValue);
                }
                var $matched = $fields.filter('[value="' + stringValue + '"]');
                if ($matched.length) {
                    $matched.prop('checked', true).trigger('change');
                    applied++;
                }
            } else if ($fields.is(':checkbox')) {
                var isChecked = !!value;
                $fields.prop('checked', isChecked).trigger('change');
                applied++;
            } else {
                $fields.val(value).trigger('change');
                applied++;
            }
        });

        return applied;
    }

    function initAdvancedSettings($scope) {
        var $root = ($scope && $scope.length) ? $scope : $(document);
        var $containers = $root.find('.blc-settings-advanced');

        if ($root.is && $root.is('.blc-settings-advanced')) {
            $containers = $containers.add($root);
        }

        if (!$containers.length) {
            return;
        }

        $containers.each(function() {
            var $container = $(this);
            if ($container.data('blcAdvancedInit')) {
                return;
            }

            $container.data('blcAdvancedInit', true);
            var $tabs = $container.find('.blc-settings-advanced__tab');
            var $panels = $container.find('.blc-settings-advanced__panel');
            var $personas = $container.find('.blc-persona');
            var $form = $container.closest('form');

            function activateTab($tab, shouldFocus) {
                if (!$tab || !$tab.length) {
                    return;
                }

                var targetSlug = String($tab.data('blcTarget') || '');
                var $targetPanel = targetSlug
                    ? $panels.filter('[data-blc-panel="' + targetSlug + '"]')
                    : $();

                $tabs.removeClass('is-active').attr({ 'aria-selected': 'false', tabindex: '-1' });
                $panels.attr('hidden', true).removeClass('is-active');

                $tab.addClass('is-active').attr({ 'aria-selected': 'true', tabindex: '0' });
                if ($targetPanel.length) {
                    $targetPanel.removeAttr('hidden').addClass('is-active');
                }

                if (shouldFocus && typeof $tab[0].focus === 'function') {
                    $tab[0].focus();
                }
            }

            function focusNextTab(currentIndex, delta) {
                var count = $tabs.length;
                if (!count) {
                    return;
                }

                var nextIndex = (currentIndex + delta + count) % count;
                var $next = $tabs.eq(nextIndex);
                activateTab($next, true);
            }

            var $initial = $tabs.filter('.is-active').first();
            if (!$initial.length) {
                $initial = $tabs.first();
            }
            activateTab($initial, false);

            $tabs.on('click', function(event) {
                event.preventDefault();
                activateTab($(this), true);
            });

            $tabs.on('keydown', function(event) {
                var index = $tabs.index(this);
                if (index < 0) {
                    return;
                }

                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    focusNextTab(index, 1);
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    focusNextTab(index, -1);
                }
            });

            if ($personas.length) {
                $personas.each(function() {
                    var $button = $(this);
                    if (!$button.attr('aria-pressed')) {
                        $button.attr('aria-pressed', 'false');
                    }
                });

                $personas.on('click', function(event) {
                    event.preventDefault();
                    var $button = $(this);
                    var rawSettings = $button.attr('data-blc-persona-settings');
                    var settings = {};

                    if (typeof rawSettings === 'string' && rawSettings !== '') {
                        try {
                            settings = JSON.parse(rawSettings);
                        } catch (error) {
                            settings = {};
                        }
                    } else if (typeof rawSettings === 'object' && rawSettings !== null) {
                        settings = rawSettings;
                    }

                    var appliedCount = applyPersonaSettings(settings, $form);
                    if (appliedCount > 0) {
                        $personas.attr('aria-pressed', 'false').removeClass('is-active');
                        $button.attr('aria-pressed', 'true').addClass('is-active');
                        if (toast && typeof toast.show === 'function') {
                            toast.show(messages.personaApplied || '', 'success');
                        }
                        accessibility.speak(messages.personaApplied || '', 'polite');
                    } else {
                        if (toast && typeof toast.warning === 'function') {
                            toast.warning(messages.personaFailed || '');
                        }
                        accessibility.speak(messages.personaFailed || '', 'assertive');
                    }
                });
            }
        });
    }

    var createSettingsModeToggle = (typeof window !== 'undefined' && window.blcSettingsModeToggleFactory)
        ? window.blcSettingsModeToggleFactory
        : null;
    var initSettingsModeToggle = null;

    if (typeof createSettingsModeToggle === 'function') {
        initSettingsModeToggle = createSettingsModeToggle($, {
            toast: toast,
            accessibility: accessibility,
            initAdvancedSettings: initAdvancedSettings
        });
    }

    window.blcAdmin = window.blcAdmin || {};
    window.blcAdmin.helpers = window.blcAdmin.helpers || {};

    if (typeof initSettingsModeToggle === 'function') {
        window.blcAdmin.initSettingsModeToggle = initSettingsModeToggle;
        window.blcAdmin.helpers.initSettingsModeToggle = initSettingsModeToggle;
    } else {
        window.blcAdmin.initSettingsModeToggle = function() {
            return false;
        };
        window.blcAdmin.helpers.initSettingsModeToggle = window.blcAdmin.initSettingsModeToggle;
    }

    function initLinksTableAjax() {
        var $forms = $('.blc-links-filter-form[data-blc-ajax-table]');
        if (!$forms.length) {
            return;
        }

        $forms.each(function() {
            var $form = $(this);
            var endpoint = $form.data('blcAjaxEndpoint');
            var nonce = $form.data('blcAjaxNonce');

            if (!endpoint || !nonce) {
                return;
            }

            var cacheTtl = parseInt($form.data('blcCacheTtl'), 10);
            if (!Number.isFinite(cacheTtl) || cacheTtl <= 0) {
                cacheTtl = 60;
            }

            var initialState = {};
            var rawInitialState = $form.data('blcInitialState');
            if (typeof rawInitialState === 'string' && rawInitialState !== '') {
                try {
                    initialState = JSON.parse(rawInitialState);
                } catch (error) {
                    initialState = {};
                }
            } else if (typeof rawInitialState === 'object' && rawInitialState !== null) {
                initialState = rawInitialState;
            }

            var cacheStore = {};
            var pendingRequests = {};
            var prefetchTimer = null;
            var viewsNonce = $form.attr('data-blc-views-nonce') || '';
            var viewsLimitAttr = parseInt($form.attr('data-blc-views-limit'), 10);
            var viewsLimit = Number.isFinite(viewsLimitAttr) ? viewsLimitAttr : 0;
            var rawSavedViewsAttr = $form.attr('data-blc-saved-views');
            var savedViews;
            try {
                savedViews = rawSavedViewsAttr ? JSON.parse(rawSavedViewsAttr) : [];
            } catch (error) {
                savedViews = [];
            }
            savedViews = normalizeSavedViewList(savedViews);
            var savedViewElements = getSavedViewElements();

            function normalizeValue(value) {
                if (typeof value === 'undefined' || value === null) {
                    return '';
                }

                return String(value);
            }

            function buildCacheKey(params) {
                var normalized = [];
                Object.keys(params).sort().forEach(function(key) {
                    if (key === 'action' || key === '_ajax_nonce') {
                        return;
                    }
                    normalized.push(key + '=' + normalizeValue(params[key]));
                });

                return normalized.join('&');
            }

            function collectParams() {
                var params = {};
                $.each($form.serializeArray(), function(_index, field) {
                    if (field.name === 'action' || field.name === 'action2') {
                        return;
                    }
                    params[field.name] = field.value;
                });
                return params;
            }

            function syncState(state) {
                if (!state || typeof state !== 'object') {
                    return;
                }

                var view = typeof state.view === 'string' && state.view !== '' ? state.view : 'all';
                $form.find('input[data-blc-state-field="link_type"]').val(view);

                if (state.sorting && typeof state.sorting === 'object') {
                    if (typeof state.sorting.orderby !== 'undefined') {
                        $form.find('input[data-blc-state-field="orderby"]').val(state.sorting.orderby || '');
                    }
                    if (typeof state.sorting.order !== 'undefined') {
                        $form.find('input[data-blc-state-field="order"]').val(state.sorting.order || '');
                    }
                }

                if (state.pagination && typeof state.pagination.current !== 'undefined') {
                    $form.find('input[data-blc-state-field="paged"]').val(state.pagination.current || 1);
                }

                if (typeof state.search !== 'undefined') {
                    var $search = $form.find('.search-box input[name="s"]');
                    if ($search.length) {
                        $search.val(state.search || '');
                    }
                }

                if (typeof state.post_type !== 'undefined') {
                    var $postType = $form.find('select[name="post_type"]');
                    if ($postType.length) {
                        var value = state.post_type || '';
                        $postType.val(value);
                    }
                }
            }

            function updateActiveCards(view) {
                if (typeof view !== 'string' || view === '') {
                    view = 'all';
                }

                $('.blc-stats-box .blc-stat').each(function() {
                    var $card = $(this);
                    var type = $card.data('linkType') || 'all';
                    var isActive = (view === 'all' && type === 'all') || (view !== 'all' && type === view);
                    $card.toggleClass('is-active', isActive);
                    if (isActive) {
                        $card.attr('aria-current', 'page');
                    } else {
                        $card.removeAttr('aria-current');
                    }
                });
            }

            function updateHistory(state) {
                if (!window.history || !history.replaceState) {
                    return;
                }

                var baseParams = {};
                var pageSlug = $form.find('input[name="page"]').val() || 'blc-dashboard';
                baseParams.page = pageSlug;

                if (state.view && state.view !== 'all') {
                    baseParams.link_type = state.view;
                }

                if (state.post_type) {
                    baseParams.post_type = state.post_type;
                }

                if (state.search) {
                    baseParams.s = state.search;
                }

                if (state.sorting) {
                    if (state.sorting.orderby) {
                        baseParams.orderby = state.sorting.orderby;
                    }
                    if (state.sorting.order) {
                        baseParams.order = state.sorting.order;
                    }
                }

                if (state.pagination && state.pagination.current && state.pagination.current > 1) {
                    baseParams.paged = state.pagination.current;
                }

                var query = $.param(baseParams);
                var newUrl = window.location.pathname + (query ? '?' + query : '');

                try {
                    history.replaceState({ blcLinksState: state }, '', newUrl);
                } catch (error) {
                    // Ignore history errors (Safari private mode etc.).
                }
            }

            function schedulePrefetch(state) {
                if (prefetchTimer) {
                    window.clearTimeout(prefetchTimer);
                }

                if (!state || !state.pagination) {
                    return;
                }

                var current = parseInt(state.pagination.current, 10);
                var total = parseInt(state.pagination.total_pages, 10);
                if (!Number.isFinite(current) || current < 1 || !Number.isFinite(total) || total <= current) {
                    return;
                }

                prefetchTimer = window.setTimeout(function() {
                    var params = collectParams();
                    params.paged = current + 1;
                    requestTable(params, { prefetch: true });
                }, 400);
            }

            function requestTable(params, options) {
                options = options || {};
                var cacheKey = buildCacheKey(params);
                var now = Date.now();

                if (!options.prefetch && cacheStore[cacheKey] && (now - cacheStore[cacheKey].timestamp) < cacheTtl * 1000) {
                    renderResponse(cacheStore[cacheKey].data);
                    return $.Deferred().resolve(cacheStore[cacheKey].data).promise();
                }

                if (pendingRequests[cacheKey]) {
                    return pendingRequests[cacheKey];
                }

                var requestData = $.extend({}, params, {
                    action: 'blc_fetch_links_table',
                    _ajax_nonce: nonce
                });

                if (!options.prefetch) {
                    $form.addClass('is-loading');
                }

                var request = $.ajax({
                    url: endpoint,
                    method: 'POST',
                    dataType: 'json',
                    data: requestData
                }).done(function(response) {
                    if (!response || !response.success || !response.data) {
                        throw new Error('invalid_response');
                    }

                    cacheStore[cacheKey] = {
                        timestamp: Date.now(),
                        data: response.data
                    };

                    if (!options.prefetch) {
                        renderResponse(response.data);
                    }
                }).fail(function() {
                    if (!options.prefetch) {
                        if (toast && typeof toast.error === 'function') {
                            toast.error(messages.tableFetchError || '');
                        }
                        accessibility.speak(messages.tableFetchError || '', 'assertive');
                    }
                }).always(function() {
                    if (!options.prefetch) {
                        $form.removeClass('is-loading');
                    }
                    delete pendingRequests[cacheKey];
                });

                pendingRequests[cacheKey] = request;
                return request;
            }

            function renderResponse(data) {
                if (!data) {
                    return;
                }

                if (data.markup) {
                    $form.find('[data-blc-table-region]').html(data.markup);
                }

                if (data.state) {
                    syncState(data.state);
                    updateActiveCards(data.state.view || 'all');
                    updateHistory(data.state);
                    schedulePrefetch(data.state);
                }

                accessibility.speak(messages.tableRefreshed || '', 'polite');
            }

            function extractParam(url, key) {
                if (!url || !key) {
                    return '';
                }

                try {
                    if (typeof window.URL === 'function') {
                        var parsed = new URL(url, window.location.origin);
                        return parsed.searchParams.get(key) || '';
                    }
                } catch (error) {
                    // Fallback to manual parsing below.
                }

                var anchor = document.createElement('a');
                anchor.href = url;

                if (anchor.search && anchor.search.length > 1) {
                    var params = anchor.search.substring(1).split('&');
                    for (var index = 0; index < params.length; index++) {
                        var pair = params[index].split('=');
                        if (pair[0] === key) {
                            try {
                                return decodeURIComponent(pair[1] || '');
                            } catch (error) {
                                return pair[1] || '';
                            }
                        }
                    }
                }

                return '';
            }

            function normalizeSavedViewList(list) {
                if (!Array.isArray(list)) {
                    return [];
                }

                return list.reduce(function(accumulator, item) {
                    if (!item || typeof item.id !== 'string') {
                        return accumulator;
                    }

                    var normalized = {
                        id: item.id,
                        name: item.name || '',
                        summary: item.summary || '',
                        updated_human: item.updated_human || '',
                        filters: item.filters && typeof item.filters === 'object' ? item.filters : {},
                        is_default: !!item.is_default
                    };

                    accumulator.push(normalized);
                    return accumulator;
                }, []);
            }

            function getSavedViewElements() {
                var $panel = $form.find('[data-blc-saved-views-panel]');
                if (!$panel.length) {
                    return null;
                }

                return {
                    panel: $panel,
                    select: $panel.find('[data-blc-saved-views-select]'),
                    apply: $panel.find('[data-blc-saved-views-apply]'),
                    deleteButton: $panel.find('[data-blc-saved-views-delete]'),
                    save: $panel.find('[data-blc-saved-views-save]'),
                    name: $panel.find('[data-blc-saved-views-name]'),
                    meta: $panel.find('[data-blc-saved-views-meta]'),
                    defaultToggle: $panel.find('[data-blc-saved-views-default]')
                };
            }

            function updateSavedViewsButtons() {
                if (!savedViewElements) {
                    return;
                }

                var hasSelection = !!(savedViewElements.select && savedViewElements.select.val());

                if (savedViewElements.apply && savedViewElements.apply.length) {
                    savedViewElements.apply.prop('disabled', !hasSelection);
                }

                if (savedViewElements.deleteButton && savedViewElements.deleteButton.length) {
                    savedViewElements.deleteButton.prop('disabled', !hasSelection);
                }
            }

            function updateSavedViewsMeta(viewId) {
                if (!savedViewElements || !savedViewElements.meta || !savedViewElements.meta.length) {
                    return;
                }

                var view = findSavedView(viewId);

                if (!view) {
                    savedViewElements.meta.text('').attr('hidden', 'hidden');
                    return;
                }

                var metaParts = [];
                if (view.is_default) {
                    var defaultBadge = messages.savedViewDefaultBadge || defaultMessages.savedViewDefaultBadge || '';
                    if (defaultBadge) {
                        metaParts.push(defaultBadge);
                    }
                }
                if (view.summary) {
                    metaParts.push(view.summary);
                }
                if (view.updated_human) {
                    metaParts.push(view.updated_human);
                }

                var metaText = metaParts.join(' · ');
                if (metaText) {
                    savedViewElements.meta.text(metaText).removeAttr('hidden');
                } else {
                    savedViewElements.meta.text('').attr('hidden', 'hidden');
                }
            }

            function updateDefaultToggle(viewId) {
                if (!savedViewElements || !savedViewElements.defaultToggle || !savedViewElements.defaultToggle.length) {
                    return;
                }

                var targetId = typeof viewId === 'string' ? viewId : '';
                if (!targetId && savedViewElements.select && savedViewElements.select.length) {
                    targetId = savedViewElements.select.val();
                }

                var shouldCheck = false;
                if (targetId) {
                    var targetView = findSavedView(targetId);
                    if (targetView) {
                        shouldCheck = !!targetView.is_default;
                    }
                }

                savedViewElements.defaultToggle.prop('checked', shouldCheck);

                var $wrapper = savedViewElements.defaultToggle.closest('.blc-saved-views__toggle');
                if ($wrapper && $wrapper.length) {
                    $wrapper.toggleClass('is-active', shouldCheck);
                }
            }

            function findSavedView(viewId) {
                if (!viewId) {
                    return null;
                }

                for (var index = 0; index < savedViews.length; index++) {
                    var candidate = savedViews[index];
                    if (candidate && candidate.id === viewId) {
                        return candidate;
                    }
                }

                return null;
            }

            function getDefaultSavedView() {
                for (var index = 0; index < savedViews.length; index++) {
                    var candidate = savedViews[index];
                    if (candidate && candidate.is_default) {
                        return candidate;
                    }
                }

                return null;
            }

            function isBaselineFilters(filters) {
                if (!filters || typeof filters !== 'object') {
                    return true;
                }

                var linkType = filters.link_type || 'all';
                var postType = filters.post_type || '';
                var searchTerm = filters.s || '';
                var orderby = filters.orderby || '';
                var order = (filters.order || '').toLowerCase();

                if (order !== 'asc' && order !== 'desc') {
                    order = 'desc';
                }

                return linkType === 'all' && postType === '' && searchTerm === '' && orderby === '' && order === 'desc';
            }

            function isBaselineState(state) {
                if (!state || typeof state !== 'object') {
                    return true;
                }

                var view = typeof state.view === 'string' ? state.view : 'all';
                var postType = typeof state.post_type === 'string' ? state.post_type : '';
                var search = typeof state.search === 'string' ? state.search : '';
                var sorting = state.sorting && typeof state.sorting === 'object' ? state.sorting : {};
                var orderby = typeof sorting.orderby === 'string' ? sorting.orderby : '';
                var order = typeof sorting.order === 'string' ? sorting.order.toLowerCase() : '';

                if (order !== 'asc' && order !== 'desc') {
                    order = 'desc';
                }

                return view === 'all' && postType === '' && search === '' && orderby === '' && order === 'desc';
            }

            function hasExplicitFilterQuery() {
                if (typeof window === 'undefined' || !window.location || typeof window.location.search !== 'string') {
                    return false;
                }

                return /[?&](link_type|post_type|s|orderby|order)=/.test(window.location.search);
            }

            function maybeApplyDefaultSavedView() {
                var defaultView = getDefaultSavedView();
                if (!defaultView || !defaultView.id) {
                    return;
                }

                if (hasExplicitFilterQuery()) {
                    return;
                }

                if (!isBaselineState(initialState)) {
                    return;
                }

                if (savedViewElements && savedViewElements.select && savedViewElements.select.length) {
                    savedViewElements.select.val(defaultView.id);
                }
                updateSavedViewsMeta(defaultView.id);
                updateDefaultToggle(defaultView.id);
                updateSavedViewsButtons();

                if (!isBaselineFilters(defaultView.filters || {})) {
                    applySavedView(defaultView.id, { silent: true });
                }
            }

            function renderSavedViews(selectedId) {
                if (!savedViewElements || !savedViewElements.select || !savedViewElements.select.length) {
                    return;
                }

                var valueToSelect = typeof selectedId === 'string' ? selectedId : savedViewElements.select.val();
                savedViewElements.select.empty();

                var placeholder = messages.savedViewPlaceholder || defaultMessages.savedViewPlaceholder || 'Sélectionnez une vue…';
                $('<option>', { value: '' }).text(placeholder).appendTo(savedViewElements.select);

                savedViews.forEach(function(view) {
                    if (!view || !view.id) {
                        return;
                    }

                    var optionLabel = view.name || '';
                    if (view.is_default) {
                        optionLabel += messages.savedViewDefaultSuffix || defaultMessages.savedViewDefaultSuffix || ' (par défaut)';
                    }

                    $('<option>', { value: view.id, 'data-default': view.is_default ? '1' : '0' }).text(optionLabel).appendTo(savedViewElements.select);
                });

                if (valueToSelect && savedViewElements.select.find('option[value="' + valueToSelect + '"]').length) {
                    savedViewElements.select.val(valueToSelect);
                } else {
                    savedViewElements.select.val('');
                }

                var activeSelection = savedViewElements.select.val();
                updateSavedViewsMeta(activeSelection);
                updateDefaultToggle(activeSelection);
                updateSavedViewsButtons();
            }

            function buildCurrentFilters() {
                var params = collectParams();
                var orderValue = (params.order || '').toLowerCase();
                if (orderValue !== 'asc' && orderValue !== 'desc') {
                    orderValue = 'desc';
                }
                return {
                    link_type: params.link_type || 'all',
                    post_type: params.post_type || '',
                    s: params.s || '',
                    orderby: params.orderby || '',
                    order: orderValue
                };
            }

            function applySavedView(viewId, options) {
                options = options || {};
                var view = findSavedView(viewId);
                if (!view) {
                    return;
                }

                var filters = view.filters || {};
                var state = {
                    view: filters.link_type || 'all',
                    post_type: filters.post_type || '',
                    search: filters.s || '',
                    sorting: {
                        orderby: filters.orderby || '',
                        order: filters.order || ''
                    },
                    pagination: { current: 1 }
                };

                syncState(state);
                updateActiveCards(state.view || 'all');

                var params = collectParams();
                params.link_type = filters.link_type || 'all';
                params.post_type = filters.post_type || '';
                params.s = filters.s || '';
                params.orderby = filters.orderby || '';
                params.order = filters.order || '';
                params.paged = 1;

                requestTable(params, { prefetch: false });

                var appliedMessage = formatTemplate(messages.savedViewApplied || '', view.name || '');
                if (appliedMessage && !options.silent) {
                    if (toast && typeof toast.success === 'function') {
                        toast.success(appliedMessage);
                    }
                    accessibility.speak(appliedMessage, 'polite');
                }
            }

            function handleSavedViewSave() {
                if (!savedViewElements) {
                    return;
                }

                var nameValue = '';
                if (savedViewElements.name && savedViewElements.name.length) {
                    nameValue = savedViewElements.name.val();
                }
                nameValue = nameValue ? nameValue.trim() : '';

                if (!nameValue) {
                    var requiredMessage = messages.savedViewNameRequired || messages.savedViewGenericError || messages.genericError || '';
                    if (requiredMessage) {
                        if (toast && typeof toast.warning === 'function') {
                            toast.warning(requiredMessage);
                        }
                        accessibility.speak(requiredMessage, 'assertive');
                    }
                    if (savedViewElements.name && typeof savedViewElements.name.trigger === 'function') {
                        savedViewElements.name.trigger('focus');
                    }
                    return;
                }

                var filters = buildCurrentFilters();
                var shouldSetDefault = savedViewElements.defaultToggle && savedViewElements.defaultToggle.length
                    ? savedViewElements.defaultToggle.is(':checked')
                    : false;

                if (savedViewElements.save && savedViewElements.save.length) {
                    savedViewElements.save.prop('disabled', true);
                }

                $.ajax({
                    url: endpoint,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'blc_save_links_view',
                        _ajax_nonce: viewsNonce || nonce,
                        name: nameValue,
                        filters: filters,
                        is_default: shouldSetDefault ? '1' : '0'
                    }
                }).done(function(response) {
                    if (!response || !response.success || !response.data) {
                        throw new Error('invalid_response');
                    }

                    var payload = response.data;

                    if (Array.isArray(payload.views)) {
                        savedViews = normalizeSavedViewList(payload.views);
                    }

                    if (typeof payload.limit !== 'undefined') {
                        var limitCandidate = parseInt(payload.limit, 10);
                        if (Number.isFinite(limitCandidate)) {
                            viewsLimit = limitCandidate;
                        }
                    }

                    var selectedId = payload.view && payload.view.id ? payload.view.id : '';
                    renderSavedViews(selectedId);
                    updateSavedViewsMeta(selectedId);
                    updateDefaultToggle(selectedId);

                    if (savedViewElements.name && savedViewElements.name.length) {
                        savedViewElements.name.val('');
                    }

                    var activeView = selectedId ? findSavedView(selectedId) : null;
                    var status = payload.status || '';
                    var template = messages.savedViewCreated || '';
                    if (status === 'updated') {
                        template = messages.savedViewUpdated || template;
                    } else if (status === 'created') {
                        template = messages.savedViewCreated || template;
                    }
                    var successMessage = formatTemplate(template, activeView ? activeView.name || '' : nameValue);
                    var defaultStatus = payload.default_status || '';
                    var defaultMessage = '';
                    if (defaultStatus === 'assigned') {
                        defaultMessage = formatTemplate(messages.savedViewDefaultAssigned || '', activeView ? activeView.name || '' : nameValue);
                    } else if (defaultStatus === 'removed') {
                        defaultMessage = formatTemplate(messages.savedViewDefaultRemoved || '', activeView ? activeView.name || '' : nameValue);
                    }

                    if (defaultMessage) {
                        successMessage = successMessage ? successMessage + ' ' + defaultMessage : defaultMessage;
                    }

                    if (successMessage) {
                        if (toast && typeof toast.success === 'function') {
                            toast.success(successMessage);
                        }
                        accessibility.speak(successMessage, 'polite');
                    }
                }).fail(function(xhr) {
                    var fallbackMessage = messages.savedViewGenericError || messages.genericError || '';
                    var errorData = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;

                    if (errorData) {
                        if (errorData.code === 'invalid_name') {
                            fallbackMessage = messages.savedViewNameRequired || fallbackMessage;
                        } else if (errorData.code === 'limit_reached') {
                            var limitValue = errorData.limit || viewsLimit;
                            if (messages.savedViewLimitReached) {
                                fallbackMessage = formatTemplate(messages.savedViewLimitReached, limitValue);
                            }
                        } else if (errorData.message) {
                            fallbackMessage = errorData.message;
                        }
                    }

                    if (fallbackMessage) {
                        if (toast && typeof toast.error === 'function') {
                            toast.error(fallbackMessage);
                        }
                        accessibility.speak(fallbackMessage, 'assertive');
                    }
                }).always(function() {
                    if (savedViewElements.save && savedViewElements.save.length) {
                        savedViewElements.save.prop('disabled', false);
                    }
                    updateSavedViewsButtons();
                });
            }

            function handleSavedViewDelete() {
                if (!savedViewElements || !savedViewElements.select || !savedViewElements.select.length) {
                    return;
                }

                var selectedId = savedViewElements.select.val();
                if (!selectedId) {
                    return;
                }

                var selectedView = findSavedView(selectedId);
                var confirmTemplate = messages.savedViewDeleteConfirm || '';
                var confirmMessage = confirmTemplate ? formatTemplate(confirmTemplate, selectedView ? selectedView.name || '' : '') : '';
                if (confirmMessage && !window.confirm(confirmMessage)) {
                    return;
                }

                if (savedViewElements.deleteButton && savedViewElements.deleteButton.length) {
                    savedViewElements.deleteButton.prop('disabled', true);
                }

                $.ajax({
                    url: endpoint,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'blc_delete_links_view',
                        _ajax_nonce: viewsNonce || nonce,
                        id: selectedId
                    }
                }).done(function(response) {
                    if (!response || !response.success || !response.data) {
                        throw new Error('invalid_response');
                    }

                    var payload = response.data;
                    if (Array.isArray(payload.views)) {
                        savedViews = normalizeSavedViewList(payload.views);
                    }

                    renderSavedViews('');
                    updateDefaultToggle('');

                    var deletedMessage = formatTemplate(messages.savedViewDeleted || '', selectedView ? selectedView.name || '' : '');
                    if (deletedMessage) {
                        if (toast && typeof toast.success === 'function') {
                            toast.success(deletedMessage);
                        }
                        accessibility.speak(deletedMessage, 'polite');
                    }
                }).fail(function(xhr) {
                    var errorMessage = messages.savedViewGenericError || messages.genericError || '';
                    var errorData = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null;

                    if (errorData && errorData.message) {
                        errorMessage = errorData.message;
                    }

                    if (errorMessage) {
                        if (toast && typeof toast.error === 'function') {
                            toast.error(errorMessage);
                        }
                        accessibility.speak(errorMessage, 'assertive');
                    }
                }).always(function() {
                    if (savedViewElements.deleteButton && savedViewElements.deleteButton.length) {
                        savedViewElements.deleteButton.prop('disabled', false);
                    }
                    updateSavedViewsButtons();
                });
            }

            function initializeSavedViews() {
                if (!savedViewElements) {
                    return;
                }

                renderSavedViews('');

                if (savedViewElements.select && savedViewElements.select.length) {
                    savedViewElements.select.on('change', function() {
                        var value = $(this).val();
                        updateSavedViewsMeta(value);
                        updateDefaultToggle(value);
                        updateSavedViewsButtons();
                    });

                    savedViewElements.select.on('dblclick', function() {
                        var selected = $(this).val();
                        if (selected) {
                            applySavedView(selected);
                        }
                    });
                }

                if (savedViewElements.apply && savedViewElements.apply.length) {
                    savedViewElements.apply.on('click', function(event) {
                        event.preventDefault();
                        var selected = savedViewElements.select ? savedViewElements.select.val() : '';
                        if (!selected) {
                            return;
                        }
                        applySavedView(selected);
                    });
                }

                if (savedViewElements.save && savedViewElements.save.length) {
                    savedViewElements.save.on('click', function(event) {
                        event.preventDefault();
                        handleSavedViewSave();
                    });
                }

                if (savedViewElements.deleteButton && savedViewElements.deleteButton.length) {
                    savedViewElements.deleteButton.on('click', function(event) {
                        event.preventDefault();
                        handleSavedViewDelete();
                    });
                }

                if (savedViewElements.defaultToggle && savedViewElements.defaultToggle.length) {
                    savedViewElements.defaultToggle.on('change', function() {
                        var $wrapper = $(this).closest('.blc-saved-views__toggle');
                        if ($wrapper && $wrapper.length) {
                            $wrapper.toggleClass('is-active', $(this).is(':checked'));
                        }
                    });
                }

                updateSavedViewsButtons();
            }

            initializeSavedViews();

            if (initialState && typeof initialState === 'object') {
                syncState(initialState);
                updateActiveCards(initialState.view || 'all');
                schedulePrefetch(initialState);
            }

            maybeApplyDefaultSavedView();

            $form.on('submit', function(event) {
                if ($form.data('blcBulkConfirmed')) {
                    return;
                }

                var bulkAction = typeof getSelectedBulkAction === 'function'
                    ? getSelectedBulkAction($form)
                    : null;

                if (bulkAction) {
                    return;
                }

                event.preventDefault();
                var params = collectParams();
                params.paged = 1;
                requestTable(params, { prefetch: false });
            });

            $form.on('change', 'select[name="post_type"]', function() {
                var params = collectParams();
                params.post_type = $(this).val();
                params.paged = 1;
                requestTable(params, { prefetch: false });
            });

            $form.on('click', '.subsubsub a', function(event) {
                event.preventDefault();
                var url = $(this).attr('href') || '';
                var linkType = extractParam(url, 'link_type') || 'all';
                var params = collectParams();
                params.link_type = linkType;
                params.paged = 1;
                requestTable(params, { prefetch: false });
            });

            $form.on('click', '.tablenav-pages a', function(event) {
                event.preventDefault();
                var url = $(this).attr('href') || '';
                var paged = parseInt(extractParam(url, 'paged'), 10);
                if (!Number.isFinite(paged) || paged < 1) {
                    paged = 1;
                }
                var params = collectParams();
                params.paged = paged;
                requestTable(params, { prefetch: false });
            });

            $form.on('keydown', '.tablenav .current-page', function(event) {
                if (event.key !== 'Enter') {
                    return;
                }
                event.preventDefault();
                var value = parseInt($(this).val(), 10);
                if (!Number.isFinite(value) || value < 1) {
                    value = 1;
                }
                var params = collectParams();
                params.paged = value;
                requestTable(params, { prefetch: false });
            });

            $form.on('click', '.tablenav .button', function(event) {
                var $currentPage = $form.find('.tablenav .current-page');
                if (!$currentPage.length) {
                    return;
                }
                event.preventDefault();
                var value = parseInt($currentPage.val(), 10);
                if (!Number.isFinite(value) || value < 1) {
                    value = 1;
                }
                var params = collectParams();
                params.paged = value;
                requestTable(params, { prefetch: false });
            });

            $form.on('click', '.search-box input[type="submit"]', function(event) {
                event.preventDefault();
                var params = collectParams();
                params.paged = 1;
                requestTable(params, { prefetch: false });
            });

            $form.on('keydown', '.search-box input[type="search"]', function(event) {
                if (event.key !== 'Enter') {
                    return;
                }
                event.preventDefault();
                var params = collectParams();
                params.paged = 1;
                requestTable(params, { prefetch: false });
            });
        });
    }

    initFieldHelp();
    initAdvancedSettings();
    initSettingsModeToggle();
    initLinksTableAjax();

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
