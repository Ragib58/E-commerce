import type { Metadata } from 'next';
import Link from 'next/link';
import { Suspense } from 'react';
import { AuthCard } from '@/features/auth/components/form-controls';
import { ResetPasswordForm } from '@/features/auth/components/reset-password-form';

export const metadata: Metadata = {
  title: 'Reset administrator password',
  robots: { index: false, follow: false, nocache: true },
};

export default function AdminResetPasswordPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30">
      <AuthCard
        title="Choose a new password"
        footer={
          <Link href="/admin/login" className="font-medium text-primary hover:underline">
            Back to sign in
          </Link>
        }
      >
        <Suspense fallback={<div className="h-64" aria-hidden="true" />}>
          <ResetPasswordForm realm="admin" />
        </Suspense>
      </AuthCard>
    </div>
  );
}
