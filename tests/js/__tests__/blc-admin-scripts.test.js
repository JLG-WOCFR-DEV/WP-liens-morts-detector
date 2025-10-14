const path = require('path');
const $ = require('jquery');

describe('blc-admin-scripts accessibility helper', () => {
    let originalReady;
    let originalFadeOut;
    let originalVisible;
    let originalHidden;
    let originalRequestAnimationFrame;

    beforeEach(() => {
        jest.resetModules();
        document.body.innerHTML = `
            <div>
                <table>
                    <tbody id="the-list">
                        <tr id="row-1">
                            <td><button type="button" class="blc-edit-link">Modifier</button></td>
                        </tr>
                        <tr id="row-2">
                            <td><button type="button" class="blc-edit-link">Modifier</button></td>
                        </tr>
                    </tbody>
                </table>
                <div class="tablenav">
                    <button type="button" id="post-query-submit">Filtrer</button>
                </div>
            </div>
        `;

        originalReady = $.fn.ready;
        $.fn.ready = function(fn) {
            fn.call(document, $);
            return this;
        };

        originalVisible = $.expr.pseudos.visible;
        originalHidden = $.expr.pseudos.hidden;
        $.expr.pseudos.visible = () => true;
        $.expr.pseudos.hidden = () => false;

        originalFadeOut = $.fn.fadeOut;
        $.fn.fadeOut = function(_duration, callback) {
            if (typeof callback === 'function') {
                callback.call(this);
            }
            return this;
        };

        originalRequestAnimationFrame = window.requestAnimationFrame;
        window.requestAnimationFrame = (callback) => {
            if (typeof callback === 'function') {
                callback();
            }
        };

        window.blcAdminMessages = {
            successAnnouncement: 'Action effectuée avec succès.'
        };

        window.wp = {
            a11y: {
                speak: jest.fn()
            }
        };

        global.jQuery = $;
        global.$ = $;
        window.jQuery = $;
        window.$ = $;

        require(path.resolve(__dirname, '../../..', 'liens-morts-detector-jlg/assets/js/blc-admin-scripts.js'));
    });

    afterEach(() => {
        delete window.blcAdminMessages;
        delete window.blcAdmin;
        delete window.wp;
        delete global.jQuery;
        delete global.$;
        delete window.jQuery;
        delete window.$;

        if (originalReady) {
            $.fn.ready = originalReady;
        } else {
            delete $.fn.ready;
        }

        if (originalFadeOut) {
            $.fn.fadeOut = originalFadeOut;
        } else {
            delete $.fn.fadeOut;
        }

        if (originalVisible) {
            $.expr.pseudos.visible = originalVisible;
        } else {
            delete $.expr.pseudos.visible;
        }

        if (originalHidden) {
            $.expr.pseudos.hidden = originalHidden;
        } else {
            delete $.expr.pseudos.hidden;
        }

        jest.resetModules();

        if (originalRequestAnimationFrame) {
            window.requestAnimationFrame = originalRequestAnimationFrame;
        } else {
            delete window.requestAnimationFrame;
        }

        originalReady = undefined;
        originalFadeOut = undefined;
        originalVisible = undefined;
        originalHidden = undefined;
        originalRequestAnimationFrame = undefined;
    });

    it('announces success through wp.a11y.speak for successful responses', () => {
        const row = $('#row-1');
        const helpers = {
            close: jest.fn()
        };

        window.blcAdmin.listActions.handleSuccessfulResponse(
            {
                success: true,
                data: {
                    announcement: 'La ligne a été mise à jour.'
                }
            },
            row,
            helpers
        );

        expect(window.wp.a11y.speak).toHaveBeenCalledWith('La ligne a été mise à jour.', 'polite');
    });

    it('restores focus to the next available action when no helper is provided', () => {
        const firstButton = $('#row-1 .blc-edit-link')[0];
        const secondButton = $('#row-2 .blc-edit-link')[0];

        firstButton.focus();

        window.blcAdmin.listActions.handleSuccessfulResponse(
            {
                success: true,
                data: {}
            },
            $('#row-1')
        );

        expect(document.activeElement).toBe(secondButton);
    });

    it('does not override focus when helpers already moved it', () => {
        const firstButton = $('#row-1 .blc-edit-link')[0];
        const secondButton = $('#row-2 .blc-edit-link')[0];
        const helpers = {
            close: jest.fn(() => {
                secondButton.focus();
            })
        };

        firstButton.focus();

        window.blcAdmin.listActions.handleSuccessfulResponse(
            {
                success: true,
                data: {}
            },
            $('#row-1'),
            helpers
        );

        expect(helpers.close).toHaveBeenCalledWith(secondButton);
        expect(document.activeElement).toBe(secondButton);
    });
});

