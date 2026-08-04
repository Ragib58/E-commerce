'use client';

import { useId, type ReactNode } from 'react';

import { cn } from '@/lib/utils/cn';
import type { WindowState } from '../../types';

/**
 * Form primitives shared by the content admin screens.
 *
 * The homepage builder, the banner manager, and the page editor all render the
 * same field shapes — a labelled input, a scheduling pair, a status chip — and
 * duplicating them three times is how the three drift into looking like three
 * different products.
 */

const CONTROL_CLASS =
  'w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-not-allowed disabled:opacity-60';

export function Field({
  label,
  hint,
  error,
  children,
  className,
}: {
  label: string;
  hint?: string;
  error?: string;
  children: (props: { id: string; describedBy?: string }) => ReactNode;
  className?: string;
}) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;

  // The control is wired to whichever of hint/error is present, so a screen
  // reader announces the guidance or the failure rather than only the label.
  const describedBy = [hint ? hintId : null, error ? errorId : null]
    .filter(Boolean)
    .join(' ') || undefined;

  return (
    <div className={cn('space-y-1.5', className)}>
      <label htmlFor={id} className="block text-sm font-medium">
        {label}
      </label>

      {children({ id, describedBy })}

      {hint && !error ? (
        <p id={hintId} className="text-xs text-muted-foreground">
          {hint}
        </p>
      ) : null}

      {error ? (
        <p id={errorId} className="text-xs font-medium text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}

export function TextInput(
  props: React.InputHTMLAttributes<HTMLInputElement> & { describedBy?: string },
) {
  const { describedBy, className, ...rest } = props;

  return <input {...rest} aria-describedby={describedBy} className={cn(CONTROL_CLASS, className)} />;
}

export function TextArea(
  props: React.TextareaHTMLAttributes<HTMLTextAreaElement> & { describedBy?: string },
) {
  const { describedBy, className, ...rest } = props;

  return (
    <textarea {...rest} aria-describedby={describedBy} className={cn(CONTROL_CLASS, className)} />
  );
}

export function Select(
  props: React.SelectHTMLAttributes<HTMLSelectElement> & { describedBy?: string },
) {
  const { describedBy, className, children, ...rest } = props;

  return (
    <select {...rest} aria-describedby={describedBy} className={cn(CONTROL_CLASS, className)}>
      {children}
    </select>
  );
}

export function Checkbox({
  label,
  hint,
  checked,
  onChange,
  disabled,
}: {
  label: string;
  hint?: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
  disabled?: boolean;
}) {
  const id = useId();

  return (
    <div className="flex items-start gap-2.5">
      <input
        id={id}
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(event) => onChange(event.target.checked)}
        className="mt-0.5 size-4 rounded border-border text-primary focus:ring-2 focus:ring-primary"
      />
      <div>
        <label htmlFor={id} className="text-sm font-medium">
          {label}
        </label>
        {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
      </div>
    </div>
  );
}

/**
 * The start/end scheduling pair.
 *
 * Rendered as `datetime-local` inputs, which produce a value with no timezone
 * — so the conversions in ../lib/dates exist to pin them to the operator's own
 * timezone rather than letting the browser and the API disagree about what
 * "9am" meant.
 *
 * Both ends are clearable, and the empty state is labelled rather than left to
 * be inferred: "no end date" means the campaign runs indefinitely, which is the
 * opposite of what a blank field usually implies.
 */
export function ScheduleFields({
  startsAt,
  endsAt,
  onChange,
  disabled,
}: {
  startsAt: string;
  endsAt: string;
  onChange: (field: 'starts_at' | 'ends_at', value: string) => void;
  disabled?: boolean;
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <Field
        label="Starts"
        hint={startsAt ? undefined : 'Live immediately once enabled.'}
      >
        {({ id, describedBy }) => (
          <TextInput
            id={id}
            type="datetime-local"
            value={startsAt}
            disabled={disabled}
            describedBy={describedBy}
            onChange={(event) => onChange('starts_at', event.target.value)}
          />
        )}
      </Field>

      <Field label="Ends" hint={endsAt ? undefined : 'Runs indefinitely.'}>
        {({ id, describedBy }) => (
          <TextInput
            id={id}
            type="datetime-local"
            value={endsAt}
            // The end cannot precede the start. The API validates this too;
            // the attribute just stops the operator entering it in the first
            // place, which is a better experience than a rejected save.
            min={startsAt || undefined}
            disabled={disabled}
            describedBy={describedBy}
            onChange={(event) => onChange('ends_at', event.target.value)}
          />
        )}
      </Field>
    </div>
  );
}

/**
 * The chip showing where a record sits relative to its schedule.
 *
 * `window_state` is computed server-side, so the panel and the storefront
 * cannot disagree about whether something is live — the one place a
 * re-derivation on the client would be actively harmful.
 */
export function WindowBadge({
  state,
  isEnabled = true,
}: {
  state: WindowState;
  isEnabled?: boolean;
}) {
  if (!isEnabled) {
    return <Badge tone="muted">Disabled</Badge>;
  }

  switch (state) {
    case 'scheduled':
      return <Badge tone="info">Scheduled</Badge>;
    case 'expired':
      return <Badge tone="warning">Expired</Badge>;
    default:
      return <Badge tone="success">Live</Badge>;
  }
}

export function Badge({
  children,
  tone = 'muted',
}: {
  children: ReactNode;
  tone?: 'muted' | 'success' | 'info' | 'warning' | 'danger';
}) {
  const tones: Record<string, string> = {
    muted: 'bg-muted text-muted-foreground',
    success: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    info: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    danger: 'bg-destructive/10 text-destructive',
  };

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        tones[tone],
      )}
    >
      {children}
    </span>
  );
}

export { CONTROL_CLASS };
