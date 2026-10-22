/**
 * Login, registration, and verification notices for signed-out visitors.
 */

import { useState, type FormEvent } from 'react';

import { ApiError, registrationApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import type { FieldErrors } from '../../lib/types';
import { hasErrors, validateRegistration } from '../../lib/validation';
import { ErrorBanner, FieldError, Notice, Spinner } from '../../components/ui';

type Mode = 'login' | 'register' | 'resend';

export function AuthGate() {
  const bootstrap = getBootstrap();
  const [mode, setMode] = useState<Mode>('login');

  return (
    <div className="penalis-auth">
      <div className="penalis-auth__panel">
        <p className="penalis-eyebrow">{bootstrap.siteName}</p>
        <h1>{mode === 'register' ? 'Apply to write for Penalis' : 'Writer sign in'}</h1>
        <p className="penalis-muted">
          {mode === 'register'
            ? 'Create an account, confirm your email address, and an administrator will review your application.'
            : 'Sign in with your Penalis writer account to manage your articles.'}
        </p>

        {mode === 'login' ? <LoginForm /> : null}
        {mode === 'register' ? <RegistrationForm onDone={() => setMode('login')} /> : null}
        {mode === 'resend' ? <ResendForm /> : null}

        <div className="penalis-auth__switch">
          {mode !== 'login' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('login')}>
              Back to sign in
            </button>
          ) : null}

          {mode === 'login' && bootstrap.registration ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('register')}>
              Create a writer account
            </button>
          ) : null}

          {mode !== 'resend' ? (
            <button type="button" className="penalis-link-button" onClick={() => setMode('resend')}>
              Resend verification email
            </button>
          ) : null}
        </div>

        <p className="penalis-fineprint">
          <a href={bootstrap.lostPassword}>Forgot your password?</a>
        </p>
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
      action={bootstrap.loginPostUrl ?? '/wp-login.php'}
      noValidate
    >
      <input type="hidden" name="redirect_to" value={bootstrap.dashboardUrl} />
      <input type="hidden" name="testcookie" value="1" />

      <label className="penalis-field">
        <span>Username or email</span>
        <input type="text" name="log" autoComplete="username" required />
      </label>

      <label className="penalis-field">
        <span>Password</span>
        <input type="password" name="pwd" autoComplete="current-password" required />
      </label>

      <label className="penalis-checkbox">
        <input type="checkbox" name="rememberme" value="forever" />
        <span>Keep me signed in</span>
      </label>

      <button type="submit" className="penalis-button penalis-button--primary">
        Sign in
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
        setFormError('Registration could not be completed. Please try again.');
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
        <span>Display name</span>
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
        <span>Username</span>
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
        <span>Email address</span>
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
        <span>Password</span>
        <input
          type="password"
          value={values.password}
          onChange={(event) => update('password', event.target.value)}
          autoComplete="new-password"
          aria-invalid={Boolean(errors.password)}
          aria-describedby={errors.password ? 'password-error' : undefined}
        />
        <small>At least 12 characters.</small>
        <FieldError errors={errors} name="password" />
      </label>

      <label className="penalis-field">
        <span>Confirm password</span>
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
        <span>I agree to the site terms and understand my application requires administrator approval.</span>
      </label>
      <FieldError errors={errors} name="penalis_consent" />

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? 'Creating account…' : 'Create account'}
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
      setError(caught instanceof ApiError ? caught.message : 'The request could not be completed.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      <p className="penalis-muted">
        Enter the email address you registered with. If the account is awaiting verification, we will send a new
        link.
      </p>

      {error ? <ErrorBanner message={error} /> : null}
      {message ? <Notice tone="success">{message}</Notice> : null}

      <label className="penalis-field">
        <span>Email address</span>
        <input
          type="email"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          autoComplete="email"
          required
        />
      </label>

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? <Spinner label="Sending…" /> : 'Send verification email'}
      </button>
    </form>
  );
}