describe('blc-admin-scripts test notification button', () => {
    let originalReady;
    let postSpy;
    let deferred;

    beforeEach(() => {
        jest.resetModules();
        document.body.innerHTML = `
            <div>
                <button type="button" id="blc-send-test-email">Envoyer</button>
                <span id="blc-test-email-spinner"></span>
                <div id="blc-test-email-feedback"></div>
                <textarea id="blc_notification_message_template"></textarea>
                <input type="text" id="blc_notification_recipients" value="admin@example.com" />
                <input type="checkbox" id="blc_notification_links_enabled" checked />
                <input type="checkbox" id="blc_notification_images_enabled" />
                <input type="url" id="blc_notification_webhook_url" value="" />
                <select id="blc_notification_webhook_channel">
                    <option value="disabled" selected>Disabled</option>
                </select>
                <label><input type="checkbox" name="blc_notification_status_filters[]" value="status_404_410" checked />404</label>
                <label><input type="checkbox" name="blc_notification_status_filters[]" value="status_5xx" />5xx</label>
            </div>
        `;

        originalReady = $.fn.ready;
        $.fn.ready = function(fn) {
            fn.call(document, $);
            return this;
        };

        window.blcAdminMessages = {};
        window.blcAdminNotifications = {
            action: 'blc_send_test_email',
            nonce: 'abc123',
            ajaxUrl: '/ajax-endpoint',
            missingRecipientsText: 'missing',
            missingChannelText: 'channel',
            errorText: 'error',
            sendingText: 'sending',
            successText: 'success',
            partialSuccessText: 'partial'
        };

        window.wp = {
            a11y: {
                speak: jest.fn()
            }
        };

        global.jQuery = $;
        global.$ = $;
        window.jQuery = $;
        window.$ = $;

        deferred = $.Deferred();
        postSpy = jest.spyOn($, 'post').mockImplementation(() => deferred);

        require(path.resolve(__dirname, '../../..', 'liens-morts-detector-jlg/assets/js/blc-admin-scripts.js'));
    });

    afterEach(() => {
        if (postSpy) {
            postSpy.mockRestore();
            postSpy = null;
        }

        delete window.blcAdminNotifications;
        delete window.blcAdminMessages;
        delete window.wp;
        delete global.jQuery;
        delete global.$;
        delete window.jQuery;
        delete window.$;

        if (originalReady) {
            $.fn.ready = originalReady;
        } else {
            delete $.fn.ready;
        }

        jest.resetModules();
    });

    it('includes the selected status filters in the AJAX payload', () => {
        $('#blc-send-test-email').trigger('click');

        expect(postSpy).toHaveBeenCalledTimes(1);
        const payload = postSpy.mock.calls[0][1];
        expect(payload.status_filters).toEqual(['status_404_410']);

        deferred.resolve({ success: true, data: { message: 'ok' } });
    });
});

