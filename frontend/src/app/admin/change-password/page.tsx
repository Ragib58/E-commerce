'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { useAdminChangePassword, useAdminSession } from '@/features/auth/hooks/use-admin-auth';
import {
  applyApiErrors,
  AuthCard,
  Field,
  FormError,
  SubmitButton,
} from '@/features/auth/components/form-controls';
import { changePasswordSchema, type ChangePasswordInput } from '@/features/auth/schemas';

/**
 * Administrator password change.
 *
 * Serves two cases: a voluntary change, and the forced rotation an account
 * created with a generated password must complete before the API will serve
 * any other admin endpoint.
 *
 * Deliberately outside AdminGuard's permission checks — an admin under forced
 * rotation must be able to reach this page, and the guard would otherwise
 * redirect them here in a loop.
 */
export default function AdminChangePasswordPage() {
  const router = useRouter();
  const { mustChangePassword, isAuthenticated, isHydrated } = useAdminSession();
  const changePassword = useAdminChangePassword();

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordInput>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  });

  const onSubmit = handleSubmit(async (values) => {
    try {
      await changePassword.mutateAsync(values);
      router.replace('/admin');
    } catch (error) {
      applyApiErrors<ChangePasswordInput>(error, setError);
    }
  });

  if (isHydrated && !isAuthenticated) {
    router.replace('/admin/login');

    return null;
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30">
      <AuthCard
        title={mustChangePassword ? 'Set a new password' : 'Change your password'}
        description={
          mustChangePassword
            ? 'Your account was created with a temporary password. Choose a new one to continue.'
            : undefined
        }
      >
        <form onSubmit={onSubmit} className="space-y-4" noValidate>
          <FormError error={changePassword.error} />

          <Field
            label={mustChangePassword ? 'Temporary password' : 'Current password'}
            type="password"
            autoComplete="current-password"
            required
            error={errors.current_password?.message}
            {...register('current_password')}
          />

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

          <p className="text-xs text-muted-foreground">
            Changing your password signs you out on every other device.
          </p>

          <SubmitButton
            isPending={isSubmitting || changePassword.isPending}
            pendingLabel="Updating…"
          >
            Update password
          </SubmitButton>
        </form>
      </AuthCard>
    </div>
  );
}
