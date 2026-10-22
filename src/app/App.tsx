/**
 * Dashboard application root.
 */

import { useCallback, useEffect, useState } from 'react';

import { accountApi, ApiError, registrationApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import type { Account, ViewName } from '../lib/types';
import { ErrorBanner, Notice, Spinner } from '../components/ui';
import { Shell } from '../components/shell';
import { AuthGate } from '../features/auth/AuthGate';
import { Overview } from '../features/overview/Overview';
import { Articles } from '../features/articles/Articles';
import { Editor } from '../features/editor/Editor';
import { Profile } from '../features/profile/Profile';
import { Settings } from '../features/settings/Settings';
import { Media } from '../features/media/Media';

/**
 * Explicit verification confirmation page.
 *
 * Tokens are consumed only after the visitor presses the button, so email
 * link scanners cannot burn single-use tokens.
 */
function VerificationPage({ token }: { token: string }) {
  const bootstrap = getBootstrap();
  const [state, setState] = useState<'idle' | 'working' | 'done'>('idle');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const confirm = async () => {
    setState('working');
    setError('');

    try {
      const result = await registrationApi.confirm(token);
      setMessage(result.message);
      setState('done');

      // Remove the single-use token from the address bar and history.
      const url = new URL(window.location.href);
      url.searchParams.delete('penalis_verify');
      window.history.replaceState({}, '', url.toString());
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'The verification link could not be processed.');
      setState('idle');
    }
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <p className="penalis-eyebrow">{bootstrap.siteName}</p>
        <h1>Confirm your email</h1>
        <p className="penalis-muted">
          Confirm that this email address belongs to you. This link can only be used once.
        </p>

        {error ? <ErrorBanner message={error} /> : null}

        {state === 'done' ? (
          <>
            <Notice tone="success">{message}</Notice>
            <a
              className="penalis-button penalis-button--primary"
              href={bootstrap.isLoggedIn ? bootstrap.dashboardUrl : bootstrap.loginUrl}
            >
              {bootstrap.isLoggedIn ? 'Go to your dashboard' : 'Continue to sign in'}
            </a>
          </>
        ) : (
          <button
            type="button"
            className="penalis-button penalis-button--primary"
            onClick={() => void confirm()}
            disabled={state === 'working'}
          >
            {state === 'working' ? 'Confirming…' : 'Confirm email address'}
          </button>
        )}

        <p className="penalis-fineprint">
          <a href={bootstrap.lostPassword}>Need a new verification email?</a>
        </p>
      </div>
    </div>
  );
}

export function App() {
  const bootstrap = getBootstrap();
  const [account, setAccount] = useState<Account | null>(null);
  const [loading, setLoading] = useState(bootstrap.isLoggedIn);
  const [error, setError] = useState('');
  const [view, setView] = useState<ViewName>('overview');
  const [editingId, setEditingId] = useState<number | undefined>(undefined);
  const [signedOut, setSignedOut] = useState(!bootstrap.isLoggedIn);
  const [blocked, setBlocked] = useState(false);

  // A verification link may be opened while already signed in. Handle it
  // before the normal dashboard so the token is never silently ignored.
  const verificationToken = new URLSearchParams(window.location.search).get('penalis_verify');

  const loadAccount = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const result = await accountApi.get();
      setAccount(result);
      setSignedOut(false);
      setBlocked(false);
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        setSignedOut(true);
      } else if (caught instanceof ApiError && caught.status === 403) {
        setBlocked(true);
      } else {
        setError(caught instanceof ApiError ? caught.message : 'Your account could not be loaded.');
      }
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!bootstrap.isLoggedIn) {
      return;
    }
    void loadAccount();
  }, [bootstrap.isLoggedIn, loadAccount]);

  // Support deep links such as ?view=editor&id=123 from the articles API.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const requested = params.get('view');
    const requestedId = Number(params.get('id'));

    if (requested === 'editor' || requested === 'articles' || requested === 'media' || requested === 'profile' || requested === 'settings') {
      setView(requested);
      if (requested === 'editor' && Number.isFinite(requestedId) && requestedId > 0) {
        setEditingId(requestedId);
      }
    }
  }, []);

  if (verificationToken) {
    return <VerificationPage token={verificationToken} />;
  }

  if (signedOut) {
    return <AuthGate />;
  }

  if (blocked) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <h1>Account unavailable</h1>
          <p className="penalis-muted">
            This account cannot access the writing dashboard. If you believe this is a mistake, please contact the
            site administrators.
          </p>
          <a className="penalis-button penalis-button--ghost" href={bootstrap.homeUrl}>
            Return to the site
          </a>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <Spinner label="Loading your dashboard…" />
        </div>
      </div>
    );
  }

  if (!account) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <ErrorBanner message={error || 'Your account could not be loaded.'} onRetry={() => void loadAccount()} />
          <a className="penalis-button penalis-button--ghost" href={bootstrap.loginUrl}>
            Sign in again
          </a>
        </div>
      </div>
    );
  }

  const navigate = (next: ViewName) => {
    if (!account.canWrite && !['overview', 'profile', 'settings'].includes(next)) {
      return;
    }

    if (next === 'editor') {
      setEditingId(undefined);
    }

    setView(next);

    const url = new URL(window.location.href);
    url.searchParams.delete('view');
    url.searchParams.delete('id');
    if (next !== 'overview') {
      url.searchParams.set('view', next);
    }
    window.history.replaceState({}, '', url.toString());
  };

  const openEditor = (id?: number) => {
    if (!account.canWrite) {
      return;
    }

    setEditingId(id);
    setView('editor');

    const url = new URL(window.location.href);
    url.searchParams.set('view', 'editor');
    if (id) {
      url.searchParams.set('id', String(id));
    } else {
      url.searchParams.delete('id');
    }
    window.history.replaceState({}, '', url.toString());
  };

  // If a deep link requested a writer-only view but the account cannot write,
  // fall back to the overview instead of rendering an empty page.
  const writeOnlyViews: ViewName[] = ['articles', 'editor', 'media'];
  const effectiveView =
    !account.canWrite && writeOnlyViews.includes(view) ? 'overview' : view;

  return (
    <Shell account={account} view={effectiveView} onNavigate={navigate}>
      {effectiveView === 'overview' ? (
        <Overview account={account} onOpenEditor={() => openEditor()} onOpenArticles={() => navigate('articles')} />
      ) : null}

      {effectiveView === 'articles' && account.canWrite ? (
        <Articles onEdit={(id) => openEditor(id)} onCreate={() => openEditor()} />
      ) : null}

      {effectiveView === 'editor' && account.canWrite ? (
        <Editor
          key={editingId ?? 'new'}
          articleId={editingId}
          onSaved={() => {
            void loadAccount();
          }}
          onCancel={() => navigate('articles')}
        />
      ) : null}

      {effectiveView === 'media' && account.canWrite ? <Media /> : null}

      {effectiveView === 'profile' ? <Profile account={account} onUpdated={setAccount} /> : null}

      {effectiveView === 'settings' ? <Settings account={account} /> : null}
    </Shell>
  );
}