describe('settings mode toggle', () => {
    let postDeferred;
    let originalPost;
    let toggleCountBefore;
    let controlCountBefore;
    let createSettingsModeToggle;
    let initSettingsModeToggle;
    let initAdvancedSettingsSpy;
    let toast;

    beforeEach(() => {
        jest.resetModules();
        document.body.innerHTML = `
            <div class="blc-settings-mode" data-blc-settings-mode-toggle data-current-mode="simple">
                <div class="blc-settings-mode__intro">
                    <h2 id="blc-settings-mode-title">Niveau de configuration</h2>
                    <p id="blc-settings-mode-description">Description</p>
                </div>
                <div class="blc-settings-mode__control">
                    <span id="blc-settings-mode-state" data-blc-settings-mode-state>Mode simple activé — seuls les réglages essentiels sont visibles.</span>
                    <button type="button" class="button blc-settings-mode__switch" role="switch" aria-checked="false" aria-labelledby="blc-settings-mode-title blc-settings-mode-state" aria-describedby="blc-settings-mode-description" data-blc-settings-mode-control>
                        <span data-blc-settings-mode-action>Passer en mode avancé</span>
                    </button>
                </div>
            </div>
            <div class="blc-settings-groups" data-blc-settings-groups>
                <div class="blc-settings-groups__advanced" data-blc-settings-advanced-placeholder></div>
            </div>
            <script type="text/template" id="blc-settings-advanced-template">
                <details class="blc-settings-group blc-settings-group--collapsible">
                    <summary class="blc-settings-group__summary">
                        <span class="blc-settings-group__title">Réglages avancés</span>
                        <span class="blc-settings-group__description">Optimisez les performances.</span>
                    </summary>
                    <div class="blc-settings-group__content">
                        <div class="blc-settings-advanced">
                            <div class="blc-settings-advanced__tabs" role="tablist">
                                <button type="button" class="blc-settings-advanced__tab is-active" data-blc-target="demo" aria-selected="true" tabindex="0" role="tab">Démo</button>
                            </div>
                            <div class="blc-settings-advanced__panels">
                                <section class="blc-settings-advanced__panel is-active" data-blc-panel="demo"></section>
                            </div>
                        </div>
                    </div>
                </details>
            </script>
        `;

        toggleCountBefore = $('[data-blc-settings-mode-toggle]').length;
        controlCountBefore = $('[data-blc-settings-mode-control]').length;

        originalPost = $.post;
        postDeferred = $.Deferred();
        jest.spyOn($, 'post').mockReturnValue(postDeferred.promise());

        initAdvancedSettingsSpy = jest.fn();
        toast = { warning: jest.fn() };

        window.blcAdminMessages = {};
        window.blcAdminSettings = {
            mode: 'simple',
            ajax: {
                url: '/ajax',
                action: 'blc_update_settings_mode',
                nonce: '123'
            },
            i18n: {
                statusSimple: 'Mode simple activé — seuls les réglages essentiels sont visibles.',
                statusAdvanced: 'Mode avancé activé — toutes les sections sont affichées.',
                switchToAdvanced: 'Passer en mode avancé',
                switchToSimple: 'Revenir au mode simple',
                announcementSimple: 'Mode simple activé. Les réglages avancés sont masqués.',
                announcementAdvanced: 'Mode avancé activé. Les réglages supplémentaires sont visibles.',
                error: 'Impossible d’enregistrer votre préférence pour le moment.'
            }
        };

        window.wp = {
            a11y: {
                speak: jest.fn()
            }
        };

        window.ajaxurl = '/ajax';

        createSettingsModeToggle = require(path.resolve(
            __dirname,
            '../../..',
            'liens-morts-detector-jlg/assets/js/settings-mode-toggle.js'
        ));

        initSettingsModeToggle = createSettingsModeToggle($, {
            toast: toast,
            accessibility: { speak: window.wp.a11y.speak },
            initAdvancedSettings: initAdvancedSettingsSpy
        });

        expect(typeof initSettingsModeToggle).toBe('function');
        const initialized = initSettingsModeToggle();
        expect(initialized).toBe(true);
    });

    afterEach(() => {
        delete window.blcAdminMessages;
        delete window.blcAdminSettings;
        delete window.wp;
        delete window.ajaxurl;

        if ($.post.mockRestore) {
            $.post.mockRestore();
        } else if (originalPost) {
            $.post = originalPost;
        }

        jest.resetModules();
    });

    it('affiche les réglages avancés et annonce le changement après une bascule réussie', async () => {
        expect(toggleCountBefore).toBe(1);
        expect(controlCountBefore).toBe(1);
        const button = document.querySelector('[data-blc-settings-mode-control]');
        const placeholder = document.querySelector('[data-blc-settings-advanced-placeholder]');

        expect(button).not.toBeNull();
        expect(placeholder.children.length).toBe(0);

        $(button).trigger('click');

        expect(button.getAttribute('aria-checked')).toBe('true');
        expect($.post).toHaveBeenCalledTimes(1);
        expect($.post).toHaveBeenCalledWith('/ajax', {
            action: 'blc_update_settings_mode',
            mode: 'advanced',
            _wpnonce: '123'
        });

        postDeferred.resolve({ success: true, data: { mode: 'advanced', announcement: 'Mode avancé activé. Les réglages supplémentaires sont visibles.' } });

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(button.getAttribute('aria-checked')).toBe('true');
        expect(document.querySelector('[data-current-mode="advanced"]')).not.toBeNull();
        expect(placeholder.querySelector('.blc-settings-advanced')).not.toBeNull();
        expect(initAdvancedSettingsSpy).toHaveBeenCalledTimes(1);
        expect(window.wp.a11y.speak).toHaveBeenCalledWith('Mode avancé activé. Les réglages supplémentaires sont visibles.', 'polite');
    });

    it('annonce une erreur et restaure l’état précédent si la requête échoue', async () => {
        expect(toggleCountBefore).toBe(1);
        expect(controlCountBefore).toBe(1);
        const button = document.querySelector('[data-blc-settings-mode-control]');

        $(button).trigger('click');

        expect(button.getAttribute('aria-checked')).toBe('true');
        expect($.post).toHaveBeenCalledTimes(1);
        expect($.post).toHaveBeenCalledWith('/ajax', {
            action: 'blc_update_settings_mode',
            mode: 'advanced',
            _wpnonce: '123'
        });

        postDeferred.reject();

        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(button.getAttribute('aria-checked')).toBe('false');
        expect(window.wp.a11y.speak).toHaveBeenCalledWith('Impossible d’enregistrer votre préférence pour le moment.', 'assertive');
        expect(toast.warning).toHaveBeenCalledWith('Impossible d’enregistrer votre préférence pour le moment.');
    });
});
