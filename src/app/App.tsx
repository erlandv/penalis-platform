/**
 * Dashboard application root.
 */

import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';

import { accountApi, ApiError, passwordApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import { DEEPLINK, EDITOR_FOCUS_FEEDBACK, readArticleId, readParam } from '../lib/deeplink';
import { isViewName } from '../lib/navigation';
import {
  canAccessView,
  canReceiveNotifications,
  isStaffRole,
  resolveDashboardRole,
  resolveEffectiveView,
} from '../lib/permissions';
import type {
  Account,
  DashboardNotification,
  EditorFocus,
  FieldErrors,
  ViewName,
} from '../lib/types';
import { hasErrors, validatePasswordPair } from '../lib/validation';
import { ErrorBanner, Notice, Spinner } from '../components/ui';
import { PasswordPairFields } from '../components/PasswordPairFields';
import { ToastProvider } from '../components/Toast';
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
import { ReviewQueue } from '../features/review/ReviewQueue';

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
  const [view, setView] = useState<ViewName>(() => {
    const requested = readParam('view');
    return isViewName(requested) ? requested : 'overview';
  });
  const [editingId, setEditingId] = useState<number | undefined>(() => {
    // Only a `?view=editor` link describes the editor; an `id` on any other
    // view belongs to that view (the reviewer reader), not to this state.
    if (readParam('view') !== 'editor') {
      return undefined;
    }

    return readArticleId();
  });
  const [editorFocus, setEditorFocus] = useState<EditorFocus>(() =>
    readParam(DEEPLINK.editorFocus) === EDITOR_FOCUS_FEEDBACK ? 'feedback' : null,
  );
  /*
   * Article open in the reviewer reader (`?view=review&id=<post_id>`).
   *
   * The writer editor already carries its article in the URL, so a
   * notification deep-links straight to the draft. The reviewer reader used to
   * be component state only, which meant a staff notification could not point
   * at one submission — the button opened the queue and the Senator had to
   * find the article again by hand. Mirroring the writer's `id` parameter gives
   * the reader the same linkability (shareable URL, working Back) and lets the
   * notification row land on the exact manuscript.
   */
  const [reviewingId, setReviewingId] = useState<number | undefined>(() => {
    // Only a `?view=review` link describes the reader; an `id` on any other
    // view belongs to that view (the editor), not to this state.
    if (readParam('view') !== 'review') {
      return undefined;
    }

    return readArticleId();
  });
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

  /*
   * Back/Forward support.
   *
   * User-initiated navigation pushes a history entry, so the browser buttons
   * have to put the view back — a curation round is Ringkasan -> Review -> read
   * -> back, and without this the first Back leaves the dashboard entirely.
   */
  useEffect(() => {
    const onPopState = () => {
      const params = new URLSearchParams(window.location.search);
      const requested = params.get('view');

      if (!isViewName(requested)) {
        setView('overview');
        setEditingId(undefined);
        setEditorFocus(null);
        setReviewingId(undefined);
        return;
      }

      const articleId = readArticleId();

      setView(requested);
      setEditingId(requested === 'editor' ? articleId : undefined);
      setEditorFocus(
        requested === 'editor' && readParam(DEEPLINK.editorFocus) === EDITOR_FOCUS_FEEDBACK ? 'feedback' : null,
      );
      setReviewingId(requested === 'review' ? articleId : undefined);
    };

    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, []);

  /*
   * Keep the address bar honest.
   *
   * A deep link or a stale bookmark can ask for a view the role cannot render
   * (a staff account opening ?view=editor). The screen is clamped to Ringkasan
   * below; this rewrites the URL so a reload does not re-request it. The `view`
   * state is deliberately left alone, so the notice explaining the redirect
   * stays on screen until the visitor navigates somewhere.
   */
  useEffect(() => {
    if (!account) {
      return;
    }

    const resolvedRole = resolveDashboardRole(account);
    const effective = resolveEffectiveView(resolvedRole, view);
    if (effective === view) {
      return;
    }

    const url = new URL(window.location.href);
    url.searchParams.delete('view');
    url.searchParams.delete('id');
    url.searchParams.delete(DEEPLINK.editorFocus);
    if (effective !== 'overview') {
      url.searchParams.set('view', effective);
    }
    window.history.replaceState({ view: effective }, '', url.toString());
  }, [account, view]);

  // Preload the editor chunk during browser idle time for active writers so
  // navigating to the editor is instant and immune to mobile network drops.
  useEffect(() => {
    if (!account || resolveDashboardRole(account) !== 'writer') {
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
  }, [account]);

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
            Senator Penalis.
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

  const role = resolveDashboardRole(account);

  /*
   * `canView` is the client mirror of `Penalis_Rest_Api::require_logged_in()`.
   * A suspended account is refused by the API but still gets the explanatory
   * banner, so it is exempt here; anything else the API would not admit gets
   * the same terminal screen the 403 response produces.
   */
  if (!account.canView && role !== 'suspended') {
    return (
      <div className="penalis-auth">
        <div className="penalis-auth__panel">
          <h1>Akun tidak tersedia</h1>
          <p className="penalis-muted">
            Akun ini tidak dapat mengakses dashboard Penalis. Jika kamu merasa ini keliru, silakan hubungi
            Senator Penalis.
          </p>
          <a className="penalis-button penalis-button--ghost" href={bootstrap.homeUrl}>
            Kembali ke beranda
          </a>
        </div>
      </div>
    );
  }

  const effectiveView = resolveEffectiveView(role, view);
  const deniedView = effectiveView !== view;

  /**
   * Push the current view into the address bar.
   *
   * `pushState` for navigation the visitor chose, so Back/Forward walk the
   * dashboard; `replaceState` only for a mid-screen update such as the editor
   * gaining the draft id it just created.
   */
  const writeViewUrl = (
    next: ViewName,
    options: { id?: number; focus?: EditorFocus; replace?: boolean } = {},
  ) => {
    const url = new URL(window.location.href);
    url.searchParams.delete('view');
    url.searchParams.delete('id');
    url.searchParams.delete(DEEPLINK.editorFocus);

    if (next !== 'overview') {
      url.searchParams.set('view', next);
    }
    if (next === 'editor' && options.id) {
      url.searchParams.set('id', String(options.id));
    }
    if (next === 'review' && options.id) {
      url.searchParams.set('id', String(options.id));
    }
    if (next === 'editor' && options.focus === 'feedback') {
      url.searchParams.set(DEEPLINK.editorFocus, EDITOR_FOCUS_FEEDBACK);
    }

    const method = options.replace ? 'replaceState' : 'pushState';
    window.history[method]({ view: next }, '', url.toString());
  };

  const navigate = (next: ViewName) => {
    if (!canAccessView(role, next)) {
      return;
    }

    if (next === 'editor') {
      setEditingId(undefined);
      setEditorFocus(null);
    }

    /*
     * The nav item is the queue, not a submission: leaving Review through the
     * menu closes any reader that a notification deep-link had opened, so the
     * list is what the next visitor to `?view=review` sees. Re-clicking the
     * current section must not stack duplicate history entries, so the URL is
     * rewritten in place instead of pushed.
     */
    if (next === 'review') {
      const hadReader = reviewingId !== undefined;
      setReviewingId(undefined);

      if (next === view) {
        if (hadReader) {
          writeViewUrl('review', { replace: true });
        }
        return;
      }
    }

    // Re-clicking the current section must not stack duplicate history entries.
    if (next === view) {
      return;
    }

    setView(next);
    writeViewUrl(next);
  };

  const openEditor = (id?: number, focus: EditorFocus = null) => {
    if (!canAccessView(role, 'editor')) {
      return;
    }

    const sameTarget = view === 'editor' && editingId === id && editorFocus === focus;

    setEditingId(id);
    setEditorFocus(focus);
    setView('editor');

    if (!sameTarget) {
      writeViewUrl('editor', { id, focus });
    }
  };

  /*
   * Open one submission in the reviewer reader.
   *
   * `id` is optional so the same helper serves the notification deep-link
   * (`openReviewArticle( item.articleId )`) and the plain queue entry from the
   * nav (`openReviewArticle()`), which must land on the list.
   *
   * Closing the reader replaces the history entry instead of pushing one: the
   * reader is a sub-state of Review, so Back from the list should return to
   * wherever the Senator came from (a notification, Ringkasan) rather than
   * bouncing them into the article they just left.
   */
  const openReviewArticle = (id?: number, options: { replace?: boolean } = {}) => {
    if (!canAccessView(role, 'review')) {
      return;
    }

    const sameTarget = view === 'review' && reviewingId === id;

    setReviewingId(id);
    setView('review');

    if (!sameTarget) {
      writeViewUrl('review', { id, replace: options.replace });
    }
  };

  /*
   * A notification opens the writer's editor for the article, or the reviewer
   * reader for that exact submission. The two audiences are exclusive, so this
   * is a role switch, not a capability combination.
   *
   * Staff used to be sent to the bare queue, which meant every notification
   * ended in a manual search for the article it was about. The reader is the
   * same workspace "Baca karya" opens, so the notification now lands where the
   * decision is actually made.
   */
  const openNotificationArticle = (item: DashboardNotification) => {
    if (isStaffRole(role)) {
      openReviewArticle(item.articleId);
      return;
    }

    if (role === 'writer') {
      // A decision that carries revision notes opens the feedback panel.
      openEditor(item.articleId, item.feedback ? 'feedback' : null);
    }
  };

  /** Render the screen for a view. `effectiveView` is already clamped to the role. */
  const renderView = (next: ViewName): ReactNode => {
    switch (next) {
      case 'overview':
        return (
          <Overview
            account={account}
            role={role}
            notifications={notifications}
            onOpenEditor={() => openEditor()}
            onOpenArticle={(id) => openEditor(id)}
            onOpenReviewArticle={(id) => openReviewArticle(id)}
            onOpenArticles={() => navigate('articles')}
            onOpenNotifications={() => navigate('notifications')}
            onOpenReview={() => navigate('review')}
          />
        );
      case 'notifications':
        return (
          <Notifications
            account={account}
            role={role}
            controller={notifications}
            onOpenArticle={openNotificationArticle}
          />
        );
      case 'review':
        return (
          <ReviewQueue
            readingId={reviewingId}
            onOpenReader={(id) => openReviewArticle(id)}
            onCloseReader={() => openReviewArticle(undefined, { replace: true })}
          />
        );
      case 'articles':
        return <Articles onEdit={(id) => openEditor(id)} onCreate={() => openEditor()} />;
      case 'editor':
        return (
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
        );
      case 'media':
        return <Media />;
      case 'profile':
        return <Profile account={account} onUpdated={setAccount} />;
      case 'settings':
        return <Settings account={account} role={role} onUpdated={setAccount} />;
    }
  };

  return (
    <ToastProvider>
      <Shell
        account={account}
        role={role}
        view={effectiveView}
        onNavigate={navigate}
        notifications={notifications}
        onOpenNotificationArticle={openNotificationArticle}
      >
        {deniedView ? (
          <Notice tone="info">
            {isStaffRole(role)
              ? 'Akun Senator tidak memiliki halaman itu. Kamu dialihkan ke Ringkasan; untuk menulis, logout lalu masuk dengan akun Penulis kamu.'
              : 'Halaman itu tidak tersedia untuk akun kamu, jadi kamu dialihkan ke Ringkasan.'}
          </Notice>
        ) : null}

        {renderView(effectiveView)}
      </Shell>
    </ToastProvider>
  );
}
