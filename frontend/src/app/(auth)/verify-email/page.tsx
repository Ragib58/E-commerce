import type { Metadata } from 'next';
import { Suspense } from 'react';
import { AuthCard } from '@/features/auth/components/form-controls';
import { VerifyEmailStatus } from '@/features/auth/components/verify-email-status';

export const metadata: Metadata = {
  title: 'Verify your email',
  robots: { index: false, follow: false },
};

export default function VerifyEmailPage() {
  return (
    <AuthCard title="Email verification">
      <Suspense fallback={<div className="h-40" aria-hidden="true" />}>
        <VerifyEmailStatus />
      </Suspense>
    </AuthCard>
  );
}
