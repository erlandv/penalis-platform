/**
 * Login, registration, and verification notices for signed-out visitors.
 */

import { useState, type FormEvent } from 'react';

import { ApiError, legacyApi, passwordApi, registrationApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import { DEEPLINK, hasParam } from '../../lib/deeplink';
import type { FieldErrors } from '../../lib/types';
import { hasErrors, validateEmail, validateRegistration, validateResetEmail } from '../../lib/validation';
import { PASSWORD_MIN_LENGTH } from '../../lib/validation';
import { describedBy } from '../../lib/a11y';
import { ErrorBanner, FieldError, FieldHint, Notice, Spinner } from '../../components/ui';
import { OtpForm } from '../../components/OtpForm';
import { PasswordInput } from '../../components/PasswordInput';

type Mode = 'login' | 'register' | 'verify' | 'resend' | 'forgot' | 'activate' | 'activateVerify';

/**
 * A verification code that was just issued.
 *
 * The challenge ID is an opaque locator, not a secret. It lives in component
 * state only, so closing the tab discards the flow.
 */
interface Challenge {
  challengeId: string;
  email: string;
  resendAfter: number;
}

const HEADINGS: Record<Mode, string> = {
  login: 'Login ke Dashboard',
  register: 'Buat Akun Penalis',
  verify: 'Masukkan Kode Verifikasi',
  resend: 'Kirim Ulang Kode Verifikasi',
  forgot: 'Lupa Password',
  activate: 'Aktifkan Akun Kontributor',
  activateVerify: 'Verifikasi Akun Kontributor',
};

const DESCRIPTIONS: Record<Mode, string> = {
  login: 'Login ke dashboard dengan akun Penalis untuk mengelola karya tulis.',
  register: 'Buat akun Penalis untuk mengakses dashboard dan mulai menulis di Penalis.',
  verify: 'Masukkan kode enam digit yang kami kirim ke email kamu untuk mengaktifkan akun.',
  resend: 'Minta kode verifikasi baru untuk akun yang belum dikonfirmasi.',
  forgot: 'Masukkan email kamu. Kami akan mengirim link untuk reset password.',
  activate: 'Sudah pernah mengirim karya tulis dan menjadi kontributor Penalis sebelumnya?',
  activateVerify: 'Masukkan kode dari email kamu, lalu buat kata sandi untuk akun tersebut.',
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
 * | `penalis_resend=1` | Verification code resend |
 */
function initialMode(): Mode {
  if (hasParam(DEEPLINK.register)) {
    return 'register';
  }

  if (hasParam(DEEPLINK.activate)) {
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
  const [challenge, setChallenge] = useState<Challenge | null>(null);

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

  const startChallenge = (next: Challenge, nextMode: Mode) => {
    setChallenge(next);
    setMode(nextMode);
  };

  const backToLogin = () => {
    setChallenge(null);
    setMode('login');
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <h1>{HEADINGS[mode]}</h1>
        <p className="penalis-muted">{DESCRIPTIONS[mode]}</p>

        {mode === 'login' && loginFailed ? (
          <ErrorBanner message="Username/email/password salah, atau akun ini belum bisa login." />
        ) : null}

        {mode === 'login' ? <LoginForm /> : null}
        {mode === 'register' ? (
          <RegistrationForm onChallenge={(next) => startChallenge(next, 'verify')} />
        ) : null}
        {mode === 'verify' && challenge ? (
          <RegistrationVerifyForm
            challenge={challenge}
            onChallenge={(next) => setChallenge(next)}
            onDone={backToLogin}
          />
        ) : null}
        {mode === 'verify' && !challenge ? (
          <p className="penalis-muted">
            Sesi verifikasi tidak ditemukan. Silakan masuk, atau minta kode verifikasi baru.
          </p>
        ) : null}
        {mode === 'resend' ? (
          <ResendForm onChallenge={(next) => startChallenge(next, 'verify')} />
        ) : null}
        {mode === 'forgot' ? <ForgotForm onDone={backToLogin} /> : null}
        {mode === 'activate' ? (
          <ActivateForm onChallenge={(next) => startChallenge(next, 'activateVerify')} />
        ) : null}
        {mode === 'activateVerify' && challenge ? (
          <ActivateVerifyForm
            challenge={challenge}
            onChallenge={(next) => setChallenge(next)}
            onDone={backToLogin}
          />
        ) : null}
        {mode === 'activateVerify' && !challenge ? (
          <p className="penalis-muted">
            Sesi aktivasi tidak ditemukan. Silakan masuk, atau minta kode aktivasi baru.
          </p>
        ) : null}

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
              Aktifkan Akun Kontributor
            </button>
          ) : null}

          {mode !== 'login' ? (
            <button type="button" className="penalis-auth__back" onClick={backToLogin}>
              Kembali ke Halaman Login
            </button>
          ) : null}
        </div>

        <div className="penalis-auth__utility">
          {mode === 'login' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('forgot')}>
              Lupa Password?
            </button>
          ) : null}

          {mode !== 'resend' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('resend')}>
              Kirim Ulang Kode Verifikasi
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
        <label htmlFor="login">Username atau Email</label>
        <input
          id="login"
          type="text"
          name="log"
          autoComplete="username"
          placeholder="username atau email kamu"
          required
        />
      </div>

      <div className="penalis-field">
        <label htmlFor="login_password">Password</label>
        <PasswordInput
          id="login_password"
          name="pwd"
          autoComplete="current-password"
          placeholder="Password kamu"
          required
        />
      </div>

      <label className="penalis-checkbox">
        <input type="checkbox" name="rememberme" value="forever" />
        <span>Simpan sesi login</span>
      </label>

      <button type="submit" className="penalis-button penalis-button--primary">
        Login ke Dashboard
      </button>
    </form>
  );
}

