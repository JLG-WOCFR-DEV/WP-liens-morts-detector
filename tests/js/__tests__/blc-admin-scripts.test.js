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
