/**
 * Login, registration, and verification notices for signed-out visitors.
 */

import { useState, type FormEvent } from 'react';

import { ApiError, legacyApi, passwordApi, registrationApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import { DEEPLINK, hasParam, isTokenShaped, readParam } from '../../lib/deeplink';
import type { FieldErrors } from '../../lib/types';
import { hasErrors, validateEmail, validateLoginOrEmail, validateRegistration } from '../../lib/validation';
import { describedBy } from '../../lib/a11y';
import { ErrorBanner, FieldError, FieldHint, Notice, Spinner } from '../../components/ui';

type Mode = 'login' | 'register' | 'resend' | 'forgot' | 'activate';

const HEADINGS: Record<Mode, string> = {
  login: 'Login',
  register: 'Buat akun Penalis',
  resend: 'Kirim ulang email verifikasi',
  forgot: 'Lupa kata sandi',
  activate: 'Aktifkan akun penulis lama',
};

const DESCRIPTIONS: Record<Mode, string> = {
  login: 'Masuk dengan akun Penalis untuk mengelola karya tulis.',
  register: 'Buat akun Penalis untuk mulai menulis di Penalis.',
  resend: 'Minta tautan verifikasi baru untuk akun yang belum dikonfirmasi.',
  forgot: 'Masukkan email atau nama pengguna kamu. Kami akan mengirim tautan untuk mengatur ulang kata sandi.',
  activate:
    'Pernah mengirim karya tulis sebelum dasbor ini ada? Kami sudah membuatkan akun untukmu. Masukkan email yang kamu pakai saat mengirim, lalu buat kata sandi.',
};

/**
 * Which mode a deep link opens.
 *
 * Every form has a flag parameter so the public site can link straight to it:
 *
 * | Parameter | Opens |
 * | --- | --- |
 * | `penalis_signin=1` | Login (also the default) |
 * | `penalis_register=1` | Registration |
 * | `penalis_activate=1` | Legacy account activation |
 * | `penalis_forgot=1` | Password reset request |
 * | `penalis_resend=1` | Verification resend |
 *
 * `penalis_activate` is special: a token-shaped value is handled earlier by
 * `App` as a confirmation link, so only the flag form reaches this function.
 */
function initialMode(): Mode {
  if (hasParam(DEEPLINK.register)) {
    return 'register';
  }

  if (hasParam(DEEPLINK.activate) && !isTokenShaped(readParam(DEEPLINK.activate))) {
    return 'activate';
  }

  if (hasParam(DEEPLINK.forgot)) {
    return 'forgot';
  }

  if (hasParam(DEEPLINK.resend)) {
    return 'resend';
  }

  return 'login';
}

export function AuthGate() {
  const bootstrap = getBootstrap();
  const [mode, setMode] = useState<Mode>(initialMode);

  // A failed dashboard login redirects back here with a flag instead of
  // rendering the WordPress login screen. The flag is read once and removed
  // from the address bar so it does not linger after the user switches modes.
  const [loginFailed] = useState(() => {
    const url = new URL(window.location.href);

    if (!url.searchParams.has(DEEPLINK.loginError)) {
      return false;
    }

    url.searchParams.delete(DEEPLINK.loginError);
    window.history.replaceState({}, '', url.toString());

    return true;
  });

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        
        <h1>{HEADINGS[mode]}</h1>
        <p className="penalis-muted">{DESCRIPTIONS[mode]}</p>

        {mode === 'login' && loginFailed ? (
          <ErrorBanner message="Nama pengguna atau kata sandi salah, atau akun ini belum dapat masuk." />
        ) : null}

        {mode === 'login' ? <LoginForm /> : null}
        {mode === 'register' ? <RegistrationForm onDone={() => setMode('login')} /> : null}
        {mode === 'resend' ? <ResendForm /> : null}
        {mode === 'forgot' ? <ForgotForm onDone={() => setMode('login')} /> : null}
        {mode === 'activate' ? <ActivateForm onDone={() => setMode('login')} /> : null}

        <div className="penalis-auth__alt">
          {mode === 'login' && bootstrap.registration ? (
            <button
              type="button"
              className="penalis-button penalis-auth__alt-action"
              onClick={() => setMode('register')}
            >
              Buat Akun Penalis
            </button>
          ) : null}

          {mode === 'login' ? (
            <button
              type="button"
              className="penalis-button penalis-auth__alt-action"
              onClick={() => setMode('activate')}
            >
              Aktifkan akun penulis lama
            </button>
          ) : null}

          {mode !== 'login' ? (
            <button type="button" className="penalis-auth__back" onClick={() => setMode('login')}>
              Kembali ke halaman login
            </button>
          ) : null}
        </div>

        <div className="penalis-auth__utility">
          {mode === 'login' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('forgot')}>
              Lupa kata sandi?
            </button>
          ) : null}

          {mode !== 'resend' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('resend')}>
              Kirim ulang email verifikasi
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function LoginForm() {
  const bootstrap = getBootstrap();

  return (
    <form
      className="penalis-form"
      method="post"
      action={bootstrap.dashboardUrl}
      noValidate
    >
      {/* Marks the submission as coming from the dashboard form. */}
      <input type="hidden" name="penalis_login" value="1" />

      <div className="penalis-field">
        <label htmlFor="login">Nama pengguna atau email</label>
        <input
          id="login"
          type="text"
          name="log"
          autoComplete="username"
          placeholder="nama.pengguna atau nama@email.com"
          required
        />
      </div>

      <div className="penalis-field">
        <label htmlFor="login_password">Kata sandi</label>
        <input
          id="login_password"
          type="password"
          name="pwd"
          autoComplete="current-password"
          placeholder="Kata sandi kamu"
          required
        />
      </div>

      <label className="penalis-checkbox">
        <input type="checkbox" name="rememberme" value="forever" />
        <span>Tetap masuk</span>
      </label>

      <button type="submit" className="penalis-button penalis-button--primary">
        Masuk
      </button>
    </form>
  );
}