function RegistrationForm({ onChallenge }: { onChallenge: (challenge: Challenge) => void }) {
  const [values, setValues] = useState({
    display_name: '',
    user_login: '',
    user_email: '',
    instagram: '',
    password: '',
    confirm_password: '',
    penalis_consent: false,
    penalis_website: '',
  });
  const [errors, setErrors] = useState<FieldErrors>({});
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const update = (key: keyof typeof values, value: string | boolean) => {
    setValues((current) => ({ ...current, [key]: value }));
  };

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError('');

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
        instagram: values.instagram,
        password: values.password,
        penalis_consent: '1',
        penalis_website: values.penalis_website,
      });

      // The password is no longer needed: the account exists and only the
      // emailed code can activate it.
      setValues({
        display_name: '',
        user_login: '',
        user_email: '',
        instagram: '',
        password: '',
        confirm_password: '',
        penalis_consent: false,
        penalis_website: '',
      });

      onChallenge({
        challengeId: result.challenge_id,
        email: values.user_email.trim(),
        resendAfter: result.resend_after,
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

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      {formError ? <ErrorBanner message={formError} /> : null}

      <div className="penalis-field">
        <label htmlFor="display_name">Nama</label>
        <input
          id="display_name"
          type="text"
          value={values.display_name}
          onChange={(event) => update('display_name', event.target.value)}
          maxLength={80}
          autoComplete="name"
          placeholder="Nama kamu"
          aria-invalid={Boolean(errors.display_name)}
          aria-describedby={describedBy('display_name-hint', errors.display_name && 'display_name-error')}
        />
        <FieldHint id="display_name-hint">Nama ini akan tampil di profil publik.</FieldHint>
        <FieldError errors={errors} name="display_name" />
      </div>

      <div className="penalis-field">
        <label htmlFor="user_login">Username</label>
        <input
          id="user_login"
          type="text"
          value={values.user_login}
          onChange={(event) => update('user_login', event.target.value)}
          maxLength={50}
          autoComplete="username"
          placeholder="Username kamu"
          aria-invalid={Boolean(errors.user_login)}
          aria-describedby={describedBy('user_login-hint', errors.user_login && 'user_login-error')}
        />
        <FieldHint id="user_login-hint">
          3-50 karakter: huruf kecil, angka, garis bawah, atau tanda hubung. Titik tidak diperbolehkan.
        </FieldHint>
        <FieldError errors={errors} name="user_login" />
      </div>

      <div className="penalis-field">
        <label htmlFor="user_email">Email</label>
        <input
          id="user_email"
          type="email"
          value={values.user_email}
          onChange={(event) => update('user_email', event.target.value)}
          maxLength={254}
          autoComplete="email"
          placeholder="Email kamu"
          aria-invalid={Boolean(errors.user_email)}
          aria-describedby={describedBy('user_email-hint', errors.user_email && 'user_email-error')}
        />
        <FieldHint id="user_email-hint">Kami mengirim kode verifikasi enam digit ke email ini.</FieldHint>
        <FieldError errors={errors} name="user_email" />
      </div>

      <div className="penalis-field">
        <label htmlFor="instagram">Username Instagram (opsional)</label>
        <input
          id="instagram"
          type="text"
          value={values.instagram}
          onChange={(event) => update('instagram', event.target.value)}
          maxLength={100}
          placeholder="@username"
          aria-invalid={Boolean(errors.instagram)}
          aria-describedby={describedBy('instagram-hint', errors.instagram && 'instagram-error')}
        />
        <FieldHint id="instagram-hint">
          Opsional, digunakan untuk keperluan internal admin Penalis.
        </FieldHint>
        <FieldError errors={errors} name="instagram" />
      </div>

      <div className="penalis-field">
        <label htmlFor="password">Password</label>
        <PasswordInput
          id="password"
          value={values.password}
          onChange={(event) => update('password', event.target.value)}
          autoComplete="new-password"
          placeholder="Password kamu"
          aria-invalid={Boolean(errors.password)}
          aria-describedby={describedBy('password-hint', errors.password && 'password-error')}
        />
        <FieldHint id="password-hint">Minimal 12 karakter.</FieldHint>
        <FieldError errors={errors} name="password" />
      </div>

      <div className="penalis-field">
        <label htmlFor="confirm_password">Konfirmasi Password</label>
        <PasswordInput
          id="confirm_password"
          value={values.confirm_password}
          onChange={(event) => update('confirm_password', event.target.value)}
          autoComplete="new-password"
          placeholder="Ulangi password kamu"
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
        <span>Telah membaca dan menyetujui Ketentuan Layanan dan Kebijakan Privasi.</span>
      </label>
      <FieldError errors={errors} name="penalis_consent" />

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? 'Membuat akun…' : 'Buat Akun Penalis'}
      </button>
    </form>
  );
}

