'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { useSessionMerge } from '@/features/shopping/hooks/use-session-merge';
import { useRegister } from '../hooks/use-customer-auth';
import { registerSchema, type RegisterInput } from '../schemas';
import { applyApiErrors, Field, FormError, SubmitButton } from './form-controls';

export function RegisterForm() {
  const router = useRouter();
  const registerMutation = useRegister();
  const mergeGuestSession = useSessionMerge();

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterInput>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
      phone: '',
      accepts_terms: false as never,
    },
  });

  const onSubmit = handleSubmit(async (values) => {
    try {
      await registerMutation.mutateAsync(values);

      // A shopper who filled a basket and then created an account to check out
      // keeps it. Best-effort and never throws — see useSessionMerge.
      await mergeGuestSession();

      // Registration issues a token immediately, but the account is
      // unverified — send the user to the page that explains what to do next
      // rather than into a storefront that will block their actions.
      router.replace('/verify-email?status=pending');
    } catch (error) {
      applyApiErrors<RegisterInput>(error, setError);
    }
  });

  const isPending = isSubmitting || registerMutation.isPending;

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      <FormError error={registerMutation.error} />

      <Field
        label="Full name"
        autoComplete="name"
        required
        error={errors.name?.message}
        {...register('name')}
      />

      <Field
        label="Email address"
        type="email"
        autoComplete="email"
        required
        placeholder="you@example.com"
        error={errors.email?.message}
        {...register('email')}
      />

      <Field
        label="Phone number"
        type="tel"
        autoComplete="tel"
        hint="Optional"
        error={errors.phone?.message}
        {...register('phone')}
      />

      <Field
        label="Password"
        type="password"
        autoComplete="new-password"
        required
        hint="At least 12 characters, including a number and a symbol."
        error={errors.password?.message}
        {...register('password')}
      />

      <Field
        label="Confirm password"
        type="password"
        autoComplete="new-password"
        required
        error={errors.password_confirmation?.message}
        {...register('password_confirmation')}
      />

      <div className="space-y-1.5">
        <label className="flex items-start gap-2.5 text-sm">
          <input
            type="checkbox"
            className="mt-0.5 size-4 rounded border-input"
            aria-invalid={errors.accepts_terms ? true : undefined}
            {...register('accepts_terms')}
          />
          <span>
            I accept the terms and conditions and the privacy policy.
          </span>
        </label>

        {errors.accepts_terms ? (
          <p className="text-xs text-destructive" role="alert">
            {errors.accepts_terms.message}
          </p>
        ) : null}
      </div>

      <SubmitButton isPending={isPending} pendingLabel="Creating account…">
        Create account
      </SubmitButton>
    </form>
  );
}
