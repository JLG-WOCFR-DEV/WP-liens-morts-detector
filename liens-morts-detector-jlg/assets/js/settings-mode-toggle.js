(function(root, factory) {
    var create = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = create;
    } else {
        root.blcSettingsModeToggleFactory = create;
    }
}(typeof self !== 'undefined' ? self : this, function() {
    function noop() {}

    return function createSettingsModeToggle($, options) {
        if (!$ || !$.fn) {
            return function() {
                return false;
            };
        }

        var settings = options || {};
        var initAdvancedSettings = (typeof settings.initAdvancedSettings === 'function') ? settings.initAdvancedSettings : noop;
        var toast = settings.toast || null;
        var accessibility = settings.accessibility || {};
        var speak = (typeof accessibility.speak === 'function') ? accessibility.speak : noop;

        return function initSettingsModeToggle() {
            var config = window.blcAdminSettings || {};
            var ajaxConfig = config.ajax || {};
            var i18n = config.i18n || {};
            var currentMode = (config.mode === 'advanced') ? 'advanced' : 'simple';
            var $toggle = $('[data-blc-settings-mode-toggle]');

            if (!$toggle.length) {
                return false;
            }

            var $control = $toggle.find('[data-blc-settings-mode-control]');
            if (!$control.length) {
                return false;
            }

            var $state = $toggle.find('[data-blc-settings-mode-state]');
            var $action = $control.find('[data-blc-settings-mode-action]');
            var $placeholder = $('[data-blc-settings-advanced-placeholder]');
            var $template = $('#blc-settings-advanced-template');
            var templateHtml = '';

            if ($template.length) {
                var templateElement = $template.get(0);

                if (templateElement && typeof templateElement.innerHTML === 'string') {
                    templateHtml = templateElement.innerHTML;
                }

                if (!templateHtml && templateElement && templateElement.content) {
                    var container = document.createElement('div');
                    container.appendChild(templateElement.content.cloneNode(true));
                    templateHtml = container.innerHTML;
                }

                if (!templateHtml) {
                    templateHtml = $template.html();
                }

                if (typeof templateHtml === 'string') {
                    templateHtml = templateHtml.trim();
                }
            }

            function ensureAdvancedMarkup() {
                if (!templateHtml || !$placeholder.length) {
                    return;
                }

                if (!$placeholder.children().length) {
                    $placeholder.html(templateHtml);
                }

                initAdvancedSettings($placeholder);
            }

            function applyMode(mode) {
                var isAdvanced = mode === 'advanced';
                currentMode = isAdvanced ? 'advanced' : 'simple';

                $toggle.attr('data-current-mode', currentMode);
                $control.attr('aria-checked', isAdvanced ? 'true' : 'false');

                if ($state.length) {
                    var statusText = isAdvanced
                        ? (i18n.statusAdvanced || i18n.modeAdvanced || $state.text())
                        : (i18n.statusSimple || i18n.modeSimple || $state.text());
                    $state.text(statusText);
                }

                if ($action.length) {
                    $action.text(isAdvanced ? (i18n.switchToSimple || $action.text()) : (i18n.switchToAdvanced || $action.text()));
                }

                if (isAdvanced) {
                    ensureAdvancedMarkup();
                } else if ($placeholder.length) {
                    $placeholder.empty();
                }
            }

            var isPending = false;

            function setPending(state) {
                isPending = !!state;
                $control.prop('disabled', isPending);
                $toggle.toggleClass('is-loading', isPending);
            }

            function getAnnouncement(mode, response) {
                if (response && response.data && response.data.announcement) {
                    return response.data.announcement;
                }

                return mode === 'advanced'
                    ? (i18n.announcementAdvanced || '')
                    : (i18n.announcementSimple || '');
            }

            function persistMode(mode) {
                var url = ajaxConfig.url || window.ajaxurl || '';
                var hasAjax = ajaxConfig.action && ajaxConfig.nonce && url && typeof $.post === 'function';

                if (!hasAjax) {
                    if (typeof Promise === 'function') {
                        return Promise.resolve({ success: true, data: { mode: mode } });
                    }

                    if (typeof $.Deferred === 'function') {
                        return $.Deferred().resolve({ success: true, data: { mode: mode } }).promise();
                    }

                    return null;
                }

                return new Promise(function(resolve, reject) {
                    var jqRequest = $.post(url, {
                        action: ajaxConfig.action,
                        mode: mode,
                        _wpnonce: ajaxConfig.nonce
                    });

                    if (jqRequest && typeof jqRequest.done === 'function') {
                        jqRequest.done(resolve).fail(reject);
                    } else if (jqRequest && typeof jqRequest.then === 'function') {
                        jqRequest.then(resolve).catch(reject);
                    } else {
                        resolve({ success: true, data: { mode: mode } });
                    }
                });
            }

            applyMode(currentMode);

            $control.off('click.blcSettingsMode');
            $control.on('click.blcSettingsMode', function(event) {
                event.preventDefault();

                if (isPending) {
                    return;
                }

                var previousMode = currentMode;
                var nextMode = (currentMode === 'advanced') ? 'simple' : 'advanced';
                setPending(true);

                applyMode(nextMode);

                var request = persistMode(nextMode);

                function handleSuccess(response) {
                    var savedMode = nextMode;

                    if (response) {
                        if (response.success !== false && response.data && response.data.mode) {
                            savedMode = response.data.mode;
                        } else if (response.mode) {
                            savedMode = response.mode;
                        }
                    }

                    applyMode(savedMode);

                    var announcement = getAnnouncement(savedMode, response);
                    if (announcement) {
                        speak(announcement, 'polite');
                    }
                }

                function handleFailure() {
                    applyMode(previousMode);
                    var message = i18n.error || '';

                    if (toast && typeof toast.warning === 'function' && message) {
                        toast.warning(message);
                    }

                    if (message) {
                        speak(message, 'assertive');
                    }
                }

                if (request && typeof request.then === 'function') {
                    request.then(function(response) {
                        handleSuccess(response);
                    }).catch(function() {
                        handleFailure();
                    }).finally(function() {
                        setPending(false);
                    });

                    return;
                }

                if (request && typeof request.done === 'function') {
                    request.done(function(response) {
                        handleSuccess(response);
                    }).fail(function() {
                        handleFailure();
                    }).always(function() {
                        setPending(false);
                    });

                    return;
                }

                handleSuccess({ data: { mode: nextMode } });
                setPending(false);
            });

            return true;
        };
    };
}));
