const path = require('path');

describe('editor iframe guard', () => {
  let guard;

  beforeEach(() => {
    jest.resetModules();
    document.body.className = '';
    delete window.BLC_IS_EDITOR;
    delete window.frameElement;
    guard = require(path.resolve(
      __dirname,
      '../editor-iframe-guard.js'
    ));
  });

  afterEach(() => {
    document.body.className = '';
    delete window.BLC_IS_EDITOR;
  });

  test('exports a detector function', () => {
    expect(typeof guard.blcIsIframedEditorContext).toBe('function');
  });

  test('returns false on a normal admin screen', () => {
    expect(guard.blcIsIframedEditorContext(window)).toBe(false);
  });

  test('detects the iframed canvas body class', () => {
    document.body.classList.add('block-editor-iframe__body');
    expect(guard.blcIsIframedEditorContext(window)).toBe(true);
  });

  test('detects canvas=edit query args', () => {
    const fakeWindow = {
      location: { search: '?canvas=edit' },
      document: { body: { classList: { contains: () => false } } },
      BLC_IS_EDITOR: false,
    };
    expect(guard.blcIsIframedEditorContext(fakeWindow)).toBe(true);
  });

  test('detects context=edit query args', () => {
    const fakeWindow = {
      location: { search: '?context=edit' },
      document: { body: { classList: { contains: () => false } } },
    };
    expect(guard.blcIsIframedEditorContext(fakeWindow)).toBe(true);
  });

  test('detects editor-canvas iframe name', () => {
    const fakeWindow = {
      location: { search: '' },
      document: { body: { classList: { contains: () => false } } },
      frameElement: {
        getAttribute: (name) => (name === 'name' ? 'editor-canvas' : ''),
        className: '',
      },
    };
    expect(guard.blcIsIframedEditorContext(fakeWindow)).toBe(true);
  });

  test('honors the PHP editor flag', () => {
    window.BLC_IS_EDITOR = true;
    expect(guard.blcIsIframedEditorContext(window)).toBe(true);
  });
});