/**
 * Registration code entry.
 *
 * The account already exists in `pending_verification`; only the emailed code
 * can activate it. The code and challenge ID stay in component state.
 */
function RegistrationVerifyForm({
  challenge,
  onChallenge,
  onDone,
}: {
  challenge: Challenge;
  onChallenge: (challenge: Challenge) => void;
  onDone: () => void;
}) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');

  const verify = async (code: string) => {
    setError('');
    setBusy(true);

    try {
      const result = await registrationApi.confirm({ challenge_id: challenge.challengeId, code });
      setMessage(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Kode verifikasi tidak dapat diproses.');
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    const result = await registrationApi.resend(challenge.email);
    const next = {
      challengeId: result.challenge_id,
      email: challenge.email,
      resendAfter: result.resend_after,
    };
    onChallenge(next);

    return { challenge_id: next.challengeId, resend_after: next.resendAfter };
  };

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Lanjut ke halaman masuk
        </button>
      </div>
    );
  }

  return (
    <OtpForm
      challengeId={challenge.challengeId}
      email={challenge.email}
      resendAfter={challenge.resendAfter}
      onVerify={verify}
      onResend={resend}
      error={error}
      busy={busy}
    />
  );
}

/**
 * Legacy activation code entry plus first password.
 *
 * The code proves control of the stored address; the password is set in the
 * same request so the account is usable immediately afterwards.
 */
function ActivateVerifyForm({
  challenge,
  onChallenge,
  onDone,
}: {
  challenge: Challenge;
  onChallenge: (challenge: Challenge) => void;
  onDone: () => void;
}) {
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');

  const verify = async (code: string) => {
    setError('');
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

    setBusy(true);

    try {
      const result = await legacyApi.confirm({
        challenge_id: challenge.challengeId,
        code,
        password,
      });
      setMessage(result.message);
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Akun tidak dapat diaktifkan. Silakan coba lagi.');
      }
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    const result = await legacyApi.request(challenge.email);
    const next = {
      challengeId: result.challenge_id,
      email: challenge.email,
      resendAfter: result.resend_after,
    };
    onChallenge(next);

    return { challenge_id: next.challengeId, resend_after: next.resendAfter };
  };

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <p className="penalis-muted">
          Karya tulis yang sudah terbit tetap terhubung ke akun ini. Akun kamu kini sudah aktif dan siap
          digunakan di dasbor penulis.
        </p>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Lanjut ke halaman masuk
        </button>
      </div>
    );
  }

  return (
    <OtpForm
      challengeId={challenge.challengeId}
      email={challenge.email}
      resendAfter={challenge.resendAfter}
      onVerify={verify}
      onResend={resend}
      error={error}
      busy={busy}
      verifyLabel="Verifikasi dan aktifkan akun"
    >
      <div className="penalis-field">
        <label htmlFor="activate_password">Kata sandi baru</label>
        <PasswordInput
          id="activate_password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          autoComplete="new-password"
          placeholder={`Minimal ${PASSWORD_MIN_LENGTH} karakter`}
          aria-invalid={Boolean(errors.password)}
          aria-describedby={describedBy('activate_password-hint', errors.password && 'password-error')}
        />
        <FieldHint id="activate_password-hint">Minimal {PASSWORD_MIN_LENGTH} karakter.</FieldHint>
        <FieldError errors={errors} name="password" />
      </div>

      <div className="penalis-field">
        <label htmlFor="activate_confirm_password">Konfirmasi kata sandi</label>
        <PasswordInput
          id="activate_confirm_password"
          value={confirm}
          onChange={(event) => setConfirm(event.target.value)}
          autoComplete="new-password"
          placeholder="Ulangi kata sandi"
          aria-invalid={Boolean(errors.confirm_password)}
          aria-describedby={describedBy(errors.confirm_password && 'confirm_password-error')}
        />
        <FieldError errors={errors} name="confirm_password" />
      </div>
    </OtpForm>
  );
}

