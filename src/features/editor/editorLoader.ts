/**
 * Lazy loader and preloader for the RichTextEditor component.
 *
 * Separated from Editor.tsx to maintain React Fast Refresh compliance.
 */

import { createLazyLoader } from '../../lib/lazyWithRetry';

export const editorLoader = createLazyLoader(() =>
  import('./RichTextEditor').then((module) => ({ default: module.RichTextEditor })),
);

/**
 * Preload the editor chunk on idle or interaction so opening the editor is instant.
 */
export const preloadEditor = (): void => {
  void editorLoader.preload().catch(() => {
    // Failures during background preloading are caught here so they do not
    // produce unhandled promise rejections; the ChunkErrorBoundary handles
    // errors when the user actually navigates to the editor.
  });
};

