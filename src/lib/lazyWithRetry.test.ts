import { describe, expect, it, vi } from 'vitest';

import { createLazyLoader, retryImport } from './lazyWithRetry';

describe('retryImport', () => {
  it('resolves immediately when importer succeeds on the first attempt', async () => {
    const importer = vi.fn().mockResolvedValue({ default: 'MockComponent' });

    const result = await retryImport(importer, 2, 10);

    expect(result).toEqual({ default: 'MockComponent' });
    expect(importer).toHaveBeenCalledTimes(1);
  });

  it('retries on failure and resolves when a subsequent attempt succeeds', async () => {
    const error = new Error('Network error');
    const importer = vi
      .fn()
      .mockRejectedValueOnce(error)
      .mockResolvedValueOnce({ default: 'RecoveredComponent' });

    const result = await retryImport(importer, 2, 10);

    expect(result).toEqual({ default: 'RecoveredComponent' });
    expect(importer).toHaveBeenCalledTimes(2);
  });

  it('exhausts all retries and rethrows the final error if all fail', async () => {
    const error = new Error('Persistent failure');
    const importer = vi.fn().mockRejectedValue(error);

    await expect(retryImport(importer, 2, 10)).rejects.toThrow('Persistent failure');
    expect(importer).toHaveBeenCalledTimes(3); // 1 initial + 2 retries
  });

  it('honors retries = 0 without attempting a retry', async () => {
    const error = new Error('Immediate failure');
    const importer = vi.fn().mockRejectedValue(error);

    await expect(retryImport(importer, 0, 10)).rejects.toThrow('Immediate failure');
    expect(importer).toHaveBeenCalledTimes(1);
  });
});

describe('createLazyLoader', () => {
  it('preloads and shares the resolved promise on subsequent calls', async () => {
    const importer = vi.fn().mockResolvedValue({ default: 'SharedComponent' });
    const loader = createLazyLoader(importer, 2, 10);

    const preloaded = await loader.preload();
    const loaded = await loader.load();

    expect(preloaded).toEqual({ default: 'SharedComponent' });
    expect(loaded).toEqual({ default: 'SharedComponent' });
    expect(importer).toHaveBeenCalledTimes(1);
  });

  it('resets the pending promise on failure so subsequent attempts retry', async () => {
    const error = new Error('Initial fail');
    const importer = vi
      .fn()
      .mockRejectedValueOnce(error)
      .mockRejectedValueOnce(error)
      .mockRejectedValueOnce(error)
      .mockResolvedValueOnce({ default: 'LaterSuccess' });

    const loader = createLazyLoader(importer, 2, 10);

    // First preload exhausts its 2 retries (total 3 attempts) and rejects.
    await expect(loader.preload()).rejects.toThrow('Initial fail');
    expect(importer).toHaveBeenCalledTimes(3);

    // Second call triggers a fresh attempt and succeeds.
    const recovered = await loader.load();
    expect(recovered).toEqual({ default: 'LaterSuccess' });
    expect(importer).toHaveBeenCalledTimes(4);
  });
});