function ResendForm({ onChallenge }: { onChallenge: (challenge: Challenge) => void }) {
  const [email, setEmail] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');

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
      onChallenge({
        challengeId: result.challenge_id,
        email: email.trim(),
        resendAfter: result.resend_after,
      });
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Permintaan tidak dapat diselesaikan.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      <p className="penalis-muted">
        Masukkan email yang kamu gunakan saat membuat akun. Jika akun sedang menunggu verifikasi, kami akan
        mengirim kode verifikasi baru.
      </p>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="penalis-field">
        <label htmlFor="resend_email">Email</label>
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
          placeholder="Email kamu"
          aria-invalid={Boolean(fieldError)}
          aria-describedby={describedBy('resend_email-hint', fieldError && 'resend_email-error')}
          required
        />
        <FieldHint id="resend_email-hint">Gunakan email yang kamu pakai saat membuat akun.</FieldHint>
        <FieldError errors={{ resend_email: fieldError }} name="resend_email" />
      </div>

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? <Spinner label="Mengirim…" /> : 'Kirim Kode Verifikasi'}
      </button>
    </form>
  );
}

/**
 * Password reset request.
 *
 * Only an email address is accepted. Usernames are public on author archives,
 * so they must not work as a reset handle. The server response is deliberately
 * generic, so the success notice repeats the same conditional wording instead
 * of confirming that the account exists.
 */
function ForgotForm({ onDone }: { onDone: () => void }) {
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

    // The server answers generically for every outcome, so an empty field
    // would otherwise look like a successful request.
    const invalid = validateResetEmail(email);
    if (invalid) {
      setFieldError(invalid);
      return;
    }
    setFieldError('');
    setSubmitting(true);

    try {
      const result = await passwordApi.forgot(email.trim(), honeypot);
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
          Link reset password hanya dapat digunakan sekali. Jika email tidak masuk dalam beberapa menit, periksa folder
          spam atau coba lagi nanti.
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
        <label htmlFor="forgot_email">Email</label>
        <input
          id="forgot_email"
          type="email"
          value={email}
          onChange={(event) => {
            setEmail(event.target.value);
            setFieldError('');
          }}
          autoComplete="email"
          maxLength={254}
          placeholder="Email kamu"
          aria-invalid={Boolean(fieldError)}
          aria-describedby={describedBy('forgot_email-hint', fieldError && 'forgot_email-error')}
          required
        />
        <FieldHint id="forgot_email-hint">
          Gunakan email yang terdaftar di akun kamu. Username tidak dapat dipakai untuk reset password.
        </FieldHint>
        <FieldError errors={{ forgot_email: fieldError }} name="forgot_email" />
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
        {submitting ? <Spinner label="Mengirim…" /> : 'Kirim Link Reset Password'}
      </button>
    </form>
  );
}

/**
 * Legacy account activation request.
 *
 * Legacy contributors were created by an administrator and never received a
 * password, so they cannot sign in or reset a password. This form sends a
 * verification code that lets them prove control of the stored address and set
 * a first password, keeping their existing articles attached.
 *
 * The server response is deliberately generic, so the next screen never
 * confirms whether the address belongs to a legacy account.
 */
function ActivateForm({ onChallenge }: { onChallenge: (challenge: Challenge) => void }) {
  const [email, setEmail] = useState('');
  const [honeypot, setHoneypot] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');

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
      onChallenge({
        challengeId: result.challenge_id,
        email: email.trim(),
        resendAfter: result.resend_after,
      });
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Permintaan tidak dapat diselesaikan.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      <p className="penalis-muted">
        Kami sudah membuatkan akun untukmu. Aktifkan dengan cara memverifikasi email yang kamu pakai saat
        mengirim karya, lalu buat password untuk akun tersebut.
      </p>

      {error ? <ErrorBanner message={error} /> : null}

      <div className="penalis-field">
        <label htmlFor="activate_email">Email</label>
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
          placeholder="Email kamu"
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
        {submitting ? <Spinner label="Mengirim…" /> : 'Kirim Kode Aktivasi'}
      </button>
    </form>
  );
}
