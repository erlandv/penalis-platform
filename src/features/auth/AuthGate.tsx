/**
 * Login, registration, and verification notices for signed-out visitors.
 */

import { useState, type FormEvent } from 'react';

import { ApiError, passwordApi, registrationApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import type { FieldErrors } from '../../lib/types';
import { hasErrors, validateRegistration } from '../../lib/validation';
import { ErrorBanner, FieldError, Notice, Spinner } from '../../components/ui';

type Mode = 'login' | 'register' | 'resend' | 'forgot';

const HEADINGS: Record<Mode, string> = {
  login: 'Login',
  register: 'Buat akun Penalis',
  resend: 'Kirim ulang email verifikasi',
  forgot: 'Lupa kata sandi',
};

const DESCRIPTIONS: Record<Mode, string> = {
  login: 'Masuk dengan akun Penalis untuk mengelola karya tulis.',
  register: 'Buat akun Penalis untuk mulai menulis di Penalis.',
  resend: 'Minta tautan verifikasi baru untuk akun yang belum dikonfirmasi.',
  forgot: 'Masukkan email atau nama pengguna kamu. Kami akan mengirim tautan untuk mengatur ulang kata sandi.',
};

export function AuthGate() {
  const bootstrap = getBootstrap();
  // Deep links such as ?penalis_forgot=1 open the reset request directly.
  const [mode, setMode] = useState<Mode>(() =>
    new URLSearchParams(window.location.search).has('penalis_forgot') ? 'forgot' : 'login',
  );

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        
        <h1>{HEADINGS[mode]}</h1>
        <p className="penalis-muted">{DESCRIPTIONS[mode]}</p>

        {mode === 'login' ? <LoginForm onForgot={() => setMode('forgot')} /> : null}
        {mode === 'register' ? <RegistrationForm onDone={() => setMode('login')} /> : null}
        {mode === 'resend' ? <ResendForm /> : null}
        {mode === 'forgot' ? <ForgotForm onDone={() => setMode('login')} /> : null}

        <div className="penalis-auth__switch">
          {mode !== 'login' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('login')}>
              Kembali ke halaman login
            </button>
          ) : null}

          {mode === 'login' && bootstrap.registration ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('register')}>
              Buat Akun Penalis
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

function LoginForm({ onForgot }: { onForgot: () => void }) {
  const bootstrap = getBootstrap();

  return (
    <>
      <form
        className="penalis-form"
        method="post"
        action={bootstrap.loginPostUrl ?? '/wp-login.php'}
        noValidate
      >
        <input type="hidden" name="redirect_to" value={bootstrap.dashboardUrl} />
        <input type="hidden" name="testcookie" value="1" />

        <label className="penalis-field">
          <span>Nama pengguna atau email</span>
          <input type="text" name="log" autoComplete="username" required />
        </label>

        <label className="penalis-field">
          <span>Kata sandi</span>
          <input type="password" name="pwd" autoComplete="current-password" required />
        </label>

        <label className="penalis-checkbox">
          <input type="checkbox" name="rememberme" value="forever" />
          <span>Tetap masuk</span>
        </label>

        <button type="submit" className="penalis-button penalis-button--primary">
          Masuk
        </button>
      </form>

      <p className="penalis-fineprint">
        <button type="button" className="penalis-link-button" onClick={onForgot}>
          Lupa kata sandi?
        </button>
      </p>
    </>
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

      <label className="penalis-field">
        <span>Nama tampilan</span>
        <input
          type="text"
          value={values.display_name}
          onChange={(event) => update('display_name', event.target.value)}
          maxLength={80}
          autoComplete="name"
          aria-invalid={Boolean(errors.display_name)}
          aria-describedby={errors.display_name ? 'display_name-error' : undefined}
        />
        <FieldError errors={errors} name="display_name" />
      </label>

      <label className="penalis-field">
        <span>Nama pengguna</span>
        <input
          type="text"
          value={values.user_login}
          onChange={(event) => update('user_login', event.target.value)}
          maxLength={60}
          autoComplete="username"
          aria-invalid={Boolean(errors.user_login)}
          aria-describedby={errors.user_login ? 'user_login-error' : undefined}
        />
        <FieldError errors={errors} name="user_login" />
      </label>

      <label className="penalis-field">
        <span>Alamat email</span>
        <input
          type="email"
          value={values.user_email}
          onChange={(event) => update('user_email', event.target.value)}
          maxLength={254}
          autoComplete="email"
          aria-invalid={Boolean(errors.user_email)}
          aria-describedby={errors.user_email ? 'user_email-error' : undefined}
        />
        <FieldError errors={errors} name="user_email" />
      </label>

      <label className="penalis-field">
        <span>Kata sandi</span>
        <input
          type="password"
          value={values.password}
          onChange={(event) => update('password', event.target.value)}
          autoComplete="new-password"
          aria-invalid={Boolean(errors.password)}
          aria-describedby={errors.password ? 'password-error' : undefined}
        />
        <small>Minimal 12 karakter.</small>
        <FieldError errors={errors} name="password" />
      </label>

      <label className="penalis-field">
        <span>Konfirmasi kata sandi</span>
        <input
          type="password"
          value={values.confirm_password}
          onChange={(event) => update('confirm_password', event.target.value)}
          autoComplete="new-password"
          aria-invalid={Boolean(errors.confirm_password)}
          aria-describedby={errors.confirm_password ? 'confirm_password-error' : undefined}
        />
        <FieldError errors={errors} name="confirm_password" />
      </label>

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
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
    setSubmitting(true);

    try {
      const result = await registrationApi.resend(email);
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

      <label className="penalis-field">
        <span>Alamat email</span>
        <input
          type="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          autoComplete="email"
          required
        />
      </label>

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
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setMessage('');
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

      <label className="penalis-field">
        <span>Email atau nama pengguna</span>
        <input
          type="text"
          value={login}
          onChange={(event) => setLogin(event.target.value)}
          autoComplete="username"
          maxLength={254}
          required
        />
      </label>

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
