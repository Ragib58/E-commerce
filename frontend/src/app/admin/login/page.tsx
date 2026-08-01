import type { Metadata } from 'next';
import { Suspense } from 'react';
import { AuthCard } from '@/features/auth/components/form-controls';
import { AdminLoginForm } from '@/features/auth/components/admin-login-form';

export const metadata: Metadata = {
  title: 'Administrator sign in',
  robots: { index: false, follow: false },
};

export default function AdminLoginPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30">
      <AuthCard
        title="Administrator sign in"
        description="This area is restricted to authorised staff."
      >
        <Suspense fallback={<div className="h-64" aria-hidden="true" />}>
          <AdminLoginForm />
        </Suspense>
      </AuthCard>
    </div>
  );
}
