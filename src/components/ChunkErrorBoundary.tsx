/**
 * Error boundary for lazily loaded chunks.
 *
 * A dynamic `import()` rejects when the chunk cannot be fetched — a stale
 * deploy, a missing file, a CDN miss, or an offline client. Without a boundary
 * React unmounts the whole tree, so the entire dashboard goes blank instead of
 * just the one view that needed the chunk.
 *
 * This boundary keeps the failure local and offers a retry, which is usually
 * enough after a redeploy or a cache refresh.
 */

import { Component, type ErrorInfo, type ReactNode } from 'react';

type Props = {
  /** Content to render when loading succeeds. */
  children: ReactNode;
  /** Message shown when the chunk fails to load. */
  message?: string;
  /** Optional fallback UI. Defaults to a message with a retry button. */
  fallback?: ReactNode;
  /** Called when the user asks to retry. */
  onRetry?: () => void;
};

type State = {
  failed: boolean;
};

export class ChunkErrorBoundary extends Component<Props, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Kept as a console warning rather than a silent failure: a missing chunk
    // is an operational problem (bad deploy) that should be visible in logs.
    console.warn('Penalis: a lazily loaded chunk failed to load.', error, info.componentStack);
  }

  private retry = () => {
    this.setState({ failed: false });
    this.props.onRetry?.();
  };

  render() {
    if (!this.state.failed) {
      return this.props.children;
    }

    if (this.props.fallback) {
      return this.props.fallback;
    }

    return (
      <div className="penalis-banner penalis-banner--error" role="alert">
        <p>
          {this.props.message ??
            'Editor tidak dapat dimuat. Ini biasanya terjadi setelah pembaruan plugin; muat ulang halaman untuk mencoba lagi.'}
        </p>
        <button type="button" className="penalis-button penalis-button--ghost" onClick={this.retry}>
          Coba muat ulang editor
        </button>
      </div>
    );
  }
}
