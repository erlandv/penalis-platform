/**
 * Writer profile: safe fields only.
 */

import { useState, type FormEvent } from 'react';

import { accountApi, ApiError } from '../../lib/api';
import type { Account, FieldErrors } from '../../lib/types';
import { ErrorBanner, FieldError, Notice } from '../../components/ui';

export function Profile({
  account,
  onUpdated,
}: {
  account: Account;
  onUpdated: (account: Account) => void;
}) {
  const [displayName, setDisplayName] = useState(account.displayName);
  const [description, setDescription] = useState(account.description);
  const [website, setWebsite] = useState(account.website);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setNotice('');
    setErrors({});
    setSaving(true);

    try {
      const updated = await accountApi.updateProfile({
        display_name: displayName.trim(),
        description,
        user_url: website.trim(),
      });
      onUpdated(updated);
      setNotice('Your profile has been updated.');
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Your profile could not be updated.');
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Writer profile</h2>
          <p className="penalis-muted">
            This information may appear alongside your published articles. Your email address and role cannot be
            changed here.
          </p>
        </div>
      </div>

      {error ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <form className="penalis-form penalis-form--narrow" onSubmit={submit} noValidate>
        <label className="penalis-field">
          <span>Display name</span>
          <input
            type="text"
            value={displayName}
            onChange={(event) => setDisplayName(event.target.value)}
            maxLength={80}
            aria-invalid={Boolean(errors.display_name)}
          />
          <FieldError errors={errors} name="display_name" />
        </label>

        <label className="penalis-field">
          <span>Biography</span>
          <textarea
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            rows={6}
            maxLength={500}
            aria-invalid={Boolean(errors.description)}
          />
          <small>{description.length}/500 characters</small>
          <FieldError errors={errors} name="description" />
        </label>

        <label className="penalis-field">
          <span>Website</span>
          <input
            type="url"
            value={website}
            onChange={(event) => setWebsite(event.target.value)}
            placeholder="https://"
            aria-invalid={Boolean(errors.user_url)}
          />
          <FieldError errors={errors} name="user_url" />
        </label>

        <dl className="penalis-details">
          <div>
            <dt>Username</dt>
            <dd>{account.username}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{account.email}</dd>
          </div>
          <div>
            <dt>Account status</dt>
            <dd>{account.statusLabel}</dd>
          </div>
        </dl>

        <button type="submit" className="penalis-button penalis-button--primary" disabled={saving}>
          {saving ? 'Saving…' : 'Save profile'}
        </button>
      </form>
    </div>
  );
}
