const path = require('path');
const $ = require('jquery');

describe('blc-admin-scripts accessibility helper', () => {
    let originalReady;
    let originalFadeOut;
    let originalVisible;
    let originalHidden;

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

        originalReady = undefined;
        originalFadeOut = undefined;
        originalVisible = undefined;
        originalHidden = undefined;
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
});
