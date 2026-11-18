import { describe, expect, it } from 'vitest';

import { isEditableTarget, matchEditorShortcut } from './shortcuts';

function key(overrides: Partial<Parameters<typeof matchEditorShortcut>[0]> = {}) {
  return {
    key: '',
    ctrlKey: false,
    metaKey: false,
    altKey: false,
    shiftKey: false,
    ...overrides,
  };
}

describe('matchEditorShortcut', () => {
  it('maps Ctrl+S and Command+S to save', () => {
    expect(matchEditorShortcut(key({ key: 's', ctrlKey: true }))).toBe('save');
    expect(matchEditorShortcut(key({ key: 's', metaKey: true }))).toBe('save');
  });

  it('accepts the upper-case key a shifted keyboard reports', () => {
    expect(matchEditorShortcut(key({ key: 'S', ctrlKey: true }))).toBe('save');
  });

  it('maps Ctrl+Enter and Command+Enter to submit', () => {
    expect(matchEditorShortcut(key({ key: 'Enter', ctrlKey: true }))).toBe('submit');
    expect(matchEditorShortcut(key({ key: 'Enter', metaKey: true }))).toBe('submit');
  });

  it('maps the question mark to help', () => {
    expect(matchEditorShortcut(key({ key: '?', shiftKey: true }))).toBe('help');
  });

  it('ignores unmodified typing', () => {
    expect(matchEditorShortcut(key({ key: 's' }))).toBeNull();
    expect(matchEditorShortcut(key({ key: 'Enter' }))).toBeNull();
    expect(matchEditorShortcut(key({ key: 'a' }))).toBeNull();
  });

  it('leaves Alt combinations to the operating system', () => {
    expect(matchEditorShortcut(key({ key: 's', ctrlKey: true, altKey: true }))).toBeNull();
    expect(matchEditorShortcut(key({ key: '?', altKey: true, shiftKey: true }))).toBeNull();
  });

  it('leaves Ctrl+Shift combinations alone', () => {
    expect(matchEditorShortcut(key({ key: 'S', ctrlKey: true, shiftKey: true }))).toBeNull();
    expect(matchEditorShortcut(key({ key: 'Enter', ctrlKey: true, shiftKey: true }))).toBeNull();
  });

  it('ignores other modified keys', () => {
    expect(matchEditorShortcut(key({ key: 'p', ctrlKey: true }))).toBeNull();
    expect(matchEditorShortcut(key({ key: 'Escape', ctrlKey: true }))).toBeNull();
  });
});

describe('isEditableTarget', () => {
  it('treats form controls as editable', () => {
    expect(isEditableTarget({ tagName: 'INPUT' })).toBe(true);
    expect(isEditableTarget({ tagName: 'textarea' })).toBe(true);
    expect(isEditableTarget({ tagName: 'SELECT' })).toBe(true);
  });

  it('treats contenteditable elements as editable', () => {
    expect(isEditableTarget({ tagName: 'DIV', isContentEditable: true })).toBe(true);
  });

  it('treats nodes inside a contenteditable region as editable', () => {
    const inside = { tagName: 'P', closest: () => ({ tagName: 'DIV' }) };
    expect(isEditableTarget(inside)).toBe(true);
  });

  it('treats ordinary page elements as not editable', () => {
    expect(isEditableTarget({ tagName: 'DIV' })).toBe(false);
    expect(isEditableTarget({ tagName: 'BUTTON', closest: () => null })).toBe(false);
  });

  it('survives a missing target', () => {
    expect(isEditableTarget(null)).toBe(false);
    expect(isEditableTarget(undefined)).toBe(false);
    expect(isEditableTarget('div')).toBe(false);
  });
});
