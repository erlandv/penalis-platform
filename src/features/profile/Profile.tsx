/**
 * Writer profile: identity fields, public name preference, and profile photo.
 */

import { useCallback, useMemo, useState, type FormEvent } from 'react';
import { Dialog } from '../../components/Dialog';
import { MediaSelection } from '../../components/MediaSelection';

import { accountApi, ApiError, mediaApi } from '../../lib/api';
import { displayNameOptions } from '../../lib/profile';
import type { Account, DisplayNameMode, FieldErrors, MediaItem } from '../../lib/types';
import { ErrorBanner, FieldError, Notice, Spinner } from '../../components/ui';

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
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const [avatarBusy, setAvatarBusy] = useState(false);
  const [avatarError, setAvatarError] = useState('');
  const [pickerOpen, setPickerOpen] = useState(false);
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState('');
  const [selectedMediaId, setSelectedMediaId] = useState<number | null>(null);
  const closePicker = useCallback(() => setPickerOpen(false), []);

  const options = useMemo(
    () => displayNameOptions(firstName, lastName, account.username),
    [firstName, lastName, account.username],
  );

  // Clearing a name removes the option that depended on it. Fall back to the
  // automatic option rather than submitting a selection the server rejects.
  const selectedMode: DisplayNameMode = options.some((option) => option.mode === mode) ? mode : 'auto';

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
      });
      onUpdated(updated);
      setCustomName(updated.displayNameMode === 'custom' ? updated.displayName : customName);
      setNotice('Profil Anda telah diperbarui.');
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Profil Anda tidak dapat diperbarui.');
      }
    } finally {
      setSaving(false);
    }
  };

  const openPicker = async () => {
    setPickerOpen(true);
    setSelectedMediaId(null);
    setAvatarError('');
    setMediaLoading(true);
    setUploadError('');

    try {
      const list = await mediaApi.list(1);
      setMedia(list.items);
    } catch {
      setMedia([]);
    } finally {
      setMediaLoading(false);
    }
  };

  const chooseAvatar = async (attachmentId: number) => {
    setAvatarBusy(true);
    setAvatarError('');

    try {
      const updated = await accountApi.setAvatar(attachmentId);
      onUpdated(updated);
      setPickerOpen(false);
      setNotice('Foto profil Anda telah diperbarui.');
    } catch (caught) {
      setAvatarError(caught instanceof ApiError ? caught.message : 'Foto profil Anda tidak dapat diperbarui.');
    } finally {
      setAvatarBusy(false);
    }
  };

  const removeAvatar = async () => {
    setAvatarBusy(true);
    setAvatarError('');

    try {
      const updated = await accountApi.removeAvatar();
      onUpdated(updated);
      setNotice('Foto profil Anda telah dihapus.');
    } catch (caught) {
      setAvatarError(caught instanceof ApiError ? caught.message : 'Foto profil Anda tidak dapat dihapus.');
    } finally {
      setAvatarBusy(false);
    }
  };

  const uploadAvatar = async (file: File) => {
    setUploading(true);
    setUploadError('');

    try {
      const uploaded = await mediaApi.upload(file);
      setMedia((current) => [uploaded, ...current]);
      setSelectedMediaId(uploaded.id);
    } catch (caught) {
      setUploadError(caught instanceof ApiError ? caught.message : 'Gambar tidak dapat diunggah.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Profil Penulis</h2>
          <p className="penalis-muted">
            Profil kamu akan tampil di bagian bawah karya tulis kamu 
            yang telah terbit dan juga akan tampil di halaman penulismu.
          </p>
        </div>
      </div>

      <section className="penalis-avatar" aria-label="Foto profil">
        <img className="penalis-avatar__preview" src={account.avatarUrl} alt="" width={96} height={96} />

        <div className="penalis-avatar__body">
          <h3>Foto profil</h3>
          <p className="penalis-muted">Tampil pada artikel dan halaman penulis Anda.</p>

          <div className="penalis-actions-row">
            <button
              type="button"
              className="penalis-button penalis-button--ghost"
              onClick={() => void openPicker()}
              disabled={avatarBusy}
            >
              Ganti foto
            </button>
            {account.avatarId > 0 ? (
              <button
                type="button"
                className="penalis-button penalis-button--danger"
                onClick={() => void removeAvatar()}
                disabled={avatarBusy}
              >
                Hapus foto
              </button>
            ) : null}
          </div>

          {avatarError ? <ErrorBanner message={avatarError} /> : null}
        </div>
      </section>

      {error ? <ErrorBanner message={error} /> : null}
      {notice ? <Notice tone="success">{notice}</Notice> : null}

      <form className="penalis-form penalis-form--narrow" onSubmit={submit} noValidate>
        <div className="penalis-field-row">
          <label className="penalis-field">
            <span>Nama depan</span>
            <input
              type="text"
              value={firstName}
              onChange={(event) => setFirstName(event.target.value)}
              maxLength={50}
              aria-invalid={Boolean(errors.first_name)}
            />
            <small>Opsional</small>
            <FieldError errors={errors} name="first_name" />
          </label>

          <label className="penalis-field">
            <span>Nama belakang</span>
            <input
              type="text"
              value={lastName}
              onChange={(event) => setLastName(event.target.value)}
              maxLength={50}
              aria-invalid={Boolean(errors.last_name)}
            />
            <small>Opsional</small>
            <FieldError errors={errors} name="last_name" />
          </label>
        </div>

        <label className="penalis-field">
          <span>Tampilkan nama publik sebagai</span>
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
          <small>Tampil pada artikel dan halaman penulis Anda.</small>
          <FieldError errors={errors} name="display_name_mode" />
        </label>

        {selectedMode === 'custom' ? (
          <label className="penalis-field">
            <span>Nama tampilan</span>
            <input
              type="text"
              value={customName}
              onChange={(event) => setCustomName(event.target.value)}
              maxLength={80}
              aria-invalid={Boolean(errors.display_name)}
            />
            <small>2-80 karakter.</small>
            <FieldError errors={errors} name="display_name" />
          </label>
        ) : null}

        <label className="penalis-field">
          <span>Bio</span>
          <textarea
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            rows={6}
            maxLength={500}
            aria-invalid={Boolean(errors.description)}
          />
          <small>{description.length}/500 karakter</small>
          <FieldError errors={errors} name="description" />
        </label>

        <button type="submit" className="penalis-button penalis-button--primary" disabled={saving}>
          {saving ? 'Menyimpan…' : 'Simpan profil'}
        </button>
      </form>

      {pickerOpen ? (
        <Dialog label="Pilih foto profil" onClose={closePicker}>
          <div className="penalis-modal__content">
            <div className="penalis-modal__header">
              <h3>Pilih foto profil</h3>
            </div>

            <label className="penalis-upload">
              <span>{uploading ? 'Mengunggah…' : 'Unggah gambar'}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                disabled={uploading}
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) {
                    void uploadAvatar(file);
                  }
                  event.target.value = '';
                }}
              />
              <small>JPEG, PNG, GIF, atau WebP, minimal 100 × 100 piksel.</small>
            </label>

            {uploadError ? <ErrorBanner message={uploadError} /> : null}
            {avatarError ? <ErrorBanner message={avatarError} /> : null}

            {mediaLoading ? (
              <Spinner label="Memuat media…" />
            ) : media.length === 0 ? (
              <p className="penalis-muted">Anda belum mengunggah gambar apa pun. Gunakan kontrol unggah di atas.</p>
            ) : (
              <MediaSelection items={media} selectedId={selectedMediaId} onSelect={setSelectedMediaId}
                onConfirm={() => { if (selectedMediaId !== null) void chooseAvatar(selectedMediaId); }}
                onCancel={closePicker} busy={avatarBusy || uploading} />
            )}
          </div>
        </Dialog>
      ) : null}
    </div>
  );
}
