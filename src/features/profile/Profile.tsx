/**
 * Writer profile: identity fields, public name preference, and profile photo.
 */

import { useCallback, useMemo, useState, type FormEvent } from 'react';
import { AvatarCropper } from '../../components/AvatarCropper';
import { Dialog } from '../../components/Dialog';
import { ImageDropzone } from '../../components/ImageDropzone';
import { MediaSelection } from '../../components/MediaSelection';

import { accountApi, ApiError, mediaApi } from '../../lib/api';
import { displayNameOptions } from '../../lib/profile';
import type { Account, DisplayNameMode, FieldErrors, MediaItem } from '../../lib/types';
import { validateInstagram } from '../../lib/validation';
import { ErrorBanner, FieldError, FieldHint, Notice, Spinner } from '../../components/ui';

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
  const [instagram, setInstagram] = useState(account.instagram ?? '');
  const [description, setDescription] = useState(account.description);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const [avatarBusy, setAvatarBusy] = useState(false);
  const [avatarError, setAvatarError] = useState('');
  const [pickerOpen, setPickerOpen] = useState(false);
  const [cropTarget, setCropTarget] = useState<File | string | null>(null);
  const [media, setMedia] = useState<MediaItem[]>([]);
  const [mediaLoading, setMediaLoading] = useState(false);
  const [uploadError, setUploadError] = useState('');
  const [selectedMediaId, setSelectedMediaId] = useState<number | null>(null);
  const closePicker = useCallback(() => {
    setPickerOpen(false);
    setCropTarget(null);
  }, []);

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

    const instagramError = validateInstagram(instagram);
    if (instagramError !== '') {
      setErrors({ instagram: instagramError });
      return;
    }

    setSaving(true);

    try {
      const updated = await accountApi.updateProfile({
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        display_name_mode: selectedMode,
        ...(selectedMode === 'custom' ? { display_name: customName.trim() } : {}),
        instagram: instagram.trim(),
        description,
      });
      onUpdated(updated);
      setCustomName(updated.displayNameMode === 'custom' ? updated.displayName : customName);
      setInstagram(updated.instagram ?? '');
      setNotice('Profil kamu telah diperbarui.');
    } catch (caught) {
      if (caught instanceof ApiError) {
        setErrors(caught.fields);
        setError(caught.message);
      } else {
        setError('Profil kamu tidak dapat diperbarui.');
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

  const handleCroppedAvatar = async (croppedFile: File) => {
    setAvatarBusy(true);
    setAvatarError('');

    try {
      const uploaded = await mediaApi.upload(croppedFile);
      setMedia((current) => [uploaded, ...current]);
      const updated = await accountApi.setAvatar(uploaded.id);
      onUpdated(updated);
      setCropTarget(null);
      setPickerOpen(false);
      setNotice('Foto profil kamu telah diperbarui.');
    } catch (caught) {
      setAvatarError(
        caught instanceof ApiError ? caught.message : 'Foto profil kamu tidak dapat diperbarui.',
      );
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
      setNotice('Foto profil kamu telah dihapus.');
    } catch (caught) {
      setAvatarError(caught instanceof ApiError ? caught.message : 'Foto profil kamu tidak dapat dihapus.');
    } finally {
      setAvatarBusy(false);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Profil Penulis</h2>
          <p className="penalis-muted">
            Informasi profil kamu akan tampil di halaman karya tulis yang telah terbit 
            dan juga akan tampil di halaman publik profil kamu.
          </p>
        </div>
      </div>

      <section className="penalis-avatar" aria-label="Foto profil">
        <img className="penalis-avatar__preview" src={account.avatarUrl} alt="" width={96} height={96} />

        <div className="penalis-avatar__body">
          <h3>Foto Profil</h3>
          <p className="penalis-muted">Ganti atau hapus foto profil.</p>

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
            <span>Nama Depan</span>
            <input
              type="text"
              value={firstName}
              onChange={(event) => setFirstName(event.target.value)}
              maxLength={50}
              aria-invalid={Boolean(errors.first_name)}
            />
            <FieldHint id="first_name-hint">Opsional</FieldHint>
            <FieldError errors={errors} name="first_name" />
          </label>

          <label className="penalis-field">
            <span>Nama Belakang</span>
            <input
              type="text"
              value={lastName}
              onChange={(event) => setLastName(event.target.value)}
              maxLength={50}
              aria-invalid={Boolean(errors.last_name)}
            />
            <FieldHint id="last_name-hint">Opsional</FieldHint>
            <FieldError errors={errors} name="last_name" />
          </label>
        </div>

        <label className="penalis-field">
          <span>Tampilkan Nama Publik Sebagai</span>
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
          <FieldHint id="display_name_mode-hint">Tampil pada karya tulis dan halaman penulis</FieldHint>
          <FieldError errors={errors} name="display_name_mode" />
        </label>

        {selectedMode === 'custom' ? (
          <label className="penalis-field">
            <span>Nama Tampilan</span>
            <input
              type="text"
              value={customName}
              onChange={(event) => setCustomName(event.target.value)}
              maxLength={80}
              aria-invalid={Boolean(errors.display_name)}
            />
            <FieldHint id="display_name-hint">2-80 karakter.</FieldHint>
            <FieldError errors={errors} name="display_name" />
          </label>
        ) : null}

        <label className="penalis-field">
          <span>Username Instagram (opsional)</span>
          <input
            type="text"
            value={instagram}
            onChange={(event) => setInstagram(event.target.value)}
            maxLength={100}
            placeholder="@username"
            aria-invalid={Boolean(errors.instagram)}
          />
          <FieldHint id="instagram-hint">
            Opsional, digunakan untuk keperluan internal admin Penalis.
          </FieldHint>
          <FieldError errors={errors} name="instagram" />
        </label>

        <label className="penalis-field">
          <span>Bio</span>
          <textarea
            value={description}
            onChange={(event) => setDescription(event.target.value)}
            rows={6}
            maxLength={500}
            aria-invalid={Boolean(errors.description)}
          />
          <FieldHint id="description-hint">{description.length}/500 karakter</FieldHint>
          <FieldError errors={errors} name="description" />
        </label>

        <button type="submit" className="penalis-button penalis-button--primary" disabled={saving}>
          {saving ? 'Menyimpan…' : 'Simpan Profil'}
        </button>
      </form>

      {cropTarget !== null ? (
        <AvatarCropper
          imageSource={cropTarget}
          onCrop={handleCroppedAvatar}
          onCancel={() => setCropTarget(null)}
          busy={avatarBusy}
        />
      ) : pickerOpen ? (
        <Dialog label="Pilih foto profil" onClose={closePicker}>
          <div className="penalis-modal__content">
            <div className="penalis-modal__header">
              <h3>Pilih Foto Profil</h3>
            </div>

            <ImageDropzone
              onFile={(file) => setCropTarget(file)}
              onReject={setUploadError}
              busy={avatarBusy}
              title="Letakkan gambar di sini"
              subtitle="atau klik untuk memilih file"
              hint="JPEG, PNG, GIF, atau WebP, minimal 100 x 100 piksel."
            />

            {uploadError ? <ErrorBanner message={uploadError} /> : null}
            {avatarError ? <ErrorBanner message={avatarError} /> : null}

            {mediaLoading ? (
              <Spinner label="Memuat media…" />
            ) : media.length === 0 ? (
              <p className="penalis-muted">Kamu belum upload gambar apa pun. Gunakan kontrol upload di atas.</p>
            ) : (
              <MediaSelection
                items={media}
                selectedId={selectedMediaId}
                onSelect={setSelectedMediaId}
                onConfirm={() => {
                  if (selectedMediaId !== null) {
                    const item = media.find((m) => m.id === selectedMediaId);
                    if (item) {
                      setCropTarget(item.full || item.thumbnail);
                    }
                  }
                }}
                onCancel={closePicker}
                busy={avatarBusy}
              />
            )}
          </div>
        </Dialog>
      ) : null}
    </div>
  );
}