function RegistrationForm({ onDone }: { onDone: () => void }) {
  const [values, setValues] = useState({
    display_name: '',
    user_login: '',
    user_email: '',
    password: '',
    confirm_password: '',
    penalis_consent: false,
    penalis_website: '',
  });
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');
  const [success, setSuccess] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const update = (key: keyof typeof values, value: string | boolean) => {
    setValues((current) => ({ ...current, [key]: value }));
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError('');
    setSuccess('');

    const clientErrors = validateRegistration(values);
    if (hasErrors(clientErrors)) {
      setErrors(clientErrors);
      return;
    }
    setErrors({});
    setSubmitting(true);

    try {
      const result = await registrationApi.register({
        display_name: values.display_name,
        user_login: values.user_login,
        user_email: values.user_email,
        password: values.password,
        penalis_consent: '1',
        penalis_website: values.penalis_website,
      });

      setSuccess(result.message);
      setValues({
        display_name: '',
        user_login: '',
        user_email: '',
        password: '',
        confirm_password: '',
        penalis_consent: false,
        penalis_website: '',
      });
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.fields);
        setFormError(error.message);
      } else {
        setFormError('Pendaftaran tidak dapat diselesaikan. Silakan coba lagi.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (success) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{success}</Notice>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Continue to sign in
        </button>
      </div>
    );
  }

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      {formError ? <ErrorBanner message={formError} /> : null}

      <div className="penalis-field">
        <label htmlFor="display_name">Nama tampilan</label>
        <input
          id="display_name"
          type="text"
          value={values.display_name}
          onChange={(event) => update('display_name', event.target.value)}
          maxLength={80}
          autoComplete="name"
          placeholder="Nama lengkap atau nama pena"
          aria-invalid={Boolean(errors.display_name)}
          aria-describedby={describedBy('display_name-hint', errors.display_name && 'display_name-error')}
        />
        <FieldHint id="display_name-hint">2–80 karakter. Nama ini tampil di profil publik.</FieldHint>
        <FieldError errors={errors} name="display_name" />
      </div>

      <div className="penalis-field">
        <label htmlFor="user_login">Nama pengguna</label>
        <input
          id="user_login"
          type="text"
          value={values.user_login}
          onChange={(event) => update('user_login', event.target.value)}
          maxLength={60}
          autoComplete="username"
          placeholder="contoh: ada.lovelace"
          aria-invalid={Boolean(errors.user_login)}
          aria-describedby={describedBy('user_login-hint', errors.user_login && 'user_login-error')}
        />
        <FieldHint id="user_login-hint">
          3–60 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.
        </FieldHint>
        <FieldError errors={errors} name="user_login" />
      </div>

      <div className="penalis-field">
        <label htmlFor="user_email">Alamat email</label>
        <input
          id="user_email"
          type="email"
          value={values.user_email}
          onChange={(event) => update('user_email', event.target.value)}
          maxLength={254}
          autoComplete="email"
          placeholder="nama@email.com"
          aria-invalid={Boolean(errors.user_email)}
          aria-describedby={describedBy('user_email-hint', errors.user_email && 'user_email-error')}
        />
        <FieldHint id="user_email-hint">Kami mengirim tautan verifikasi ke alamat ini.</FieldHint>
        <FieldError errors={errors} name="user_email" />
      </div>

      <div className="penalis-field">
        <label htmlFor="password">Kata sandi</label>
        <input
          id="password"
          type="password"
          value={values.password}
          onChange={(event) => update('password', event.target.value)}
          autoComplete="new-password"
          placeholder="Minimal 12 karakter"
          aria-invalid={Boolean(errors.password)}
          aria-describedby={describedBy('password-hint', errors.password && 'password-error')}
        />
        <FieldHint id="password-hint">Minimal 12 karakter.</FieldHint>
        <FieldError errors={errors} name="password" />
      </div>

      <div className="penalis-field">
        <label htmlFor="confirm_password">Konfirmasi kata sandi</label>
        <input
          id="confirm_password"
          type="password"
          value={values.confirm_password}
          onChange={(event) => update('confirm_password', event.target.value)}
          autoComplete="new-password"
          placeholder="Ulangi kata sandi"
          aria-invalid={Boolean(errors.confirm_password)}
          aria-describedby={describedBy(errors.confirm_password && 'confirm_password-error')}
        />
        <FieldError errors={errors} name="confirm_password" />
      </div>

      {/* Honeypot: hidden from users, must remain empty. */}
      <div className="penalis-honeypot" aria-hidden="true">
        <label>
          Website
          <input
            type="text"
            tabIndex={-1}
            autoComplete="off"
            value={values.penalis_website}
            onChange={(event) => update('penalis_website', event.target.value)}
          />
        </label>
      </div>

      <label className="penalis-checkbox">
        <input
          type="checkbox"
          checked={values.penalis_consent}
          onChange={(event) => update('penalis_consent', event.target.checked)}
          aria-invalid={Boolean(errors.penalis_consent)}
          aria-describedby={describedBy(errors.penalis_consent && 'penalis_consent-error')}
        />
        <span>Saya menyetujui ketentuan situs dan memahami bahwa pendaftaran saya memerlukan persetujuan administrator.</span>
      </label>
      <FieldError errors={errors} name="penalis_consent" />

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? 'Membuat akun…' : 'Buat akun'}
      </button>
    </form>
  );
}

