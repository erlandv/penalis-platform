/**
 * Top-level error boundary.
 *
 * Without one, any uncaught render error unmounts the whole React tree and the
 * dashboard root goes blank — which looks like "the plugin is broken" with no
 * explanation. This keeps a readable message on screen and offers a reload.
 */

import { Component, type ErrorInfo, type ReactNode } from 'react';

type Props = {
  children: ReactNode;
};

type State = {
  error: Error | null;
};

export class AppErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('Penalis: the dashboard crashed while rendering.', error, info.componentStack);
  }

  render() {
    if (!this.state.error) {
      return this.props.children;
    }

    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <h1>Dashboard tidak dapat dimuat</h1>
          <p className="penalis-muted">
            Terjadi kesalahan saat menampilkan dashboard. Coba refresh halaman. Jika masalah berlanjut,
            hubungi admin Penalis dan sertakan pesan error di bawah ini.
          </p>
          <p className="penalis-fineprint">
            <code>{this.state.error.message}</code>
          </p>
          <button
            type="button"
            className="penalis-button penalis-button--primary"
            onClick={() => window.location.reload()}
          >
            Refresh halaman
          </button>
        </div>
      </div>
    );
  }
}
