import type { Metadata } from 'next';
import Link from 'next/link';
import { Suspense } from 'react';
import { LoginForm } from '@/features/auth/components/login-form';
import { AuthCard } from '@/features/auth/components/form-controls';

export const metadata: Metadata = {
  title: 'Sign in',
  // Auth pages must never be indexed: they carry no useful content and a
  // crawled reset link would be a real leak.
  robots: { index: false, follow: false },
};

export default function LoginPage() {
  return (
    <AuthCard
      title="Sign in"
      description="Enter your credentials to access your account."
      footer={
        <>
          Don&apos;t have an account?{' '}
          <Link href="/register" className="font-medium text-primary hover:underline">
            Create one
          </Link>
        </>
      }
    >
      {/* useSearchParams requires a Suspense boundary in the App Router;
          without one the whole route opts out of static rendering. */}
      <Suspense fallback={<div className="h-64" aria-hidden="true" />}>
        <LoginForm />
      </Suspense>
    </AuthCard>
  );
}