function ResendForm() {
  const [email, setEmail] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');

    // The server answers generically for every outcome, so an empty or
    // malformed address would otherwise look like a successful request.
    const invalid = validateEmail(email);
    if (invalid) {
      setFieldError(invalid);
      return;
    }
    setFieldError('');
    setSubmitting(true);

    try {
      const result = await registrationApi.resend(email.trim());
      setMessage(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Permintaan tidak dapat diselesaikan.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      <p className="penalis-muted">
        Masukkan alamat email yang Anda gunakan saat mendaftar. Jika akun sedang menunggu verifikasi, kami akan
        mengirim tautan baru.
      </p>

      {error ? <ErrorBanner message={error} /> : null}
      {message ? <Notice tone="success">{message}</Notice> : null}

      <div className="penalis-field">
        <label htmlFor="resend_email">Alamat email</label>
        <input
          id="resend_email"
          type="email"
          value={email}
          onChange={(event) => {
            setEmail(event.target.value);
            setFieldError('');
          }}
          autoComplete="email"
          maxLength={254}
          placeholder="nama@email.com"
          aria-invalid={Boolean(fieldError)}
          aria-describedby={describedBy('resend_email-hint', fieldError && 'resend_email-error')}
          required
        />
        <FieldHint id="resend_email-hint">Gunakan alamat yang kamu pakai saat mendaftar.</FieldHint>
        <FieldError errors={{ resend_email: fieldError }} name="resend_email" />
      </div>

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? <Spinner label="Mengirim…" /> : 'Kirim email verifikasi'}
      </button>
    </form>
  );
}

/**
 * Password reset request.
 *
 * The server response is deliberately generic, so the success notice repeats
 * the same conditional wording instead of confirming that the account exists.
 */
