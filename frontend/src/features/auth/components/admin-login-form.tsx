'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useAdminLogin, useAdminSession } from '../hooks/use-admin-auth';
import { loginSchema, type LoginInput } from '../schemas';
import { applyApiErrors, Field, FormError, SubmitButton } from './form-controls';

export function AdminLoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const login = useAdminLogin();
  const { isAuthenticated, isHydrated } = useAdminSession();

  // Same-origin, admin-scoped destinations only. Restricting to /admin as
  // well as to a relative path stops a crafted link bouncing a signed-in
  // administrator into an unexpected part of the site.
  const rawNext = searchParams.get('next');
  const next =
    rawNext && rawNext.startsWith('/admin') && !rawNext.startsWith('//') ? rawNext : '/admin';

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  });

  useEffect(() => {
    if (isHydrated && isAuthenticated) {
      router.replace(next);
    }
  }, [isHydrated, isAuthenticated, next, router]);

  const onSubmit = handleSubmit(async (values) => {
    try {
      const session = await login.mutateAsync(values);

      // Every other admin endpoint 403s until the rotation is done, so route
      // straight there rather than letting the panel fill with errors.
      router.replace(session.must_change_password ? '/admin/change-password' : next);
    } catch (error) {
      applyApiErrors<LoginInput>(error, setError);
    }
  });

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      <FormError error={login.error} />

      <Field
        label="Email address"
        type="email"
        autoComplete="email"
        required
        error={errors.email?.message}
        {...register('email')}
      />

      <div className="space-y-1.5">
        <Field
          label="Password"
          type="password"
          autoComplete="current-password"
          required
          error={errors.password?.message}
          {...register('password')}
        />

        <div className="text-right">
          <Link href="/admin/forgot-password" className="text-xs text-primary hover:underline">
            Forgot your password?
          </Link>
        </div>
      </div>

      <SubmitButton isPending={isSubmitting || login.isPending} pendingLabel="Signing in…">
        Sign in
      </SubmitButton>
    </form>
  );
}
