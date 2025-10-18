(function (window, document) {
    'use strict';

    function normalizeIdentifier(value) {
        return String(value).replace(/[^a-zA-Z0-9_\-]+/g, '_');
    }

    function updateEmptyState(container) {
        if (!container) {
            return;
        }

        var rows = container.querySelectorAll('[data-blc-surveillance-row]');
        var emptyRow = container.querySelector('.no-items');

        if (!emptyRow) {
            return;
        }

        if (rows.length > 0) {
            emptyRow.style.display = 'none';
        } else {
            emptyRow.style.display = '';
        }
    }

    function prepareRow(fragment, type, index) {
        if (!fragment) {
            return;
        }

        var baseName = 'blc_surveillance_thresholds[' + type + '][' + index + ']';
        var fields = fragment.querySelectorAll('[name]');

        fields.forEach(function (field) {
            var originalName = field.getAttribute('name');
            if (typeof originalName !== 'string') {
                return;
            }

            var name = originalName.replace('__NAME__', baseName);
            field.setAttribute('name', name);

            var id = normalizeIdentifier(name);
            field.setAttribute('id', id);

            if (field.matches('[data-blc-apply-all]')) {
                field.dataset.fieldApplyAll = id;
            }
        });

        var labels = fragment.querySelectorAll('[data-blc-aria-label]');
        labels.forEach(function (label) {
            var fieldSlug = label.getAttribute('data-blc-aria-label');
            if (!fieldSlug) {
                return;
            }

            var selector = '[name="' + baseName + '[' + fieldSlug + ']"]';
            var target = fragment.querySelector(selector);
            if (target) {
                label.setAttribute('for', target.getAttribute('id'));
            }
        });

        var textareas = fragment.querySelectorAll('.blc-surveillance__term-input');
        textareas.forEach(function (textarea) {
            if (textarea.closest('[data-blc-surveillance-row]')) {
                var toggle = textarea.closest('[data-blc-surveillance-row]').querySelector('[data-blc-apply-all]');
                if (toggle) {
                    textarea.disabled = toggle.checked;
                }
            }
        });
    }

    function cloneTemplate(templateId, type, index) {
        var template = document.getElementById(templateId);
        if (!template) {
            return null;
        }

        var fragment = template.content ? template.content.cloneNode(true) : null;
        if (!fragment) {
            return null;
        }

        prepareRow(fragment, type, index);
        return fragment;
    }

    function init(context) {
        if (!context) {
            context = document;
        }

        var root = context.querySelector('[data-blc-surveillance-root]');
        if (!root) {
            return;
        }

        var globalContainer = root.querySelector('[data-blc-surveillance-global-list]');
        var taxonomyContainer = root.querySelector('[data-blc-surveillance-taxonomy-list]');

        var counters = {
            global: globalContainer ? globalContainer.querySelectorAll('[data-blc-surveillance-row]').length : 0,
            taxonomy: taxonomyContainer ? taxonomyContainer.querySelectorAll('[data-blc-surveillance-row]').length : 0,
        };

        updateEmptyState(globalContainer);
        updateEmptyState(taxonomyContainer);

        root.addEventListener('click', function (event) {
            var addGlobal = event.target.closest('[data-blc-add-global-threshold]');
            if (addGlobal) {
                event.preventDefault();
                var nextIndex = counters.global++;
                var fragment = cloneTemplate('blc-surveillance-template-global', 'global', nextIndex);
                if (fragment && globalContainer) {
                    globalContainer.appendChild(fragment);
                    updateEmptyState(globalContainer);
                }

                return;
            }

            var addTaxonomy = event.target.closest('[data-blc-add-taxonomy-threshold]');
            if (addTaxonomy) {
                event.preventDefault();
                var nextTaxonomyIndex = counters.taxonomy++;
                var fragmentTaxonomy = cloneTemplate('blc-surveillance-template-taxonomy', 'taxonomy', nextTaxonomyIndex);
                if (fragmentTaxonomy && taxonomyContainer) {
                    taxonomyContainer.appendChild(fragmentTaxonomy);
                    updateEmptyState(taxonomyContainer);
                }

                return;
            }

            var removeButton = event.target.closest('[data-blc-remove-threshold]');
            if (removeButton) {
                event.preventDefault();
                var row = removeButton.closest('[data-blc-surveillance-row]');
                if (row) {
                    var parent = row.parentElement;
                    row.remove();
                    updateEmptyState(parent);
                }
            }
        });

        root.addEventListener('change', function (event) {
            var toggle = event.target;
            if (!toggle.matches('[data-blc-apply-all]')) {
                return;
            }

            var row = toggle.closest('[data-blc-surveillance-row]');
            if (!row) {
                return;
            }

            var textarea = row.querySelector('.blc-surveillance__term-input');
            if (textarea) {
                textarea.disabled = toggle.checked;
                if (toggle.checked) {
                    textarea.value = '';
                }
            }
        });
    }

    window.blcSurveillanceControls = window.blcSurveillanceControls || {
        init: init,
    };
})(window, document);