function ForgotForm({ onDone }: { onDone: () => void }) {
  const [login, setLogin] = useState('');
  const [honeypot, setHoneypot] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');

    // The server answers generically for every outcome, so an empty field
    // would otherwise look like a successful request.
    const invalid = validateLoginOrEmail(login);
    if (invalid) {
      setFieldError(invalid);
      return;
    }
    setFieldError('');
    setSubmitting(true);

    try {
      const result = await passwordApi.forgot(login.trim(), honeypot);
      setMessage(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Permintaan tidak dapat diselesaikan.');
    } finally {
      setSubmitting(false);
    }
  };

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <p className="penalis-muted">
          Tautan hanya berlaku sekali. Jika email tidak masuk dalam beberapa menit, periksa folder spam
          atau coba lagi nanti.
        </p>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Kembali ke halaman login
        </button>
      </div>
    );
  }

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      {error ? <ErrorBanner message={error} /> : null}

      <div className="penalis-field">
        <label htmlFor="forgot_login">Email atau nama pengguna</label>
        <input
          id="forgot_login"
          type="text"
          value={login}
          onChange={(event) => {
            setLogin(event.target.value);
            setFieldError('');
          }}
          autoComplete="username"
          maxLength={254}
          placeholder="nama@email.com atau nama pengguna"
          aria-invalid={Boolean(fieldError)}
          aria-describedby={describedBy('forgot_login-hint', fieldError && 'forgot_login-error')}
          required
        />
        <FieldHint id="forgot_login-hint">Bisa berupa email atau nama pengguna.</FieldHint>
        <FieldError errors={{ forgot_login: fieldError }} name="forgot_login" />
      </div>

      {/* Honeypot: hidden from users, must remain empty. */}
      <div className="penalis-honeypot" aria-hidden="true">
        <label>
          Website
          <input
            type="text"
            tabIndex={-1}
            autoComplete="off"
            value={honeypot}
            onChange={(event) => setHoneypot(event.target.value)}
          />
        </label>
      </div>

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? <Spinner label="Mengirim…" /> : 'Kirim tautan pengaturan ulang'}
      </button>
    </form>
  );
}

/**
 * Legacy account activation request.
 *
 * Legacy contributors were created by an administrator and never received a
 * password, so they cannot sign in or reset a password. This form sends an
 * activation link that lets them set a password for the account they already
 * have, keeping their existing articles attached.
 *
 * The server response is deliberately generic, so the success notice repeats
 * the same conditional wording.
 */
function ActivateForm({ onDone }: { onDone: () => void }) {
  const [email, setEmail] = useState('');
  const [honeypot, setHoneypot] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');

    // The server answers generically for every outcome, so an empty or
    // malformed address would otherwise look like a successful request.
    const invalid = validateEmail(email);
    if (invalid) {
      setFieldError(invalid);
      return;
    }
    setFieldError('');
    setSubmitting(true);

    try {
      const result = await legacyApi.request(email.trim(), honeypot);
      setMessage(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Permintaan tidak dapat diselesaikan.');
    } finally {
      setSubmitting(false);
    }
  };

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <p className="penalis-muted">
          Tautan berlaku 24 jam dan hanya dapat digunakan sekali. Setelah kamu membuat kata sandi, admin
          akan meninjau akun sebelum kamu dapat menerbitkan karya tulis.
        </p>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Kembali ke halaman login
        </button>
      </div>
    );
  }

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      {error ? <ErrorBanner message={error} /> : null}

      <div className="penalis-field">
        <label htmlFor="activate_email">Email yang kamu pakai saat mengirim karya tulis</label>
        <input
          id="activate_email"
          type="email"
          value={email}
          onChange={(event) => {
            setEmail(event.target.value);
            setFieldError('');
          }}
          autoComplete="email"
          maxLength={254}
          placeholder="nama@email.com"
          aria-invalid={Boolean(fieldError)}
          aria-describedby={describedBy('activate_email-hint', fieldError && 'activate_email-error')}
          required
        />
        <FieldHint id="activate_email-hint">
          Tidak ingat email mana yang dipakai? Hubungi admin Penalis.
        </FieldHint>
        <FieldError errors={{ activate_email: fieldError }} name="activate_email" />
      </div>

      {/* Honeypot: hidden from users, must remain empty. */}
      <div className="penalis-honeypot" aria-hidden="true">
        <label>
          Website
          <input
            type="text"
            tabIndex={-1}
            autoComplete="off"
            value={honeypot}
            onChange={(event) => setHoneypot(event.target.value)}
          />
        </label>
      </div>

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? <Spinner label="Mengirim…" /> : 'Kirim tautan aktivasi'}
      </button>
    </form>
  );
}
