'use client';

import { forwardRef, useId, type InputHTMLAttributes, type ReactNode } from 'react';
import { cn } from '@/lib/utils/cn';
import { ApiError } from '@/lib/api/errors';

/**
 * Form primitives shared by the auth screens.
 *
 * Kept together because every auth form needs the same three things: a
 * labelled input wired to its error message, a submit button with a pending
 * state, and a place to show the API's response.
 */

interface FieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string;
  hint?: string;
}

export const Field = forwardRef<HTMLInputElement, FieldProps>(function Field(
  { label, error, hint, className, id, ...props },
  ref,
) {
  const generatedId = useId();
  const inputId = id ?? generatedId;
  const errorId = `${inputId}-error`;
  const hintId = `${inputId}-hint`;

  return (
    <div className="space-y-1.5">
      <label htmlFor={inputId} className="block text-sm font-medium">
        {label}
        {props.required ? (
          <span className="ml-0.5 text-destructive" aria-hidden="true">
            *
          </span>
        ) : null}
      </label>

      <input
        {...props}
        id={inputId}
        ref={ref}
        // Screen readers announce the error and hint via these associations
        // rather than the user having to hunt for red text.
        aria-invalid={error ? true : undefined}
        aria-describedby={cn(error && errorId, hint && hintId) || undefined}
        className={cn(
          'w-full rounded-md border border-input bg-background px-3 py-2 text-sm',
          'placeholder:text-muted-foreground',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1',
          'disabled:cursor-not-allowed disabled:opacity-60',
          error && 'border-destructive focus-visible:ring-destructive',
          className,
        )}
      />

      {hint && !error ? (
        <p id={hintId} className="text-xs text-muted-foreground">
          {hint}
        </p>
      ) : null}

      {error ? (
        <p id={errorId} className="text-xs text-destructive" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
});

export function SubmitButton({
  children,
  isPending,
  pendingLabel = 'Please wait…',
  ...props
}: {
  children: ReactNode;
  isPending: boolean;
  pendingLabel?: string;
} & InputHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type="submit"
      // Disabling during flight prevents a double submit creating two accounts
      // or burning two rate-limit slots.
      disabled={isPending || props.disabled}
      aria-busy={isPending}
      className={cn(
        'w-full rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground',
        'transition-opacity hover:opacity-90',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        'disabled:cursor-not-allowed disabled:opacity-60',
      )}
    >
      {isPending ? pendingLabel : children}
    </button>
  );
}

/**
 * Render an API error.
 *
 * Field-level validation errors are shown next to their inputs by the form, so
 * this deliberately shows only the top-level message — repeating them here
 * would duplicate every message on screen.
 */
export function FormError({ error }: { error: unknown }) {
  if (!error) {
    return null;
  }

  const message =
    error instanceof ApiError
      ? error.message
      : error instanceof Error
        ? error.message
        : 'Something went wrong. Please try again.';

  return (
    <div
      role="alert"
      className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2.5 text-sm text-destructive"
    >
      {message}
    </div>
  );
}

export function FormSuccess({ message }: { message: string | null }) {
  if (!message) {
    return null;
  }

  return (
    <div
      role="status"
      className="rounded-md border border-emerald-500/40 bg-emerald-500/5 px-3 py-2.5 text-sm text-emerald-700 dark:text-emerald-400"
    >
      {message}
    </div>
  );
}

/**
 * Map an ApiError's per-field errors onto React Hook Form.
 *
 * Server-side validation can reject things the client cannot check — a
 * duplicate email, a breached password — so those messages must land on the
 * right input rather than only in a banner.
 */
export function applyApiErrors<TFieldValues extends Record<string, unknown>>(
  error: unknown,
  setError: (name: keyof TFieldValues & string, error: { type: string; message: string }) => void,
): boolean {
  if (!(error instanceof ApiError) || !error.isValidationError) {
    return false;
  }

  let applied = false;

  for (const [field, messages] of Object.entries(error.errors)) {
    const message = messages[0];

    if (message) {
      setError(field as keyof TFieldValues & string, { type: 'server', message });
      applied = true;
    }
  }

  return applied;
}

export function AuthCard({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <div className="mx-auto w-full max-w-md px-4 py-12 sm:px-0">
      <div className="rounded-lg border border-border bg-card p-6 sm:p-8">
        <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
        {description ? <p className="mt-1.5 text-sm text-muted-foreground">{description}</p> : null}

        <div className="mt-6">{children}</div>
      </div>

      {footer ? <div className="mt-4 text-center text-sm text-muted-foreground">{footer}</div> : null}
    </div>
  );
}
