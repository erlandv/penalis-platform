/**
 * Password strength scoring for the dashboard forms.
 *
 * The server only enforces a minimum length (`PASSWORD_MIN_LENGTH`); everything
 * here is guidance. That asymmetry is deliberate: a meter that blocks a
 * submission would have to be mirrored exactly on the server, and a mismatch
 * between the two would either lock out a writer whose password the server
 * accepts or reject one it would have taken. So this module never decides
 * whether a password is *allowed* — it only tells the writer how much work an
 * attacker would need.
 *
 * Scoring is a small, explainable rubric rather than an entropy estimate: each
 * property a writer can act on adds a point, and a handful of well-known weak
 * shapes (one repeated character, digits only, a dictionary word with digits
 * bolted on) cap the score so "Password1234!" cannot read as "Sangat kuat".
 *
 * @see components/PasswordStrengthMeter.tsx
 */

import { PASSWORD_MIN_LENGTH } from './validation';

/**
 * Length at which we start calling a password "strong" instead of merely
 * acceptable. Not enforced anywhere — purely advisory.
 */
export const PASSWORD_STRONG_LENGTH = 16;

/** Score buckets rendered as the four segments of the meter. */
export const PASSWORD_STRENGTH_LABELS = ['Sangat lemah', 'Lemah', 'Cukup', 'Kuat', 'Sangat kuat'] as const;

export type PasswordStrengthScore = 0 | 1 | 2 | 3 | 4;

export type PasswordRequirementId = 'length' | 'long' | 'mixedCase' | 'digit' | 'symbol' | 'varied';

export interface PasswordRequirement {
  id: PasswordRequirementId;
  /** Short requirement, used in the meter's guidance line. */
  hint: string;
  met: boolean;
}

export interface PasswordStrength {
  /** 0 = very weak … 4 = very strong. */
  score: PasswordStrengthScore;
  /** Human label for the score, or an empty string for an empty password. */
  label: string;
  requirements: PasswordRequirement[];
  /** Hints for the unmet requirements, strongest first. */
  hints: string[];
}

/**
 * Words that stay weak no matter how they are decorated.
 *
 * Small on purpose: this is a speed bump for the obvious choices, not a
 * password dictionary. Entries are compared after stripping everything that is
 * not a letter and lower-casing, so "P@ssword2024!" still matches.
 */
const COMMON_PASSWORDS = new Set([
  'password',
  'passwort',
  'katasandi',
  'sandi',
  'qwerty',
  'asdfgh',
  'iloveyou',
  'welcome',
  'letmein',
  'admin',
  'penalis',
  'indonesia',
]);

const STRENGTH_HINTS: Record<PasswordRequirementId, string> = {
  length: `tambahkan hingga minimal ${PASSWORD_MIN_LENGTH} karakter`,
  long: `perpanjang menjadi ${PASSWORD_STRONG_LENGTH} karakter`,
  mixedCase: 'campur huruf besar dan kecil',
  digit: 'tambahkan angka',
  symbol: 'tambahkan simbol',
  varied: 'hindari karakter yang berulang',
};

/**
 * Reduce a password to letters for the dictionary comparison.
 *
 * Digits and symbols are dropped, and the usual stand-ins are mapped back to
 * the letter they imitate, so `P@ssword2024` is compared as `password`.
 *
 * @param password Raw password.
 * @return Lower-cased letters only.
 */
function dictionaryKey(password: string): string {
  return password
    .toLowerCase()
    .replace(/0/g, 'o')
    .replace(/[1!|]/g, 'i')
    .replace(/3/g, 'e')
    .replace(/[4@]/g, 'a')
    .replace(/[5$]/g, 's')
    .replace(/7/g, 't')
    .replace(/[^a-z]/g, '');
}

/**
 * Whether the password is a known word with decoration attached.
 *
 * Matching is by prefix, not equality: appending digits or a symbol to a
 * dictionary word (`password2024!`) is the most common weak pattern, and it
 * would slip through an exact comparison.
 *
 * @param password Raw password.
 * @return True when the password starts with a common word.
 */
function isCommonPassword(password: string): boolean {
  const key = dictionaryKey(password);

  if (key === '') {
    return false;
  }

  for (const word of COMMON_PASSWORDS) {
    if (key.startsWith(word)) {
      return true;
    }
  }

  return false;
}

/**
 * Score a password.
 *
 * @param password Raw password.
 * @return Score, label, per-requirement results, and guidance hints.
 */
export function passwordStrength(password: string): PasswordStrength {
  const length = password.length;

  const hasLower = /[a-z]/.test(password);
  const hasUpper = /[A-Z]/.test(password);
  const hasDigit = /[0-9]/.test(password);
  // Anything that is not a letter or a digit counts as a symbol, spaces
  // included: a passphrase's spaces are what make it easy to remember.
  const hasSymbol = /[^A-Za-z0-9]/.test(password);
  // Long runs of the same character look long without adding any guessing work.
  const distinctRatio = length > 0 ? new Set(password).size / length : 0;

  const requirements: PasswordRequirement[] = [
    { id: 'length', hint: STRENGTH_HINTS.length, met: length >= PASSWORD_MIN_LENGTH },
    { id: 'long', hint: STRENGTH_HINTS.long, met: length >= PASSWORD_STRONG_LENGTH },
    { id: 'mixedCase', hint: STRENGTH_HINTS.mixedCase, met: hasLower && hasUpper },
    { id: 'digit', hint: STRENGTH_HINTS.digit, met: hasDigit },
    { id: 'symbol', hint: STRENGTH_HINTS.symbol, met: hasSymbol },
    { id: 'varied', hint: STRENGTH_HINTS.varied, met: length >= 4 && distinctRatio >= 0.6 },
  ];

  const hints = requirements.filter((requirement) => !requirement.met).map((requirement) => requirement.hint);

  if (length === 0) {
    return { score: 0, label: '', requirements, hints };
  }

  let points = 0;
  if (length >= PASSWORD_MIN_LENGTH) {
    points += 2;
  }
  if (length >= PASSWORD_STRONG_LENGTH) {
    points += 1;
  }
  if (hasLower && hasUpper) {
    points += 1;
  }
  if (hasDigit) {
    points += 1;
  }
  if (hasSymbol) {
    points += 1;
  }
  if (length >= 4 && distinctRatio >= 0.6) {
    points += 1;
  }

  let score: number;
  if (points <= 1) {
    score = 0;
  } else if (points === 2) {
    score = 1;
  } else if (points === 3) {
    score = 2;
  } else if (points <= 5) {
    score = 3;
  } else {
    score = 4;
  }

  /*
   * Weak shapes. Each of these is capped instead of merely docked: a 12-digit
   * sequence and a dictionary word with punctuation both score well on the
   * rubric above, and both are guessed in seconds.
   */
  const weakShape = new Set(password).size === 1 || /^[0-9]+$/.test(password) || isCommonPassword(password);

  if (weakShape) {
    score = Math.min(score, 1);
  }

  return {
    score: score as PasswordStrengthScore,
    label: PASSWORD_STRENGTH_LABELS[score] ?? '',
    requirements,
    hints,
  };
}
