/**
 * Decision rules for the review queue.
 *
 * The server enforces all of this (`Penalis_Editorial_Policy::has_required_feedback`),
 * but the dialog has to know the same rules to disable its own button. Keeping
 * them in one small module means the dialog and its test agree, and the copy
 * that explains each action lives next to the rule it describes.
 */

import type { ReviewDecisionAction } from '../../lib/types';

export interface DecisionOption {
  value: ReviewDecisionAction;
  label: string;
  /** Shown in the dialog so the reviewer knows what the writer will receive. */
  description: string;
  /** Approving needs no explanation; the other two do. */
  requiresFeedback: boolean;
}

export const DECISION_OPTIONS: DecisionOption[] = [
  {
    value: 'approve',
    label: 'Setujui & terbitkan',
    description: 'Karya tulis langsung terbit di situs dan penulis menerima pemberitahuan.',
    requiresFeedback: false,
  },
  {
    value: 'request_changes',
    label: 'Minta revisi',
    description: 'Karya tulis kembali ke draf penulis, disertai catatan revisi yang wajib kamu tulis.',
    requiresFeedback: true,
  },
  {
    value: 'reject',
    label: 'Tolak',
    description: 'Karya tulis ditolak dan penulis menerima alasan penolakan yang wajib kamu tulis.',
    requiresFeedback: true,
  },
];

/**
 * Look up one option by value.
 *
 * @param action Decision action.
 * @return The option, or the first one for an unknown value.
 */
export function decisionOption(action: ReviewDecisionAction): DecisionOption {
  return DECISION_OPTIONS.find((option) => option.value === action) ?? DECISION_OPTIONS[0]!;
}

/**
 * Whether the dialog may submit.
 *
 * Whitespace is not feedback: the server trims before checking, so a reviewer
 * who typed a space must still be blocked here rather than after a round trip.
 *
 * @param action   Chosen decision.
 * @param feedback Current textarea value.
 * @return True when the decision can be sent.
 */
export function canSubmitDecision(action: ReviewDecisionAction, feedback: string): boolean {
  return decisionOption(action).requiresFeedback ? feedback.trim() !== '' : true;
}
