/**
 * Dynamic import utilities with automatic retry and preloading.
 *
 * Mobile browsers frequently encounter transient network hiccups (cell handover,
 * packet loss, or temporary signal drops) that reject dynamic `import()` calls.
 * Retrying the import with a short backoff prevents premature failure into fallback UI.
 */

/**
 * Retries a dynamic import function to recover from transient mobile network failures.
 *
 * @param importer Factory function returning the import promise.
 * @param retries Maximum retry attempts after the initial failure (default: 2).
 * @param delayMs Delay in milliseconds between retry attempts (default: 1000).
 * @return Resolved module.
 */
export async function retryImport<T>(
  importer: () => Promise<T>,
  retries = 2,
  delayMs = 1000,
): Promise<T> {
  try {
    return await importer();
  } catch (error) {
    if (retries <= 0) {
      throw error;
    }
    await new Promise((resolve) => setTimeout(resolve, delayMs));
    return retryImport(importer, retries - 1, delayMs);
  }
}

/**
 * Creates a prefetchable lazy loader with retry capability.
 *
 * @param importer Factory function returning the import promise.
 * @param retries Maximum retry attempts after the initial failure (default: 2).
 * @param delayMs Delay in milliseconds between retry attempts (default: 1000).
 * @return Object with `load` (for React.lazy) and `preload` (for idle/hover fetch).
 */
export function createLazyLoader<T>(
  importer: () => Promise<T>,
  retries = 2,
  delayMs = 1000,
): { load: () => Promise<T>; preload: () => Promise<T> } {
  let pending: Promise<T> | null = null;

  const preload = (): Promise<T> => {
    if (!pending) {
      pending = retryImport(importer, retries, delayMs).catch((error: unknown) => {
        // Reset on failure so subsequent attempts (e.g. user navigation) try again.
        pending = null;
        throw error;
      });
    }
    return pending;
  };

  return {
    load: preload,
    preload,
  };
}
