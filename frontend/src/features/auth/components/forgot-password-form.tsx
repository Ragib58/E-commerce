'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useAdminForgotPassword } from '../hooks/use-admin-auth';
import { useForgotPassword } from '../hooks/use-customer-auth';
import { forgotPasswordSchema, type ForgotPasswordInput } from '../schemas';
import { applyApiErrors, Field, FormError, FormSuccess, SubmitButton } from './form-controls';

/**
 * Request a password reset link.
 *
 * The success message is whatever the API returns, which is deliberately
 * identical whether or not the address is registered. The UI must not
 * improve on that with a friendlier "we found your account" — doing so would
 * reintroduce the account-enumeration leak the API is careful to avoid.
 */
export function ForgotPasswordForm({ realm = 'customer' }: { realm?: 'customer' | 'admin' }) {
  const [sentMessage, setSentMessage] = useState<string | null>(null);

  const customerMutation = useForgotPassword();
  const adminMutation = useAdminForgotPassword();
  const mutation = realm === 'admin' ? adminMutation : customerMutation;

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ForgotPasswordInput>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: '' },
  });

  const onSubmit = handleSubmit(async (values) => {
    try {
      const message = await mutation.mutateAsync(values);
      setSentMessage(message);
    } catch (error) {
      applyApiErrors<ForgotPasswordInput>(error, setError);
    }
  });

  if (sentMessage) {
    return (
      <div className="space-y-4">
        <FormSuccess message={sentMessage} />
        <p className="text-sm text-muted-foreground">
          The link expires shortly. If it does not arrive, check your spam folder before requesting
          another.
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      <FormError error={mutation.error} />

      <Field
        label="Email address"
        type="email"
        autoComplete="email"
        required
        placeholder="you@example.com"
        error={errors.email?.message}
        {...register('email')}
      />

      <SubmitButton isPending={isSubmitting || mutation.isPending} pendingLabel="Sending…">
        Send reset link
      </SubmitButton>
    </form>
  );
}
