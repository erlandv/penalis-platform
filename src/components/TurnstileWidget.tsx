/**
 * Cloudflare Turnstile widget wrapper for React auth forms.
 *
 * Uses the `api.js` script enqueued by Simple Cloudflare Turnstile and
 * configuration passed in `window.penalisDashboard.turnstile`.
 */

import { useEffect, useRef } from 'react';

import { getBootstrap, isTurnstileEnabledFor } from '../lib/bootstrap';
import type { TurnstileFormKey } from '../lib/types';

interface TurnstileRenderOptions {
  sitekey: string;
  theme?: 'auto' | 'light' | 'dark';
  size?: 'normal' | 'compact' | 'flexible';
  language?: string;
  appearance?: 'always' | 'execute' | 'interaction-only';
  action?: string;
  'response-field-name'?: string;
  callback?: (token: string) => void;
  'expired-callback'?: () => void;
  'error-callback'?: () => void;
}

interface TurnstileApi {
  render: (container: HTMLElement, options: TurnstileRenderOptions) => string;
  reset: (widgetId?: string) => void;
  remove: (widgetId?: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

interface TurnstileWidgetProps {
  form: TurnstileFormKey;
  onTokenChange?: (token: string) => void;
  resetKey?: number;
  error?: string;
}

export function TurnstileWidget({
  form,
  onTokenChange,
  resetKey = 0,
  error,
}: TurnstileWidgetProps) {
  const config = getBootstrap().turnstile;
  const enabled = isTurnstileEnabledFor(form);
  const containerRef = useRef<HTMLDivElement | null>(null);
  const widgetIdRef = useRef<string | null>(null);
  const onTokenChangeRef = useRef(onTokenChange);

  useEffect(() => {
    onTokenChangeRef.current = onTokenChange;
  }, [onTokenChange]);

  useEffect(() => {
    if (!enabled || !config?.siteKey || !containerRef.current) {
      return;
    }

    let cancelled = false;
    let pollTimer: number | null = null;
    let attempts = 0;

    const mountWidget = () => {
      if (cancelled || !containerRef.current || !window.turnstile?.render) {
        return false;
      }

      if (widgetIdRef.current !== null) {
        return true;
      }

      widgetIdRef.current = window.turnstile.render(containerRef.current, {
        sitekey: config.siteKey ?? '',
        theme: config.theme ?? 'auto',
        size: config.size ?? 'normal',
        language: config.language || 'auto',
        appearance: config.appearance ?? 'always',
        action: `penalis-${form}`,
        'response-field-name': 'cf-turnstile-response',
        callback: (token: string) => {
          onTokenChangeRef.current?.(token);
        },
        'expired-callback': () => {
          onTokenChangeRef.current?.('');
        },
        'error-callback': () => {
          onTokenChangeRef.current?.('');
        },
      });

      return true;
    };

    if (!mountWidget()) {
      pollTimer = window.setInterval(() => {
        attempts += 1;
        if (mountWidget() || attempts >= 100) {
          if (pollTimer !== null) {
            window.clearInterval(pollTimer);
            pollTimer = null;
          }
        }
      }, 100);
    }

    return () => {
      cancelled = true;
      if (pollTimer !== null) {
        window.clearInterval(pollTimer);
      }
      if (widgetIdRef.current !== null && window.turnstile?.remove) {
        try {
          window.turnstile.remove(widgetIdRef.current);
        } catch {
          // Ignore teardown errors if the script was not ready.
        }
      }
      widgetIdRef.current = null;
      onTokenChangeRef.current?.('');
    };
  }, [enabled, form, config?.siteKey, config?.theme, config?.size, config?.language, config?.appearance]);

  useEffect(() => {
    if (!enabled || resetKey <= 0 || widgetIdRef.current === null || !window.turnstile?.reset) {
      return;
    }

    try {
      window.turnstile.reset(widgetIdRef.current);
    } catch {
      // Ignore reset errors if the widget is no longer mounted.
    }
    onTokenChangeRef.current?.('');
  }, [enabled, resetKey]);

  if (!enabled) {
    return null;
  }

  return (
    <div className="penalis-field penalis-turnstile">
      <div ref={containerRef} />
      {error ? (
        <p className="penalis-field-error" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}
