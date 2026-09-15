(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.blcIsIframedEditorContext = api.blcIsIframedEditorContext;
        root.blcEditorIframeGuard = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    function hasClass(win, className) {
        try {
            var body = win.document && win.document.body;
            return !!(body && body.classList && body.classList.contains(className));
        } catch (e) {
            return false;
        }
    }

    function queryFlag(win, name) {
        try {
            if (!win.location || typeof win.location.search !== 'string') {
                return '';
            }
            return new URLSearchParams(win.location.search).get(name) || '';
        } catch (e) {
            return '';
        }
    }

    function blcIsIframedEditorContext(win) {
        win = win || (typeof window !== 'undefined' ? window : null);
        if (!win) {
            return false;
        }

        if (win.BLC_IS_EDITOR) {
            return true;
        }

        var canvas = queryFlag(win, 'canvas');
        var context = queryFlag(win, 'context');
        if (canvas === 'edit' || context === 'edit') {
            return true;
        }

        if (hasClass(win, 'block-editor-iframe__body') || hasClass(win, 'block-editor-page')) {
            return true;
        }

        try {
            var frame = win.frameElement;
            if (frame) {
                var frameName = typeof frame.getAttribute === 'function' ? (frame.getAttribute('name') || '') : '';
                var frameClass = frame.className || '';
                if (frameName === 'editor-canvas' || String(frameClass).indexOf('editor-canvas__iframe') !== -1) {
                    return true;
                }
            }
        } catch (e) {
            // Cross-origin frame access can throw; treat as not an editor canvas.
        }

        return false;
    }

    return {
        blcIsIframedEditorContext: blcIsIframedEditorContext
    };
}));
