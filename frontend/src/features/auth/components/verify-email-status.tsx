'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { useCustomerSession, useResendVerification } from '../hooks/use-customer-auth';
import { FormError, FormSuccess, SubmitButton } from './form-controls';

/**
 * Outcome of an email verification attempt.
 *
 * The Laravel endpoint verifies the signed link and redirects here with the
 * result in `?status=`, because a JSON body rendered in a browser address bar
 * would be meaningless to a user arriving from their mail client.
 */

type VerificationStatus = 'verified' | 'already-verified' | 'invalid' | 'pending';

const MESSAGES: Record<VerificationStatus, { title: string; body: string; tone: 'ok' | 'error' }> = {
  verified: {
    title: 'Email verified',
    body: 'Your email address has been confirmed. Please sign in to continue.',
    tone: 'ok',
  },
  'already-verified': {
    title: 'Already verified',
    body: 'This email address was already confirmed. You can sign in normally.',
    tone: 'ok',
  },
  invalid: {
    title: 'Link expired or invalid',
    body: 'This verification link is no longer valid. Request a new one below.',
    tone: 'error',
  },
  pending: {
    title: 'Check your inbox',
    body: 'We have sent a verification link to your email address. Click it to activate your account.',
    tone: 'ok',
  },
};

export function VerifyEmailStatus() {
  const searchParams = useSearchParams();
  const { isAuthenticated } = useCustomerSession();
  const resend = useResendVerification();
  const [resentMessage, setResentMessage] = useState<string | null>(null);

  const raw = searchParams.get('status');
  const status: VerificationStatus =
    raw === 'verified' || raw === 'already-verified' || raw === 'invalid' ? raw : 'pending';

  const content = MESSAGES[status];

  const onResend = async () => {
    try {
      const message = await resend.mutateAsync();
      setResentMessage(message);
    } catch {
      // Surfaced by the FormError below; nothing to do here.
    }
  };

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-base font-medium">{content.title}</h2>
        <p className="mt-1.5 text-sm text-muted-foreground">{content.body}</p>
      </div>

      <FormSuccess message={resentMessage} />
      <FormError error={resend.error} />

      {/* Resending needs a session, since the endpoint identifies the account
          from the token rather than an email in the request body. */}
      {isAuthenticated && status !== 'verified' && status !== 'already-verified' ? (
        <SubmitButton
          isPending={resend.isPending}
          pendingLabel="Sending…"
          onClick={onResend}
          type="button"
        >
          Resend verification email
        </SubmitButton>
      ) : null}

      <div className="text-center text-sm">
        <Link href="/login" className="font-medium text-primary hover:underline">
          Go to sign in
        </Link>
      </div>
    </div>
  );
}
