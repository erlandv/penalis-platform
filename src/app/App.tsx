/**
 * Dashboard application root.
 */

import { useCallback, useEffect, useState, type FormEvent } from 'react';

import { accountApi, ApiError, passwordApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import { DEEPLINK, readParam } from '../lib/deeplink';
import type { Account, FieldErrors, ViewName } from '../lib/types';
import { PASSWORD_MIN_LENGTH } from '../lib/validation';
import { describedBy } from '../lib/a11y';
import { ErrorBanner, FieldError, FieldHint, Notice, Spinner } from '../components/ui';
import { PasswordInput } from '../components/PasswordInput';
import { Shell } from '../components/shell';
import { AuthGate } from '../features/auth/AuthGate';
import { Overview } from '../features/overview/Overview';
import { Articles } from '../features/articles/Articles';
import { Editor } from '../features/editor/Editor';
import { Profile } from '../features/profile/Profile';
import { Settings } from '../features/settings/Settings';
import { Media } from '../features/media/Media';

/**
 * Password reset form reached from the reset email.
 *
 * The key is consumed only when the visitor submits a new password, so email
 * link scanners cannot burn a single-use key by opening the URL.
 */
function ResetPasswordPage({ resetKey, login }: { resetKey: string; login: string }) {
  const bootstrap = getBootstrap();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');
  const [message, setMessage] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError('');
    setErrors({});

    const clientErrors: FieldErrors = {};
    if (password.length < PASSWORD_MIN_LENGTH) {
      clientErrors.password = `Gunakan minimal ${PASSWORD_MIN_LENGTH} karakter.`;
    }
    if (password !== confirm) {
      clientErrors.confirm_password = 'Password tidak cocok.';
    }
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      return;
    }

    setSubmitting(true);

    try {
      const result = await passwordApi.reset({ key: resetKey, login, password });
      setMessage(result.message);

      // The key is single-use; drop it from the address bar and history.
      const url = new URL(window.location.href);
      url.searchParams.delete(DEEPLINK.reset);
      url.searchParams.delete(DEEPLINK.resetLogin);
      window.history.replaceState({}, '', url.toString());
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setFormError(caught.message);
      } else {
        setFormError('Password tidak dapat diperbarui. Silakan coba lagi.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <p className="penalis-eyebrow">{bootstrap.siteName}</p>
        <h1>Atur Ulang Password</h1>

        {message ? (
          <>
            <Notice tone="success">{message}</Notice>
            <a className="penalis-button penalis-button--primary" href={bootstrap.loginUrl}>
              Lanjut login ke Dashboard
            </a>
          </>
        ) : (
          <>
            <p className="penalis-muted">
              Masukkan password baru untuk akun {login}.
            </p>

            {formError ? <ErrorBanner message={formError} /> : null}

            <form className="penalis-form" onSubmit={submit} noValidate>
              <div className="penalis-field">
                <label htmlFor="reset_password">Password Baru</label>
                <PasswordInput
                  id="reset_password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  autoComplete="new-password"
                  placeholder={`Minimal ${PASSWORD_MIN_LENGTH} karakter`}
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={describedBy('reset_password-hint', errors.password && 'password-error')}
                />
                <FieldHint id="reset_password-hint">Minimal {PASSWORD_MIN_LENGTH} karakter.</FieldHint>
                <FieldError errors={errors} name="password" />
              </div>

              <div className="penalis-field">
                <label htmlFor="reset_confirm_password">Konfirmasi Password Baru</label>
                <PasswordInput
                  id="reset_confirm_password"
                  value={confirm}
                  onChange={(event) => setConfirm(event.target.value)}
                  autoComplete="new-password"
                  placeholder="Ulangi password baru"
                  aria-invalid={Boolean(errors.confirm_password)}
                  aria-describedby={describedBy(errors.confirm_password && 'confirm_password-error')}
                />
                <FieldError errors={errors} name="confirm_password" />
              </div>

              <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
                {submitting ? 'Menyimpan…' : 'Simpan password baru'}
              </button>
            </form>

            <p className="penalis-fineprint">
              <a href={bootstrap.lostPassword}>Minta link baru</a>
            </p>
          </>
        )}
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

  // A password reset link carries a single-use key. It is handled before the
  // dashboard so a signed-in visitor can still complete the reset.
  //
  // This key comes from WordPress core's get_password_reset_key(), which uses
  // wp_generate_password( 20, false ) — 20 alphanumeric characters. It is NOT
  // a plugin token, so isTokenShaped() must not be applied here: that check
  // requires 40-64 characters and would reject every real reset link.
  const [resetKey] = useState(() => {
    const value = readParam(DEEPLINK.reset);
    return value && value.trim() !== '' ? value : null;
  });
  const [resetLogin] = useState(() => readParam(DEEPLINK.resetLogin) ?? '');

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
        setError(caught instanceof ApiError ? caught.message : 'Akun kamu tidak dapat dimuat.');
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

  const handleNotificationsChanged = useCallback((unreadCount: number) => {
    setAccount((prev) => {
      if (!prev || prev.unreadNotifications === unreadCount) {
        return prev;
      }
      return { ...prev, unreadNotifications: unreadCount };
    });
  }, []);

  if (resetKey) {
    return <ResetPasswordPage resetKey={resetKey} login={resetLogin} />;
  }

  if (signedOut) {
    return <AuthGate />;
  }

  if (blocked) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <h1>Akun tidak tersedia</h1>
          <p className="penalis-muted">
            Akun ini tidak dapat mengakses dashboard penulis. Jika kamu merasa ini keliru, silakan hubungi
            admin Penalis.
          </p>
          <a className="penalis-button penalis-button--ghost" href={bootstrap.homeUrl}>
            Kembali ke beranda
          </a>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <Spinner label="Memuat dashboard…" />
        </div>
      </div>
    );
  }

  if (!account) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <ErrorBanner message={error || 'Akun kamu tidak dapat dimuat.'} onRetry={() => void loadAccount()} />
          <a className="penalis-button penalis-button--ghost" href={bootstrap.loginUrl}>
            Login lagi
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
        <Overview
          account={account}
          onOpenEditor={() => openEditor()}
          onOpenArticle={(id) => openEditor(id)}
          onOpenArticles={() => navigate('articles')}
          onNotificationsChanged={handleNotificationsChanged}
        />
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

      {effectiveView === 'settings' ? <Settings account={account} onUpdated={setAccount} /> : null}
    </Shell>
  );
}
