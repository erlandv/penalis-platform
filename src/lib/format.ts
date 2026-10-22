/**
 * Formatting helpers.
 */

export function formatDate(value: string | number): string {
  if (!value) {
    return '—';
  }

  const date =
    typeof value === 'number' ? new Date(value * 1000) : new Date(value.endsWith('Z') ? value : `${value}Z`);

  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(date);
}

export function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    publish: 'Published',
    draft: 'Draft',
    pending: 'Pending review',
    future: 'Scheduled',
    private: 'Private',
    pending_verification: 'Email verification pending',
    pending_approval: 'Awaiting approval',
    active: 'Active',
    rejected: 'Rejected',
    suspended: 'Suspended',
  };

  return labels[status] ?? status;
}

export function statusTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'publish':
    case 'active':
      return 'success';
    case 'pending':
    case 'pending_verification':
    case 'pending_approval':
    case 'future':
      return 'warning';
    case 'rejected':
    case 'suspended':
      return 'danger';
    default:
      return 'neutral';
  }
}

export function truncate(value: string, length = 160): string {
  const text = value.trim();
  if (text.length <= length) {
    return text;
  }
  return `${text.slice(0, length - 1)}…`;
}
