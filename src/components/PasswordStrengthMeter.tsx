/**
 * Live password strength feedback for the new-password fields.
 *
 * Shows a four-segment meter plus a text label, because colour alone is not a
 * message: the label is what a screen reader announces and what a colour-blind
 * writer reads. The segments are `aria-hidden` decoration.
 *
 * The wrapper carries the `id` the password input points at with
 * `aria-describedby`, so the meter is read as part of the field's description
 * rather than as a separate, unexplained widget. It renders nothing for an
 * empty field — a meter stuck at "Sangat lemah" before the writer has typed a
 * character is noise, and the persistent hint already states the minimum.
 *
 * @see lib/passwordStrength.ts for the scoring rubric
 */

import { passwordStrength } from '../lib/passwordStrength';

/** One segment per score bucket, so no two labels look alike. */
const SEGMENT_COUNT = 5;

/** Score at which the guidance line stops nagging. */
const GUIDANCE_MAX_SCORE = 2;

export function PasswordStrengthMeter({ password, id }: { password: string; id: string }) {
  const strength = passwordStrength(password);

  if (password === '') {
    return null;
  }

  // Every bucket lights its own segment: "Sangat lemah" and "Lemah" would
  // otherwise render identically.
  const filled = strength.score + 1;

  const guidance =
    strength.score <= GUIDANCE_MAX_SCORE && strength.hints.length > 0
      ? `Supaya lebih kuat: ${strength.hints.slice(0, 2).join(' dan ')}.`
      : '';

  return (
    <div className="penalis-password-strength" data-score={strength.score} id={id}>
      <div className="penalis-password-strength__meter" aria-hidden="true">
        {Array.from({ length: SEGMENT_COUNT }, (_, index) => (
          <span
            key={index}
            className={
              index < filled
                ? 'penalis-password-strength__segment is-on'
                : 'penalis-password-strength__segment'
            }
          />
        ))}
      </div>
      <p className="penalis-password-strength__status" role="status" aria-atomic="true">
        <span className="penalis-password-strength__label">{strength.label}</span>
        {guidance !== '' ? (
          <span className="penalis-password-strength__hint">{guidance}</span>
        ) : null}
      </p>
    </div>
  );
}
