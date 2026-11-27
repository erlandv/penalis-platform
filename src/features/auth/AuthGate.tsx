/**
 * Login, registration, and verification notices for signed-out visitors.
 */

import { useState, type FormEvent } from 'react';

import { ApiError, legacyApi, passwordApi, registrationApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import { DEEPLINK, hasParam } from '../../lib/deeplink';
import type { FieldErrors } from '../../lib/types';
import {
  hasErrors,
  validateEmail,
  validatePasswordPair,
  validateRegistration,
  validateResetEmail,
} from '../../lib/validation';
import { describedBy } from '../../lib/a11y';
import { toActiveChallenge, type ActiveChallenge } from '../../lib/otp';
import { ErrorBanner, FieldError, FieldHint, HoneypotField, Notice } from '../../components/ui';
import { resolveEntryMode, shouldShowAuthBackNav } from './authNav';
import { EmailRequestForm } from './EmailRequestForm';
import { OtpForm } from '../../components/OtpForm';
import { PasswordInput } from '../../components/PasswordInput';
import { PasswordPairFields } from '../../components/PasswordPairFields';
import { TurnstileWidget } from '../../components/TurnstileWidget';
import { TURNSTILE_REQUIRED_MESSAGE, useTurnstileGate } from '../../components/useTurnstileGate';

type Mode = 'login' | 'register' | 'verify' | 'forgot' | 'activate' | 'activateVerify';

type Challenge = ActiveChallenge;

const HEADINGS: Record<Mode, string> = {
  login: 'Login ke Dashboard',
  register: 'Buat Akun Penalis',
  verify: 'Masukkan Kode Verifikasi',
  forgot: 'Lupa Password',
  activate: 'Aktifkan Akun Kontributor',
  activateVerify: 'Verifikasi Akun Kontributor',
};

const DESCRIPTIONS: Record<Mode, string> = {
  login: 'Login dengan akun Penalis untuk mengakses dashboard.',
  register: 'Buat akun Penalis untuk mengakses dashboard.',
  verify: 'Masukkan kode OTP yang kami kirim ke email kamu untuk mengaktifkan akun.',
  forgot: 'Masukkan email yang terdaftar dengan akun Penalis kamu untuk mengatur ulang password.',
  activate: 'Sudah pernah mengirim karya tulis dan menjadi kontributor Penalis sebelumnya?',
  activateVerify: 'Masukkan kode OTP yang kami kirim ke email kamu, lalu buat password baru.',
};

/**
 * Which mode a deep link opens lives in `authNav.ts` so the fallback for a
 * closed registration can be unit tested without rendering the form.
 */

export function AuthGate() {
  const bootstrap = getBootstrap();
  // Deep links open one form each; registration is the only one that can be
  // closed site-wide, so a stale ?penalis_register=1 link lands on login.
  const [mode, setMode] = useState<Mode>(() =>
    resolveEntryMode(
      {
        register: hasParam(DEEPLINK.register),
        activate: hasParam(DEEPLINK.activate),
        forgot: hasParam(DEEPLINK.forgot),
      },
      bootstrap.registration,
    ),
  );
  // Explains the fallback above, so the visitor does not think the link is broken.
  const [registrationClosed] = useState(
    () => hasParam(DEEPLINK.register) && !bootstrap.registration,
  );
  const [challenge, setChallenge] = useState<Challenge | null>(null);
  // Kept outside ActivateForm so a writer who mistyped the address can step
  // back from the code screen and correct it without retyping everything.
  const [activateEmail, setActivateEmail] = useState('');
  // A success notice carries its own login CTA. Remembering that state keeps
  // the shared bottom link from offering the same action a second time.
  const [finished, setFinished] = useState(false);

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

  const [turnstileFailed] = useState(() => {
    const url = new URL(window.location.href);

    if (!url.searchParams.has(DEEPLINK.turnstileError)) {
      return false;
    }

    url.searchParams.delete(DEEPLINK.turnstileError);
    window.history.replaceState({}, '', url.toString());

    return true;
  });

  const goToMode = (next: Mode) => {
    setFinished(false);
    setMode(next);
  };

  const startChallenge = (next: Challenge, nextMode: Mode) => {
    setChallenge(next);
    goToMode(nextMode);
  };

  const backToLogin = () => {
    setChallenge(null);
    goToMode('login');
  };

  const backToActivate = () => {
    setChallenge(null);
    goToMode('activate');
  };

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <h1>{HEADINGS[mode]}</h1>
        <p className="penalis-muted">{DESCRIPTIONS[mode]}</p>

        {mode === 'login' && loginFailed ? (
          <ErrorBanner message="Username/email/password salah, atau akun ini belum bisa login." />
        ) : null}
        {mode === 'login' && turnstileFailed ? (
          <ErrorBanner message="Verifikasi keamanan (CAPTCHA) gagal atau kedaluwarsa. Silakan coba lagi." />
        ) : null}
        {mode === 'login' && registrationClosed ? (
          <Notice tone="warning">
            Pendaftaran penulis baru sedang ditutup. Silakan login dengan akun yang sudah ada.
          </Notice>
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
            onFinished={() => setFinished(true)}
          />
        ) : null}
        {mode === 'verify' && !challenge ? (
          <p className="penalis-muted">
            Sesi verifikasi tidak ditemukan. Silakan login, atau minta kode verifikasi baru.
          </p>
        ) : null}
        {mode === 'forgot' ? (
          <ForgotForm onDone={backToLogin} onFinished={() => setFinished(true)} />
        ) : null}
        {mode === 'activate' ? (
          <ActivateForm
            email={activateEmail}
            onEmailChange={setActivateEmail}
            onChallenge={(next) => startChallenge(next, 'activateVerify')}
          />
        ) : null}
        {mode === 'activateVerify' && challenge ? (
          <ActivateVerifyForm
            challenge={challenge}
            onChallenge={(next) => setChallenge(next)}
            onDone={backToLogin}
            onEditEmail={backToActivate}
            onFinished={() => setFinished(true)}
          />
        ) : null}
        {mode === 'activateVerify' && !challenge ? (
          <p className="penalis-muted">
            Sesi aktivasi tidak ditemukan. Silakan login, atau minta kode aktivasi baru.
          </p>
        ) : null}

        <div className="penalis-auth__alt">
          {mode === 'login' && bootstrap.registration ? (
            <button
              type="button"
              className="penalis-button penalis-auth__alt-action"
              onClick={() => goToMode('register')}
            >
              Buat Akun Penalis
            </button>
          ) : null}

          {mode === 'login' ? (
            <button
              type="button"
              className="penalis-button penalis-auth__alt-action"
              onClick={() => goToMode('activate')}
            >
              Aktifkan Akun Kontributor
            </button>
          ) : null}

          {shouldShowAuthBackNav(mode, finished) ? (
            <button type="button" className="penalis-auth__back" onClick={backToLogin}>
              Kembali ke Halaman Login
            </button>
          ) : null}
        </div>

        <div className="penalis-auth__utility">
          {mode === 'login' ? (
            <button type="button" className="penalis-link-button" onClick={() => goToMode('forgot')}>
              Lupa Password?
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function LoginForm() {
  const bootstrap = getBootstrap();
  const turnstile = useTurnstileGate('login');

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    if (turnstile.isMissing()) {
      event.preventDefault();
      turnstile.setError(TURNSTILE_REQUIRED_MESSAGE);
      return;
    }
    turnstile.setError('');
  };

  return (
    <form
      className="penalis-form"
      method="post"
      action={bootstrap.dashboardUrl}
      onSubmit={handleSubmit}
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
          required
        />
      </div>

      <div className="penalis-field">
        <label htmlFor="login_password">Password</label>
        <PasswordInput
          id="login_password"
          name="pwd"
          autoComplete="current-password"
          required
        />
      </div>

      <label className="penalis-checkbox">
        <input type="checkbox" name="rememberme" value="forever" />
        <span>Simpan sesi login</span>
      </label>

      <TurnstileWidget
        form="login"
        onTokenChange={turnstile.onTokenChange}
        error={turnstile.error}
      />

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
  const turnstile = useTurnstileGate('register');

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
    if (turnstile.isMissing()) {
      setErrors({});
      turnstile.setError(TURNSTILE_REQUIRED_MESSAGE);
      return;
    }
    setErrors({});
    turnstile.setError('');
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
        ...(turnstile.token ? { cf_turnstile_response: turnstile.token } : {}),
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

      onChallenge(toActiveChallenge(result, values.user_email.trim()));
    } catch (error) {
      turnstile.reset();
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
          aria-invalid={Boolean(errors.user_login)}
          aria-describedby={describedBy('user_login-hint', errors.user_login && 'user_login-error')}
        />
        <FieldHint id="user_login-hint">
          Gunakan huruf kecil, angka, garis bawah, atau tanda hubung.
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
          aria-invalid={Boolean(errors.user_email)}
          aria-describedby={describedBy('user_email-hint', errors.user_email && 'user_email-error')}
        />
        <FieldHint id="user_email-hint">Kode verifikasi akan dikirim ke email ini.</FieldHint>
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
          aria-invalid={Boolean(errors.instagram)}
          aria-describedby={describedBy('instagram-hint', errors.instagram && 'instagram-error')}
        />
        <FieldHint id="instagram-hint">
          Digunakan untuk keperluan internal Senator Penalis.
        </FieldHint>
        <FieldError errors={errors} name="instagram" />
      </div>

      <PasswordPairFields
        passwordId="password"
        confirmId="confirm_password"
        passwordHintId="password-hint"
        confirmHintId="confirm_password-hint"
        password={values.password}
        confirm={values.confirm_password}
        onPasswordChange={(event) => update('password', event.target.value)}
        onConfirmChange={(event) => update('confirm_password', event.target.value)}
        errors={errors}
      />

      <HoneypotField
        value={values.penalis_website}
        onChange={(value) => update('penalis_website', value)}
      />

      <label className="penalis-checkbox">
        <input
          type="checkbox"
          checked={values.penalis_consent}
          onChange={(event) => update('penalis_consent', event.target.checked)}
          aria-invalid={Boolean(errors.penalis_consent)}
          aria-describedby={describedBy(errors.penalis_consent && 'penalis_consent-error')}
        />
        <span>
          Telah membaca dan menyetujui{' '}
          <a
            href={getBootstrap().config?.termsUrl || '/ketentuan-layanan/'}
            target="_blank"
            rel="noopener noreferrer"
            onClick={(event) => event.stopPropagation()}
          >
            Ketentuan Layanan
          </a>{' '}
          dan{' '}
          <a
            href={getBootstrap().config?.privacyUrl || '/kebijakan-privasi/'}
            target="_blank"
            rel="noopener noreferrer"
            onClick={(event) => event.stopPropagation()}
          >
            Kebijakan Privasi
          </a>
          .
        </span>
      </label>
      <FieldError errors={errors} name="penalis_consent" />

      <TurnstileWidget
        form="register"
        resetKey={turnstile.resetKey}
        onTokenChange={turnstile.onTokenChange}
        error={turnstile.error}
      />

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
  onFinished,
}: {
  challenge: Challenge;
  onChallenge: (challenge: Challenge) => void;
  onDone: () => void;
  onFinished: () => void;
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
      onFinished();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Kode verifikasi tidak dapat diproses.');
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    const result = await registrationApi.resend(challenge.email);
    const next = toActiveChallenge(result, challenge.email);
    onChallenge(next);

    return { challenge_id: next.challengeId, resend_after: next.resendAfter };
  };

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Lanjut Login ke Dashboard
        </button>
      </div>
    );
  }

  return (
    <OtpForm
      challengeId={challenge.challengeId}
      email={challenge.email}
      resendAfter={challenge.resendAfter}
      expiresIn={challenge.expiresIn}
      maxAttempts={challenge.maxAttempts}
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
  onEditEmail,
  onFinished,
}: {
  challenge: Challenge;
  onChallenge: (challenge: Challenge) => void;
  onDone: () => void;
  onEditEmail: () => void;
  onFinished: () => void;
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

    const clientErrors = validatePasswordPair(password, confirm);
    if (hasErrors(clientErrors)) {
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
      onFinished();
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
    const next = toActiveChallenge(result, challenge.email);
    onChallenge(next);

    return { challenge_id: next.challengeId, resend_after: next.resendAfter };
  };

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <p className="penalis-muted">
          Karya tulis yang sudah terbit tetap terhubung ke akun ini. Akun kamu kini sudah aktif dan siap
          digunakan di dashboard penulis.
        </p>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Lanjut Login ke Dashboard
        </button>
      </div>
    );
  }

  return (
    <OtpForm
      challengeId={challenge.challengeId}
      email={challenge.email}
      resendAfter={challenge.resendAfter}
      expiresIn={challenge.expiresIn}
      maxAttempts={challenge.maxAttempts}
      onVerify={verify}
      onResend={resend}
      error={error}
      busy={busy}
      verifyLabel="Verifikasi dan Aktifkan Akun"
      onEditEmail={onEditEmail}
    >
      <PasswordPairFields
        passwordId="activate_password"
        confirmId="activate_confirm_password"
        passwordHintId="activate_password-hint"
        confirmHintId="confirm_password-hint"
        passwordLabel="Password Baru"
        password={password}
        confirm={confirm}
        onPasswordChange={(event) => setPassword(event.target.value)}
        onConfirmChange={(event) => setConfirm(event.target.value)}
        errors={errors}
      />
    </OtpForm>
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
function ForgotForm({ onDone, onFinished }: { onDone: () => void; onFinished: () => void }) {
  const [email, setEmail] = useState('');
  const [message, setMessage] = useState('');

  if (message) {
    return (
      <div className="penalis-form">
        <Notice tone="success">{message}</Notice>
        <p className="penalis-muted">
          Link reset password hanya dapat digunakan sekali. Jika email belum diterima 
          dalam beberapa menit, periksa folder spam atau coba lagi nanti.
        </p>
        <button type="button" className="penalis-button penalis-button--primary" onClick={onDone}>
          Kembali ke Halaman Login
        </button>
      </div>
    );
  }

  return (
    <EmailRequestForm
      form="forgot"
      emailId="forgot_email"
      email={email}
      onEmailChange={setEmail}
      validate={validateResetEmail}
      request={passwordApi.forgot}
      onSuccess={(result) => {
        setMessage(result.message);
        onFinished();
      }}
      hint="Gunakan email yang terdaftar di akun kamu."
      submitLabel="Reset Password"
      busyLabel="Memproses…"
    />
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
function ActivateForm({
  email,
  onEmailChange,
  onChallenge,
}: {
  email: string;
  onEmailChange: (email: string) => void;
  onChallenge: (challenge: Challenge) => void;
}) {
  return (
    <EmailRequestForm
      form="activate"
      emailId="activate_email"
      email={email}
      onEmailChange={onEmailChange}
      validate={validateEmail}
      request={legacyApi.request}
      onSuccess={(result) => {
        onEmailChange(email.trim());
        onChallenge(toActiveChallenge(result, email.trim()));
      }}
      description="Kami sudah membuatkan akun untukmu. Aktifkan dengan cara memverifikasi email yang kamu pakai saat mengirim karya, lalu buat password untuk akun tersebut."
      hint="Tidak ingat email mana yang dipakai? Hubungi Senator Penalis."
      submitLabel="Kirim Kode Aktivasi"
      busyLabel="Mengirim…"
    />
  );
}
