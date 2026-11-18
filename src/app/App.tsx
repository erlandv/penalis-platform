/**
 * Dashboard application root.
 */

import { useCallback, useEffect, useState, type FormEvent } from 'react';

import { accountApi, ApiError, passwordApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import { DEEPLINK, EDITOR_FOCUS_FEEDBACK, readParam } from '../lib/deeplink';
import { canReceiveNotifications } from '../lib/permissions';
import type { Account, DashboardNotification, EditorFocus, FieldErrors, ViewName } from '../lib/types';
import { hasErrors, validatePasswordPair } from '../lib/validation';
import { ErrorBanner, Notice, Spinner } from '../components/ui';
import { PasswordPairFields } from '../components/PasswordPairFields';
import { TurnstileWidget } from '../components/TurnstileWidget';
import { TURNSTILE_REQUIRED_MESSAGE, useTurnstileGate } from '../components/useTurnstileGate';
import { Shell } from '../components/shell';
import { AuthGate } from '../features/auth/AuthGate';
import { Overview } from '../features/overview/Overview';
import { Articles } from '../features/articles/Articles';
import { Editor } from '../features/editor/Editor';
import { preloadEditor } from '../features/editor/editorLoader';
import { Profile } from '../features/profile/Profile';
import { Settings } from '../features/settings/Settings';
import { Media } from '../features/media/Media';
import { Notifications } from '../features/notifications/Notifications';
import { useNotifications } from '../features/notifications/useNotifications';

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
  const turnstile = useTurnstileGate('reset');

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError('');
    setErrors({});

    const clientErrors = validatePasswordPair(password, confirm);
    if (hasErrors(clientErrors)) {
      setErrors(clientErrors);
      return;
    }

    if (turnstile.isMissing()) {
      turnstile.setError(TURNSTILE_REQUIRED_MESSAGE);
      return;
    }

    turnstile.setError('');
    setSubmitting(true);

    try {
      const result = await passwordApi.reset({
        key: resetKey,
        login,
        password,
        ...(turnstile.token ? { cf_turnstile_response: turnstile.token } : {}),
      });
      setMessage(result.message);

      // The key is single-use; drop it from the address bar and history.
      const url = new URL(window.location.href);
      url.searchParams.delete(DEEPLINK.reset);
      url.searchParams.delete(DEEPLINK.resetLogin);
      url.searchParams.delete(DEEPLINK.legacyResetLogin);
      window.history.replaceState({}, '', url.toString());
    } catch (caught) {
      turnstile.reset();
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
        <h1>Atur Ulang Password</h1>

        {message ? (
          <>
            <Notice tone="success">{message}</Notice>
            <a className="penalis-button penalis-button--primary" href={bootstrap.loginUrl}>
              Lanjut Login ke Dashboard
            </a>
          </>
        ) : (
          <>
            <p className="penalis-muted">
              Masukkan password baru untuk akun {login}.
            </p>

            {formError ? <ErrorBanner message={formError} /> : null}

            <form className="penalis-form" onSubmit={submit} noValidate>
              <PasswordPairFields
                passwordId="reset_password"
                confirmId="reset_confirm_password"
                passwordHintId="reset_password-hint"
                confirmHintId="reset_confirm_password-hint"
                passwordLabel="Password Baru"
                confirmLabel="Konfirmasi Password Baru"
                password={password}
                confirm={confirm}
                onPasswordChange={(event) => setPassword(event.target.value)}
                onConfirmChange={(event) => setConfirm(event.target.value)}
                errors={errors}
                confirmHint="Ketik ulang password baru untuk konfirmasi."
              />

              <TurnstileWidget
                form="reset"
                resetKey={turnstile.resetKey}
                onTokenChange={turnstile.onTokenChange}
                error={turnstile.error}
              />

              <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
                {submitting ? 'Menyimpan…' : 'Simpan Password Baru'}
              </button>
            </form>

            <div className="penalis-auth__utility">
              <p className="penalis-otp-resend">
                Mengalami kendala?{' '}
                <a className="penalis-link-button" href={bootstrap.lostPassword}>
                  Minta link baru
                </a>
              </p>
            </div>
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
  const [editorFocus, setEditorFocus] = useState<EditorFocus>(null);
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
  const [resetLogin] = useState(
    () => readParam(DEEPLINK.resetLogin) ?? readParam(DEEPLINK.legacyResetLogin) ?? '',
  );

  /**
   * Refresh the account payload.
   *
   * `silent` exists for one reason: the loading gate below replaces the whole
   * shell, which unmounts the editor and everything it holds in memory.
   * Background refreshes — after a save, so the article counts stay current —
   * must not do that, and must not sign the writer out mid-sentence either.
   * The editor reports a dead session in place, with the text still intact.
   */
  const loadAccount = useCallback(async (options: { silent?: boolean } = {}) => {
    const silent = options.silent ?? false;

    if (!silent) {
      setLoading(true);
      setError('');
    }

    try {
      const result = await accountApi.get();
      setAccount(result);
      setSignedOut(false);
      setBlocked(false);
    } catch (caught) {
      if (silent) {
        return;
      }

      if (caught instanceof ApiError && caught.status === 401) {
        setSignedOut(true);
      } else if (caught instanceof ApiError && caught.status === 403) {
        setBlocked(true);
      } else {
        setError(caught instanceof ApiError ? caught.message : 'Akun kamu tidak dapat dimuat.');
      }
    } finally {
      if (!silent) {
        setLoading(false);
      }
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

    if (requested === 'editor' || requested === 'articles' || requested === 'media' || requested === 'profile' || requested === 'settings' || requested === 'notifications') {
      setView(requested);
      if (requested === 'editor' && Number.isFinite(requestedId) && requestedId > 0) {
        setEditingId(requestedId);
        if (readParam(DEEPLINK.editorFocus) === EDITOR_FOCUS_FEEDBACK) {
          setEditorFocus('feedback');
        }
      }
    }
  }, []);

  // Preload the editor chunk during browser idle time for active writers so
  // navigating to the editor is instant and immune to mobile network drops.
  useEffect(() => {
    if (!account?.canWrite) {
      return;
    }

    const idleWindow = window as Window & {
      requestIdleCallback?: (cb: () => void) => number;
      cancelIdleCallback?: (id: number) => void;
    };

    if (typeof idleWindow.requestIdleCallback === 'function') {
      const handle = idleWindow.requestIdleCallback(() => {
        preloadEditor();
      });
      return () => {
        if (typeof idleWindow.cancelIdleCallback === 'function') {
          idleWindow.cancelIdleCallback(handle);
        }
      };
    }

    const timer = setTimeout(() => {
      preloadEditor();
    }, 2000);
    return () => clearTimeout(timer);
  }, [account?.canWrite]);

  const handleNotificationsChanged = useCallback((unreadCount: number) => {
    setAccount((prev) => {
      if (!prev || prev.unreadNotifications === unreadCount) {
        return prev;
      }
      return { ...prev, unreadNotifications: unreadCount };
    });
  }, []);

  /*
   * One controller owns the notification list for the whole dashboard: the
   * header bell, the notifications page, and the overview panel all read it, so
   * the unread badge can never disagree with the feed. It is enabled for
   * writers and reviewers alike, because reviewers receive submission
   * notifications too.
   */
  const notifications = useNotifications({
    enabled: Boolean(account && canReceiveNotifications(account)),
    initialUnreadCount: account?.unreadNotifications ?? 0,
    onUnreadChange: handleNotificationsChanged,
  });

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
    if (!account.canWrite && !['overview', 'notifications', 'profile', 'settings'].includes(next)) {
      return;
    }

    if (next === 'editor') {
      setEditingId(undefined);
      setEditorFocus(null);
    }

    setView(next);

    const url = new URL(window.location.href);
    url.searchParams.delete('view');
    url.searchParams.delete('id');
    url.searchParams.delete(DEEPLINK.editorFocus);
    if (next !== 'overview') {
      url.searchParams.set('view', next);
    }
    window.history.replaceState({}, '', url.toString());
  };

  const openEditor = (id?: number, focus: EditorFocus = null) => {
    if (!account.canWrite) {
      return;
    }

    setEditingId(id);
    setEditorFocus(focus);
    setView('editor');

    const url = new URL(window.location.href);
    url.searchParams.set('view', 'editor');
    if (id) {
      url.searchParams.set('id', String(id));
    } else {
      url.searchParams.delete('id');
    }
    if (focus === 'feedback') {
      url.searchParams.set(DEEPLINK.editorFocus, EDITOR_FOCUS_FEEDBACK);
    } else {
      url.searchParams.delete(DEEPLINK.editorFocus);
    }
    window.history.replaceState({}, '', url.toString());
  };

  // If a deep link requested a writer-only view but the account cannot write,
  // fall back to the overview instead of rendering an empty page.
  const writeOnlyViews: ViewName[] = ['articles', 'editor', 'media'];
  const effectiveView =
    (!account.canWrite && writeOnlyViews.includes(view)) ||
    (view === 'notifications' && !canReceiveNotifications(account))
      ? 'overview'
      : view;

  /*
   * A notification opens the writer's editor for the article, or the wp-admin
   * review screen for a reviewer who has no writer capabilities.
   */
  const openNotificationArticle = (item: DashboardNotification) => {
    if (account.canWrite) {
      // A decision that carries revision notes opens the feedback panel.
      openEditor(item.articleId, item.feedback ? 'feedback' : null);
    } else if (item.reviewUrl) {
      window.location.assign(item.reviewUrl);
    }
  };

  return (
    <Shell
      account={account}
      view={effectiveView}
      onNavigate={navigate}
      notifications={notifications}
      onOpenNotificationArticle={openNotificationArticle}
    >
      {effectiveView === 'notifications' ? (
        <Notifications account={account} controller={notifications} onOpenArticle={openNotificationArticle} />
      ) : null}

      {effectiveView === 'overview' ? (
        <Overview
          account={account}
          notifications={notifications}
          onOpenEditor={() => openEditor()}
          onOpenArticle={(id) => openEditor(id)}
          onOpenArticles={() => navigate('articles')}
          onOpenNotifications={() => navigate('notifications')}
        />
      ) : null}

      {effectiveView === 'articles' && account.canWrite ? (
        <Articles onEdit={(id) => openEditor(id)} onCreate={() => openEditor()} />
      ) : null}

      {effectiveView === 'editor' && account.canWrite ? (
        <Editor
          key={`${editingId ?? 'new'}:${editorFocus ?? ''}`}
          articleId={editingId}
          focus={editorFocus}
          onSaved={() => {
            // Counts only. The editor has already put the draft id in the URL,
            // and a full reload here would throw away the caret and any text
            // typed while the save was in flight.
            void loadAccount({ silent: true });
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
