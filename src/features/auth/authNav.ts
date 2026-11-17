/**
 * The shared "back to login" link is an escape hatch for forms still in
 * progress. Success notices already carry their own login CTA, so showing
 * both would offer two labels for the exact same action.
 */

export function shouldShowAuthBackNav(mode: string, finished: boolean): boolean {
  return mode !== 'login' && !finished;
}
