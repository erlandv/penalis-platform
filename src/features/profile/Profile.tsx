/**
 * Writer profile: identity fields and public name preference.
 */

import { useMemo, useState, type FormEvent } from 'react';

import { accountApi, ApiError } from '../../lib/api';
import { displayNameOptions, resolveDisplayName } from '../../lib/profile';
import type { Account, DisplayNameMode, FieldErrors } from '../../lib/types';
import { ErrorBanner, FieldError, Notice } from '../../components/ui';

export function Profile({
  account,
  onUpdated,
}: {
  account: Account;
  onUpdated: (account: Account) => void;
}) {
  const [firstName, setFirstName] = useState(account.firstName);
  const [lastName, setLastName] = useState(account.lastName);
  const [mode, setMode] = useState<DisplayNameMode>(account.displayNameMode);
  const [customName, setCustomName] = useState(account.displayNameMode === 'custom' ? account.displayName : '');
  const [description, setDescription] = useState(account.description);
  const [website, setWebsite] = useState(account.website);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const options = useMemo(
    () => displayNameOptions(firstName, lastName, account.username),
    [firstName, lastName, account.username],
  );

  // Clearing a name removes the option that depended on it. Fall back to the
  // automatic option rather than submitting a selection the server rejects.
  const selectedMode: DisplayNameMode = options.some((option) => option.mode === mode) ? mode : 'auto';
  const publicName = resolveDisplayName(selectedMode, firstName, lastName, account.username, customName);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setNotice('');
    setErrors({});
    setSaving(true);

    try {
      const updated = await accountApi.updateProfile({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        display_name_mode: selectedMode,
        ...(selectedMode === 'custom' ? { display_name: customName.trim() } : {}),
        description,
        user_url: website.trim(),
      });
      onUpdated(updated);
      setCustomName(updated.displayNameMode === 'custom' ? updated.displayName : customName);
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
        <div className="penalis-field-row">
          <label className="penalis-field">
            <span>First name</span>
            <input
              type="text"
              value={firstName}
              onChange={(event) => setFirstName(event.target.value)}
              maxLength={50}
              aria-invalid={Boolean(errors.first_name)}
            />
            <small>Optional</small>
            <FieldError errors={errors} name="first_name" />
          </label>

          <label className="penalis-field">
            <span>Last name</span>
            <input
              type="text"
              value={lastName}
              onChange={(event) => setLastName(event.target.value)}
              maxLength={50}
              aria-invalid={Boolean(errors.last_name)}
            />
            <small>Optional</small>
            <FieldError errors={errors} name="last_name" />
          </label>
        </div>

        <label className="penalis-field">
          <span>Display name publicly as</span>
          <select
            value={selectedMode}
            onChange={(event) => setMode(event.target.value as DisplayNameMode)}
            aria-invalid={Boolean(errors.display_name_mode)}
          >
            {options.map((option) => (
              <option key={option.mode} value={option.mode}>
                {option.label}
              </option>
            ))}
          </select>
          <small>Shown on your articles and author page.</small>
          <FieldError errors={errors} name="display_name_mode" />
        </label>

        {selectedMode === 'custom' ? (
          <label className="penalis-field">
            <span>Display name</span>
            <input
              type="text"
              value={customName}
              onChange={(event) => setCustomName(event.target.value)}
              maxLength={80}
              aria-invalid={Boolean(errors.display_name)}
            />
            <small>Between 2 and 80 characters.</small>
            <FieldError errors={errors} name="display_name" />
          </label>
        ) : null}

        <p className="penalis-profile-preview">
          Public name: <strong>{publicName || '—'}</strong>
        </p>

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
