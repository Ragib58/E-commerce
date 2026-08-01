'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { useAdminResetPassword } from '../hooks/use-admin-auth';
import { useResetPassword } from '../hooks/use-customer-auth';
import { resetPasswordSchema, type ResetPasswordInput } from '../schemas';
import { applyApiErrors, Field, FormError, SubmitButton } from './form-controls';

/**
 * Complete a password reset.
 *
 * The token and email arrive in the query string from the emailed link. They
 * are submitted as hidden values rather than rendered as editable inputs —
 * an editable email field would invite a user to "fix" an address that must
 * match the one the token was issued for.
 */
export function ResetPasswordForm({ realm = 'customer' }: { realm?: 'customer' | 'admin' }) {
  const router = useRouter();
  const searchParams = useSearchParams();

  const token = searchParams.get('token') ?? '';
  const email = searchParams.get('email') ?? '';

  const customerMutation = useResetPassword();
  const adminMutation = useAdminResetPassword();
  const mutation = realm === 'admin' ? adminMutation : customerMutation;

  const loginPath = realm === 'admin' ? '/admin/login' : '/login';
  const forgotPath = realm === 'admin' ? '/admin/forgot-password' : '/forgot-password';

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ResetPasswordInput>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { token, email, password: '', password_confirmation: '' },
  });

  const onSubmit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values);

      // The reset revokes every existing session server-side, so there is no
      // session to return to — the user must sign in with the new password.
      router.replace(`${loginPath}?reset=success`);
    } catch (error) {
      applyApiErrors<ResetPasswordInput>(error, setError);
    }
  });

  // A link that lost its query string cannot succeed; say so rather than
  // letting the user fill in a form that will always fail.
  if (!token || !email) {
    return (
      <div className="space-y-4">
        <div
          role="alert"
          className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2.5 text-sm text-destructive"
        >
          This password reset link is incomplete or malformed.
        </div>

        <Link
          href={forgotPath}
          className="inline-block text-sm font-medium text-primary hover:underline"
        >
          Request a new reset link
        </Link>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      <FormError error={mutation.error} />

      <input type="hidden" {...register('token')} />
      <input type="hidden" {...register('email')} />

      <div className="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
        Resetting the password for <span className="font-medium text-foreground">{email}</span>
      </div>

      <Field
        label="New password"
        type="password"
        autoComplete="new-password"
        required
        hint="At least 12 characters, including a number and a symbol."
        error={errors.password?.message}
        {...register('password')}
      />

      <Field
        label="Confirm new password"
        type="password"
        autoComplete="new-password"
        required
        error={errors.password_confirmation?.message}
        {...register('password_confirmation')}
      />

      <SubmitButton isPending={isSubmitting || mutation.isPending} pendingLabel="Resetting…">
        Reset password
      </SubmitButton>
    </form>
  );
}
