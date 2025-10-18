describe('blcSurveillanceControls', () => {
    function setupDom() {
        document.body.innerHTML = `
            <div data-blc-surveillance-root>
                <button type="button" data-blc-add-global-threshold>Ajouter global</button>
                <table>
                    <tbody data-blc-surveillance-global-list>
                        <tr class="no-items"><td>Aucun seuil</td></tr>
                    </tbody>
                </table>
                <button type="button" data-blc-add-taxonomy-threshold>Ajouter taxonomie</button>
                <table>
                    <tbody data-blc-surveillance-taxonomy-list>
                        <tr class="no-items"><td>Aucun seuil</td></tr>
                    </tbody>
                </table>
            </div>
            <template id="blc-surveillance-template-global">
                <tr data-blc-surveillance-row>
                    <td>
                        <label data-blc-aria-label="metric">Métrique</label>
                        <input name="__NAME__[metric]" type="text" />
                    </td>
                    <td>
                        <button type="button" data-blc-remove-threshold>Supprimer</button>
                    </td>
                </tr>
            </template>
            <template id="blc-surveillance-template-taxonomy">
                <tr data-blc-surveillance-row>
                    <td>
                        <input name="__NAME__[taxonomy]" type="text" />
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" data-blc-apply-all />
                            Appliquer à tous
                        </label>
                        <textarea class="blc-surveillance__term-input" name="__NAME__[term_ids]"></textarea>
                        <button type="button" data-blc-remove-threshold>Supprimer</button>
                    </td>
                </tr>
            </template>
        `;
    }

    function bootstrap() {
        jest.isolateModules(() => {
            delete window.blcSurveillanceControls;
            require('../../../liens-morts-detector-jlg/assets/js/surveillance-thresholds.js');
        });

        window.blcSurveillanceControls.init();
    }

    afterEach(() => {
        document.body.innerHTML = '';
        delete window.blcSurveillanceControls;
    });

    test('adds and removes global threshold rows', () => {
        setupDom();
        bootstrap();

        const addButton = document.querySelector('[data-blc-add-global-threshold]');
        addButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        let rows = document.querySelectorAll('[data-blc-surveillance-global-list] [data-blc-surveillance-row]');
        expect(rows).toHaveLength(1);
        expect(rows[0].querySelector('input').name).toBe('blc_surveillance_thresholds[global][0][metric]');

        const emptyRow = document.querySelector('[data-blc-surveillance-global-list] .no-items');
        expect(emptyRow.style.display).toBe('none');

        const removeButton = rows[0].querySelector('[data-blc-remove-threshold]');
        removeButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        rows = document.querySelectorAll('[data-blc-surveillance-global-list] [data-blc-surveillance-row]');
        expect(rows).toHaveLength(0);
        expect(emptyRow.style.display).toBe('');
    });

    test('toggle apply all disables taxonomy textarea', () => {
        setupDom();
        bootstrap();

        const addTaxonomyButton = document.querySelector('[data-blc-add-taxonomy-threshold]');
        addTaxonomyButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        const row = document.querySelector('[data-blc-surveillance-taxonomy-list] [data-blc-surveillance-row]');
        const toggle = row.querySelector('[data-blc-apply-all]');
        const textarea = row.querySelector('textarea');

        textarea.value = '7,9,12';
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change', { bubbles: true }));

        expect(textarea.disabled).toBe(true);
        expect(textarea.value).toBe('');

        toggle.checked = false;
        toggle.dispatchEvent(new Event('change', { bubbles: true }));

        expect(textarea.disabled).toBe(false);
    });
});
