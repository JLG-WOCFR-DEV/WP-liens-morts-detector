const path = require('path');

describe('blc-admin-scripts modal interactions', () => {
  let $;

  const defaultMessages = {
    empty: 'Veuillez saisir une URL.',
    invalid: 'Veuillez saisir une URL valide.',
    same: "La nouvelle URL doit être différente de l'URL actuelle.",
    genericError: 'Une erreur est survenue. Veuillez réessayer.',
    prefixedError: 'Erreur : '
  };

  function setupDom() {
    document.body.innerHTML = `
      <div id="blc-modal" class="blc-modal" role="presentation" aria-hidden="true">
        <div class="blc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="blc-modal-title">
          <button type="button" class="blc-modal__close" aria-label="Fermer"></button>
          <h2 id="blc-modal-title" class="blc-modal__title"></h2>
          <p class="blc-modal__message"></p>
          <div class="blc-modal__error" role="alert" aria-live="assertive"></div>
          <div class="blc-modal__field">
            <label for="blc-modal-url" class="blc-modal__label"></label>
            <input type="url" id="blc-modal-url" class="blc-modal__input" value="" />
          </div>
          <div class="blc-modal__actions">
            <button type="button" class="button button-secondary blc-modal__cancel">Annuler</button>
            <button type="button" class="button button-primary blc-modal__confirm">Mettre à jour</button>
          </div>
        </div>
      </div>
      <table>
        <tbody id="the-list">
          <tr data-row-id="row-1" style="opacity: 1;">
            <td>
              <a
                href="#"
                class="blc-edit-link"
                data-url="https://old.example"
                data-postid="42"
                data-row-id="row-1"
                data-occurrence-index="0"
                data-nonce="nonce"
              >Modifier</a>
              <button
                type="button"
                class="blc-unlink"
                data-url="https://old.example"
                data-postid="42"
                data-row-id="row-1"
                data-occurrence-index="0"
                data-nonce="nonce"
              >Supprimer</button>
              <button
                type="button"
                class="blc-apply-redirect"
                data-url="https://old.example"
                data-postid="42"
                data-row-id="row-1"
                data-occurrence-index="0"
                data-nonce="nonce"
                data-detected-target="https://detected.example"
              >Appliquer la redirection</button>
            </td>
          </tr>
        </tbody>
      </table>
    `;
  }

  beforeEach(() => {
    jest.resetModules();
    jest.useFakeTimers();
    setupDom();
    $ = require('jquery');
    // Exécute immédiatement les callbacks `$(document).ready(...)`.
    $.fn.ready = function (fn) {
      fn.call(document, $);
      return this;
    };
    // En environnement JSDOM, `:visible` renvoie `false` par défaut : on le force à `true`
    // pour pouvoir tester la gestion du focus dans la modale.
    $.expr.pseudos.visible = () => true;
    $.expr.pseudos.hidden = () => false;
    // Simplifie les animations jQuery utilisées lors de la suppression de lignes.
    $.fn.fadeOut = function (_duration, callback) {
      if (typeof callback === 'function') {
        this.each(function () {
          callback.call(this);
        });
      }
      return this;
    };
    $.post = jest.fn();
    global.jQuery = $;
    global.$ = $;
    window.jQuery = $;
    window.$ = $;
    global.ajaxurl = 'admin-ajax.php';
    delete window.blcAdminMessages;
    window.blcAdminSoft404Config = {
      minLength: 10,
      titleWeight: 1.5,
      titleIndicators: ['introuvable', 'erreur'],
      bodyIndicators: ['erreur 404', 'page introuvable'],
      ignorePatterns: ['/profil/i'],
      labels: {
        length: 'Contenu trop court',
        title: 'Titre suspect',
        body: "Message d’erreur détecté",
        titleWeight: 'Pondération du titre'
      }
    };
    require(path.resolve(__dirname, '../blc-admin-scripts.js'));
    expect($('#blc-modal').length).toBe(1);
    expect($('#blc-modal').attr('tabindex')).toBe('-1');
  });

  afterEach(() => {
    jest.runOnlyPendingTimers();
    jest.useRealTimers();
    document.body.innerHTML = '';
    delete global.jQuery;
    delete global.$;
    delete window.jQuery;
    delete window.$;
    delete global.ajaxurl;
    delete window.blcAdminSoft404Config;
  });

  function openEditModal() {
    const link = $('#the-list .blc-edit-link');
    expect($('#the-list').length).toBe(1);
    expect(link.length).toBe(1);
    link.trigger('click');
    jest.advanceTimersByTime(20);
    return $('#blc-modal');
  }

  function mockAjaxHandlers() {
    let doneHandler = () => {};
    let failHandler = () => {};
    // Simule l'objet renvoyé par `$.post` pour piloter manuellement les callbacks `.done()`/`.fail()`.
    $.post.mockImplementation(() => ({
      done(handler) {
        doneHandler = handler;
        return this;
      },
      fail(handler) {
        failHandler = handler;
        return this;
      }
    }));
    return {
      triggerSuccess(response) {
        doneHandler(response);
      },
      triggerFailure(error) {
        failHandler(error);
      }
    };
  }

  test('opens and closes the modal via edit link interactions', () => {
    const modal = openEditModal();

    expect(modal.hasClass('is-open')).toBe(true);
    expect(document.body.classList.contains('blc-modal-open')).toBe(true);

    modal.find('.blc-modal__cancel').trigger('click');

    expect(modal.hasClass('is-open')).toBe(false);
    expect(document.body.classList.contains('blc-modal-open')).toBe(false);
  });

  test('validates user input before submitting changes', () => {
    const modal = openEditModal();
    const input = modal.find('.blc-modal__input');
    const confirm = modal.find('.blc-modal__confirm');
    const error = modal.find('.blc-modal__error');

    input.val('   ');
    confirm.trigger('click');
    expect(error.text()).toBe(defaultMessages.empty);

    input.val('https://exa mple.com');
    confirm.trigger('click');
    expect(error.text()).toBe(defaultMessages.invalid);

    input.val('https://old.example');
    confirm.trigger('click');
    expect(error.text()).toBe(defaultMessages.same);
  });

  test('detects soft 404 responses via title and body heuristics', () => {
    const result = window.blcAdmin.soft404.detect({
      body: '<html><head><title>Page Introuvable</title></head><body><h1>Erreur 404</h1></body></html>'
    });

    expect(result.isSoft404).toBe(true);
    expect(result.reasons).toEqual(expect.arrayContaining(['title', 'body']));
  });

  test('skips soft 404 detection when ignore patterns match', () => {
    const result = window.blcAdmin.soft404.detect({
      body: '<html><body><p>Profil introuvable</p></body></html>'
    });

    expect(result.isSoft404).toBe(false);
    expect(result.ignored).toBe(true);
  });

  test('flags short content when below the configured threshold', () => {
    const result = window.blcAdmin.soft404.detect({ body: '<p>Hi</p>' });

    expect(result.reasons).toContain('length');
    expect(result.isSoft404).toBe(true);
  });

  test('exposes normalized soft 404 configuration', () => {
    const config = window.blcAdmin.soft404.getConfig();

    expect(config.minLength).toBe(10);
    expect(config.titleWeight).toBeCloseTo(1.5);
    expect(config.titleIndicators).toEqual(expect.arrayContaining(['introuvable', 'erreur']));
    expect(config.bodyIndicators).toEqual(expect.arrayContaining(['erreur 404', 'page introuvable']));
    expect(config.ignorePatterns).toEqual(expect.arrayContaining(['/profil/i']));
  });

  test('keeps focus trapped inside the modal when tabbing', () => {
    const modal = openEditModal();
    const closeButton = modal.find('.blc-modal__close');
    const input = modal.find('.blc-modal__input');
    const cancelButton = modal.find('.blc-modal__cancel');
    const confirmButton = modal.find('.blc-modal__confirm');

    input[0].focus();
    const tabFromInput = $.Event('keydown', { key: 'Tab' });
    input.trigger(tabFromInput);
    expect(tabFromInput.isDefaultPrevented()).toBe(true);
    expect(document.activeElement).toBe(cancelButton[0]);

    confirmButton[0].focus();
    const tabFromConfirm = $.Event('keydown', { key: 'Tab' });
    confirmButton.trigger(tabFromConfirm);
    expect(tabFromConfirm.isDefaultPrevented()).toBe(true);
    expect(document.activeElement).toBe(closeButton[0]);

    closeButton[0].focus();
    const shiftTabFromClose = $.Event('keydown', { key: 'Tab', shiftKey: true });
    closeButton.trigger(shiftTabFromClose);
    expect(shiftTabFromClose.isDefaultPrevented()).toBe(true);
    expect(document.activeElement).toBe(confirmButton[0]);
  });

  test('closes the modal and removes the row when the AJAX response succeeds', () => {
    const ajax = mockAjaxHandlers();
    const modal = openEditModal();
    const input = modal.find('.blc-modal__input');
    const confirm = modal.find('.blc-modal__confirm');
    const row = $('#the-list tr');

    input.val('https://new.example');
    confirm.trigger('click');

    expect(modal.hasClass('is-submitting')).toBe(true);
    expect(confirm.prop('disabled')).toBe(true);
    expect(row.css('opacity')).toBe('0.5');

    ajax.triggerSuccess({ success: true });

    expect(modal.hasClass('is-open')).toBe(false);
    const rows = $('#the-list tr');
    expect(rows.length).toBe(1);

    const noItemsRow = rows.filter('.no-items');
    expect(noItemsRow.length).toBe(1);
    expect(noItemsRow.find('td').attr('colspan')).toBe('1');
    expect(noItemsRow.text()).toBe('Aucun élément à afficher.');
    expect(document.body.classList.contains('blc-modal-open')).toBe(false);
  });

  test('restores the UI and surfaces an error when the AJAX response fails', () => {
    const ajax = mockAjaxHandlers();
    const modal = openEditModal();
    const input = modal.find('.blc-modal__input');
    const confirm = modal.find('.blc-modal__confirm');
    const row = $('#the-list tr');

    input.val('https://new.example');
    confirm.trigger('click');
    ajax.triggerSuccess({ success: false, data: { message: 'Serveur indisponible' } });

    expect(modal.hasClass('is-open')).toBe(true);
    expect(modal.hasClass('is-submitting')).toBe(false);
    expect(confirm.prop('disabled')).toBe(false);
    expect(row.css('opacity')).toBe('1');
    expect(modal.find('.blc-modal__error').text()).toBe(`${defaultMessages.prefixedError}Serveur indisponible`);
  });

  test('shows a generic error message when the AJAX request is rejected', () => {
    const ajax = mockAjaxHandlers();
    const modal = openEditModal();
    const input = modal.find('.blc-modal__input');
    const confirm = modal.find('.blc-modal__confirm');
    const row = $('#the-list tr');

    input.val('https://new.example');
    confirm.trigger('click');
    ajax.triggerFailure(new Error('Network error'));

    expect(modal.hasClass('is-open')).toBe(true);
    expect(modal.hasClass('is-submitting')).toBe(false);
    expect(confirm.prop('disabled')).toBe(false);
    expect(row.css('opacity')).toBe('1');
    expect(modal.find('.blc-modal__error').text()).toBe(defaultMessages.genericError);
  });

  test('applies a detected redirect after confirming the modal', async () => {
    const ajax = mockAjaxHandlers();
    const button = $('#the-list .blc-apply-redirect');

    button.trigger('click');
    jest.advanceTimersByTime(20);

    const modal = $('#blc-modal');
    expect(modal.hasClass('is-open')).toBe(true);
    expect(modal.find('.blc-modal__field').hasClass('is-hidden')).toBe(true);
    expect(modal.find('.blc-modal__message').text()).toBe('Appliquer la redirection détectée vers https://detected.example ?');

    modal.find('.blc-modal__confirm').trigger('click');
    await Promise.resolve();
    expect(modal.hasClass('is-submitting')).toBe(true);

    ajax.triggerSuccess({ success: true });

    expect($.post).toHaveBeenCalledWith(
      'admin-ajax.php',
      expect.objectContaining({
        action: 'blc_apply_detected_redirect',
        new_url: 'https://detected.example'
      })
    );
    expect(modal.hasClass('is-open')).toBe(false);
  });

  test('shows an informational modal when no detected redirect is available', async () => {
    const button = $('#the-list .blc-apply-redirect');
    button.attr('data-detected-target', '');
    button.data('detectedTarget', '');
    $.post.mockClear();

    button.trigger('click');
    jest.advanceTimersByTime(20);

    const modal = $('#blc-modal');
    expect(modal.hasClass('is-open')).toBe(true);
    expect(modal.find('.blc-modal__field').hasClass('is-hidden')).toBe(true);
    const cancelButton = modal.find('.blc-modal__cancel');
    expect(cancelButton.prop('hidden')).toBe(true);

    modal.find('.blc-modal__confirm').trigger('click');
    jest.advanceTimersByTime(20);
    await Promise.resolve();

    expect(modal.hasClass('is-open')).toBe(false);
    expect($.post).not.toHaveBeenCalled();
  });

  test('does not apply the redirect when the modal is cancelled', async () => {
    const button = $('#the-list .blc-apply-redirect');

    button.trigger('click');
    jest.advanceTimersByTime(20);

    const modal = $('#blc-modal');
    expect(modal.hasClass('is-open')).toBe(true);

    $.post.mockClear();
    modal.find('.blc-modal__cancel').trigger('click');
    jest.advanceTimersByTime(20);
    await Promise.resolve();

    expect(modal.hasClass('is-open')).toBe(false);
    expect($.post).not.toHaveBeenCalled();
  });
});
