import type { Metadata } from 'next';
import Link from 'next/link';
import { AuthCard } from '@/features/auth/components/form-controls';
import { ForgotPasswordForm } from '@/features/auth/components/forgot-password-form';

export const metadata: Metadata = {
  title: 'Administrator password reset',
  robots: { index: false, follow: false },
};

export default function AdminForgotPasswordPage() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30">
      <AuthCard
        title="Reset administrator password"
        description="Enter your administrator email address to receive a reset link."
        footer={
          <Link href="/admin/login" className="font-medium text-primary hover:underline">
            Back to sign in
          </Link>
        }
      >
        {/* The admin realm targets a separate broker and token table, so a
            customer reset token can never redeem against a staff account. */}
        <ForgotPasswordForm realm="admin" />
      </AuthCard>
    </div>
  );
}
