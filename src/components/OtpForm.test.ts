/**
 * The change-address affordance belongs to legacy activation only: a mistyped
 * address there would otherwise receive every resent code forever.
 */

import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { OtpForm, type OtpFormProps } from './OtpForm';

const baseProps: OtpFormProps = {
  challengeId: 'challenge-1',
  email: 'user@example.com',
  resendAfter: 47,
  onVerify: vi.fn(async () => {}),
  onResend: vi.fn(async () => ({ challenge_id: 'challenge-2', resend_after: 47 })),
};

describe('OtpForm change-address affordance', () => {
  it('offers to correct a mistyped address when a handler is supplied', () => {
    const html = renderToStaticMarkup(
      createElement(OtpForm, { ...baseProps, onEditEmail: () => {} }),
    );

    expect(html).toContain('<strong>user@example.com</strong>');
    expect(html).toContain('Salah alamat email?');
    expect(html).toContain('type="button"');
    expect(html).toContain('>Ubah email</button>');
  });

  it('stays out of the registration verification flow', () => {
    const html = renderToStaticMarkup(createElement(OtpForm, baseProps));

    expect(html).not.toContain('Ubah email');
    expect(html).not.toContain('penalis-otp-email-change');
  });
});
