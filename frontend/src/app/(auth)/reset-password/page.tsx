import type { Metadata } from 'next';
import Link from 'next/link';
import { Suspense } from 'react';
import { AuthCard } from '@/features/auth/components/form-controls';
import { ResetPasswordForm } from '@/features/auth/components/reset-password-form';

export const metadata: Metadata = {
  title: 'Reset password',
  // Critical here specifically: this URL carries a live reset token in its
  // query string, and an indexed copy would be a real credential leak.
  robots: { index: false, follow: false, nocache: true },
};

export default function ResetPasswordPage() {
  return (
    <AuthCard
      title="Choose a new password"
      footer={
        <Link href="/login" className="font-medium text-primary hover:underline">
          Back to sign in
        </Link>
      }
    >
      <Suspense fallback={<div className="h-64" aria-hidden="true" />}>
        <ResetPasswordForm />
      </Suspense>
    </AuthCard>
  );
}
