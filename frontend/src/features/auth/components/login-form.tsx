'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useLogin } from '../hooks/use-customer-auth';
import { useCustomerSession } from '../hooks/use-customer-auth';
import { loginSchema, type LoginInput } from '../schemas';
import { applyApiErrors, Field, FormError, SubmitButton } from './form-controls';

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const login = useLogin();
  const { isAuthenticated, isHydrated } = useCustomerSession();

  // Only same-origin relative paths are honoured. Accepting an absolute URL
  // here would make this an open redirect: a phishing link could send a user
  // through a legitimate login and out to an attacker's page.
  const rawNext = searchParams.get('next');
  const next = rawNext && rawNext.startsWith('/') && !rawNext.startsWith('//') ? rawNext : '/';

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginInput>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  });

  // Someone already signed in has no business on the login page.
  useEffect(() => {
    if (isHydrated && isAuthenticated) {
      router.replace(next);
    }
  }, [isHydrated, isAuthenticated, next, router]);

  const onSubmit = handleSubmit(async (values) => {
    try {
      await login.mutateAsync(values);
      router.replace(next);
    } catch (error) {
      // Field-level messages land on their inputs; anything else falls
      // through to the banner below.
      applyApiErrors<LoginInput>(error, setError);
    }
  });

  const isPending = isSubmitting || login.isPending;

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      <FormError error={login.error} />

      <Field
        label="Email address"
        type="email"
        autoComplete="email"
        required
        placeholder="you@example.com"
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
          <Link href="/forgot-password" className="text-xs text-primary hover:underline">
            Forgot your password?
          </Link>
        </div>
      </div>

      <SubmitButton isPending={isPending} pendingLabel="Signing in…">
        Sign in
      </SubmitButton>
    </form>
  );
}
