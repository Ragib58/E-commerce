'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { AlertTriangle } from 'lucide-react';

import {
  useChangePassword,
  useCustomerSession,
  useResendVerification,
  useUpdateProfile,
} from '@/features/auth/hooks/use-customer-auth';
import {
  applyApiErrors,
  Field,
  FormError,
  FormSuccess,
  SubmitButton,
} from '@/features/auth/components/form-controls';
import {
  changePasswordSchema,
  updateProfileSchema,
  type ChangePasswordInput,
  type UpdateProfileInput,
} from '@/features/auth/schemas';

/**
 * Profile and security.
 *
 * Two independent forms rather than one. They post to different endpoints with
 * different constraints — a password change requires the current password and
 * revokes other sessions — and combining them would make a name edit ask for a
 * password.
 */
export default function AccountProfilePage() {
  const { user, isVerified } = useCustomerSession();

  return (
    <div className="space-y-8">
      {!isVerified ? <VerificationNotice /> : null}

      <section aria-labelledby="profile-heading" className="rounded-lg border border-border p-6">
        <h2 id="profile-heading" className="text-base font-semibold">
          Profile
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">Signed in as {user?.email}</p>

        <ProfileForm />
      </section>

      <section aria-labelledby="password-heading" className="rounded-lg border border-border p-6">
        <h2 id="password-heading" className="text-base font-semibold">
          Password
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Changing your password signs you out of every other device.
        </p>

        <PasswordForm />
      </section>
    </div>
  );
}

/**
 * The unverified-account notice.
 *
 * Names the consequence rather than only the state. "Your email is unverified"
 * tells a customer nothing about why their profile edit was refused; listing
 * what is blocked — and what is not — does.
 */
function VerificationNotice() {
  const resend = useResendVerification();
  const [sent, setSent] = useState(false);

  return (
    <div
      role="status"
      className="flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4"
    >
      <AlertTriangle
        className="size-5 shrink-0 text-amber-600 dark:text-amber-500"
        aria-hidden="true"
      />

      <div className="min-w-0">
        <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
          Confirm your email address
        </p>
        <p className="mt-0.5 text-sm text-amber-700 dark:text-amber-400">
          Until you do, you cannot change your profile or password. Browsing and your cart are
          unaffected.
        </p>

        {sent ? (
          <p className="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300">
            Sent — check your inbox.
          </p>
        ) : (
          <button
            type="button"
            onClick={() =>
              resend.mutate(undefined, {
                /*
                 * Reported as sent regardless of outcome. The endpoint is rate
                 * limited and deliberately does not reveal whether an address
                 * exists, so a failure here is not information to act on.
                 */
                onSettled: () => setSent(true),
              })
            }
            disabled={resend.isPending}
            className="mt-2 text-sm font-medium text-amber-800 underline-offset-4 hover:underline disabled:opacity-60 dark:text-amber-300"
          >
            {resend.isPending ? 'Sending…' : 'Resend the verification email'}
          </button>
        )}
      </div>
    </div>
  );
}

function ProfileForm() {
  const { user, isVerified } = useCustomerSession();
  const updateProfile = useUpdateProfile();
  const [saved, setSaved] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<UpdateProfileInput>({
    resolver: zodResolver(updateProfileSchema),
    defaultValues: {
      name: user?.name ?? '',
      phone: user?.phone ?? '',
    },
  });

  const onSubmit = handleSubmit(async (values) => {
    setSaved(false);

    try {
      await updateProfile.mutateAsync(values);
      setSaved(true);
    } catch (error) {
      applyApiErrors<UpdateProfileInput>(error, setError);
    }
  });

  return (
    <form onSubmit={onSubmit} className="mt-5 space-y-4" noValidate>
      <FormError error={updateProfile.error} />
      <FormSuccess message={saved ? 'Your profile has been updated.' : null} />

      <Field
        label="Name"
        error={errors.name?.message}
        autoComplete="name"
        // Mirrors the API's `verified` middleware: presenting a form that
        // cannot succeed is worse than showing it disabled with a reason.
        disabled={!isVerified}
        {...register('name')}
      />

      <Field
        label="Phone"
        type="tel"
        autoComplete="tel"
        error={errors.phone?.message}
        hint="Optional. Used only for delivery updates."
        disabled={!isVerified}
        {...register('phone')}
      />

      <SubmitButton isPending={isSubmitting || updateProfile.isPending} disabled={!isVerified}>
        Save changes
      </SubmitButton>
    </form>
  );
}

function PasswordForm() {
  const { isVerified } = useCustomerSession();
  const changePassword = useChangePassword();
  const [changed, setChanged] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordInput>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: {
      current_password: '',
      password: '',
      password_confirmation: '',
    },
  });

  const onSubmit = handleSubmit(async (values) => {
    setChanged(false);

    try {
      await changePassword.mutateAsync(values);
      // Cleared on success, so the new password is not left sitting in the DOM.
      reset();
      setChanged(true);
    } catch (error) {
      applyApiErrors<ChangePasswordInput>(error, setError);
    }
  });

  return (
    <form onSubmit={onSubmit} className="mt-5 space-y-4" noValidate>
      <FormError error={changePassword.error} />
      <FormSuccess message={changed ? 'Your password has been changed.' : null} />

      <Field
        label="Current password"
        type="password"
        autoComplete="current-password"
        error={errors.current_password?.message}
        disabled={!isVerified}
        {...register('current_password')}
      />

      <Field
        label="New password"
        type="password"
        autoComplete="new-password"
        error={errors.password?.message}
        disabled={!isVerified}
        {...register('password')}
      />

      <Field
        label="Confirm new password"
        type="password"
        autoComplete="new-password"
        error={errors.password_confirmation?.message}
        disabled={!isVerified}
        {...register('password_confirmation')}
      />

      <SubmitButton isPending={isSubmitting || changePassword.isPending} disabled={!isVerified}>
        Change password
      </SubmitButton>

      <p className="text-sm text-muted-foreground">
        Forgotten it?{' '}
        <Link href="/forgot-password" className="font-medium text-primary hover:underline">
          Reset it by email
        </Link>
        .
      </p>
    </form>
  );
}
