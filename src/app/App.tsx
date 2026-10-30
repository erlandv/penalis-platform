/**
 * Dashboard application root.
 */

import { useCallback, useEffect, useState, type FormEvent } from 'react';

import { accountApi, ApiError, legacyApi, passwordApi, registrationApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import type { Account, FieldErrors, ViewName } from '../lib/types';
import { PASSWORD_MIN_LENGTH } from '../lib/validation';
import { ErrorBanner, FieldError, Notice, Spinner } from '../components/ui';
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
      setError(caught instanceof ApiError ? caught.message : 'Tautan verifikasi tidak dapat diproses.');
      setState('idle');
    }
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        
        <h1>Konfirmasi email kamu</h1>
        <p className="penalis-muted">
          Konfirmasikan bahwa alamat email ini milik kamu. Tautan ini cuma bisa dipakai sekali, ya.
        </p>

        {error ? <ErrorBanner message={error} /> : null}

        {state === 'done' ? (
          <>
            <Notice tone="success">{message}</Notice>
            <a
              className="penalis-button penalis-button--primary"
              href={bootstrap.isLoggedIn ? bootstrap.dashboardUrl : bootstrap.loginUrl}
            >
              {bootstrap.isLoggedIn ? 'Buka dasbor penulis' : 'Lanjut ke halaman masuk'}
            </a>
          </>
        ) : (
          <button
            type="button"
            className="penalis-button penalis-button--primary"
            onClick={() => void confirm()}
            disabled={state === 'working'}
          >
            {state === 'working' ? 'Mengonfirmasi…' : 'Konfirmasi alamat email'}
          </button>
        )}

        <p className="penalis-fineprint">
          <a href={bootstrap.dashboardUrl}>Butuh email verifikasi baru?</a>
        </p>
      </div>
    </div>
  );
}

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
      clientErrors.confirm_password = 'Kata sandi tidak cocok.';
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
      url.searchParams.delete('penalis_reset');
      url.searchParams.delete('login');
      window.history.replaceState({}, '', url.toString());
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setFormError(caught.message);
      } else {
        setFormError('Kata sandi tidak dapat diperbarui. Silakan coba lagi.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <p className="penalis-eyebrow">{bootstrap.siteName}</p>
        <h1>Atur ulang kata sandi</h1>

        {message ? (
          <>
            <Notice tone="success">{message}</Notice>
            <a className="penalis-button penalis-button--primary" href={bootstrap.loginUrl}>
              Lanjut ke halaman masuk
            </a>
          </>
        ) : (
          <>
            <p className="penalis-muted">
              Masukkan kata sandi baru untuk akun <strong>{login}</strong>.
            </p>

            {formError ? <ErrorBanner message={formError} /> : null}

            <form className="penalis-form" onSubmit={submit} noValidate>
              <label className="penalis-field">
                <span>Kata sandi baru</span>
                <input
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  autoComplete="new-password"
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={errors.password ? 'password-error' : undefined}
                />
                <small>Minimal {PASSWORD_MIN_LENGTH} karakter.</small>
                <FieldError errors={errors} name="password" />
              </label>

              <label className="penalis-field">
                <span>Konfirmasi kata sandi baru</span>
                <input
                  type="password"
                  value={confirm}
                  onChange={(event) => setConfirm(event.target.value)}
                  autoComplete="new-password"
                  aria-invalid={Boolean(errors.confirm_password)}
                  aria-describedby={errors.confirm_password ? 'confirm_password-error' : undefined}
                />
                <FieldError errors={errors} name="confirm_password" />
              </label>

              <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
                {submitting ? 'Menyimpan…' : 'Simpan kata sandi baru'}
              </button>
            </form>

            <p className="penalis-fineprint">
              <a href={bootstrap.lostPassword}>Minta tautan baru</a>
            </p>
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Legacy account activation page reached from the activation email.
 *
 * Legacy contributors never had a password, so this page sets the first one.
 * The token is consumed only when the visitor submits, so email link scanners
 * cannot burn a single-use token.
 */
function ActivationPage({ token }: { token: string }) {
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
      clientErrors.confirm_password = 'Kata sandi tidak cocok.';
    }
    if (Object.keys(clientErrors).length > 0) {
      setErrors(clientErrors);
      return;
    }

    setSubmitting(true);

    try {
      const result = await legacyApi.confirm({ token, password });
      setMessage(result.message);

      // The token is single-use; drop it from the address bar and history.
      const url = new URL(window.location.href);
      url.searchParams.delete('penalis_activate');
      window.history.replaceState({}, '', url.toString());
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setFormError(caught.message);
      } else {
        setFormError('Akun tidak dapat diaktifkan. Silakan coba lagi.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <h1>Aktifkan akun penulis kamu</h1>

        {message ? (
          <>
            <Notice tone="success">{message}</Notice>
            <p className="penalis-muted">
              Karya tulis yang sudah terbit tetap terhubung ke akun ini. Kamu dapat masuk setelah admin
              menyetujui akun.
            </p>
            <a className="penalis-button penalis-button--primary" href={bootstrap.loginUrl}>
              Lanjut ke halaman masuk
            </a>
          </>
        ) : (
          <>
            <p className="penalis-muted">
              Buat kata sandi untuk akun yang sudah kami siapkan. Gunakan minimal {PASSWORD_MIN_LENGTH}{' '}
              karakter.
            </p>

            {formError ? <ErrorBanner message={formError} /> : null}

            <form className="penalis-form" onSubmit={submit} noValidate>
              <label className="penalis-field">
                <span>Kata sandi baru</span>
                <input
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  autoComplete="new-password"
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={errors.password ? 'password-error' : undefined}
                />
                <FieldError errors={errors} name="password" />
              </label>

              <label className="penalis-field">
                <span>Konfirmasi kata sandi</span>
                <input
                  type="password"
                  value={confirm}
                  onChange={(event) => setConfirm(event.target.value)}
                  autoComplete="new-password"
                  aria-invalid={Boolean(errors.confirm_password)}
                  aria-describedby={errors.confirm_password ? 'confirm_password-error' : undefined}
                />
                <FieldError errors={errors} name="confirm_password" />
              </label>

              <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
                {submitting ? 'Mengaktifkan…' : 'Aktifkan akun'}
              </button>
            </form>

            <p className="penalis-fineprint">
              <a href={bootstrap.dashboardUrl}>Minta tautan aktivasi baru</a>
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

  // A verification link may be opened while already signed in. Handle it
  // before the normal dashboard so the token is never silently ignored.
  //
  // The values are captured once: the pages below strip the token from the
  // address bar after use, and re-reading the URL would unmount them mid-flow.
  const [verificationToken] = useState(() =>
    new URLSearchParams(window.location.search).get('penalis_verify'),
  );

  // A password reset link carries a single-use key. It is handled before the
  // dashboard so a signed-in visitor can still complete the reset.
  const [resetKey] = useState(() => new URLSearchParams(window.location.search).get('penalis_reset'));
  const [resetLogin] = useState(
    () => new URLSearchParams(window.location.search).get('penalis_login') ?? '',
  );

  // A legacy activation link carries a single-use token. It is handled before
  // the dashboard so a signed-in visitor can still complete the activation.
  const [activationToken] = useState(() =>
    new URLSearchParams(window.location.search).get('penalis_activate'),
  );

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

  if (verificationToken) {
    return <VerificationPage token={verificationToken} />;
  }

  if (resetKey) {
    return <ResetPasswordPage resetKey={resetKey} login={resetLogin} />;
  }

  if (activationToken) {
    return <ActivationPage token={activationToken} />;
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
            Akun ini tidak dapat mengakses dasbor menulis. Jika kamu merasa ini keliru, silakan hubungi
            administrator situs.
          </p>
          <a className="penalis-button penalis-button--ghost" href={bootstrap.homeUrl}>
            Kembali ke situs
          </a>
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <Spinner label="Memuat dasbor…" />
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
            Masuk lagi
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
