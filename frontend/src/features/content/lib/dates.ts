/**
 * Conversions between the API's ISO-8601 timestamps and the value a
 * `datetime-local` input expects.
 *
 * These exist because the two formats disagree about timezones in a way that
 * silently corrupts a schedule if ignored:
 *
 *   - `datetime-local` has **no** timezone. Its value is "2026-11-27T09:00",
 *     which the operator means in their own timezone.
 *   - The API stores and returns UTC.
 *
 * Passing an ISO string straight into the input displays the UTC wall-clock
 * time, so an operator in UTC+6 who scheduled a sale for 9am sees 3am — and,
 * worse, submitting that value back shifts the schedule by six hours on every
 * save. The round trip below is what keeps a saved time stable.
 */

/**
 * ISO-8601 (UTC) → the local wall-clock string a `datetime-local` shows.
 *
 * Built from the local getters rather than by slicing the ISO string, which is
 * the usual shortcut and is exactly the bug described above.
 */
export function toDateTimeLocal(iso: string | null | undefined): string {
  if (!iso) return '';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '';

  const pad = (value: number) => String(value).padStart(2, '0');

  return (
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}`
  );
}

/**
 * A `datetime-local` value → an ISO-8601 UTC string for the API.
 *
 * Returns null for an empty input, which is how the API is told to *clear* a
 * bound — distinct from omitting the key, which leaves it unchanged.
 */
export function fromDateTimeLocal(value: string): string | null {
  if (!value.trim()) return null;

  // `new Date('2026-11-27T09:00')` is interpreted in the local timezone, which
  // is what the operator meant; toISOString then converts it to UTC.
  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

/**
 * A short, human-readable timestamp for admin tables.
 */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return '—';

  return date.toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/**
 * A plain-language description of a scheduling window.
 *
 * Written out because two dates in a table tell an operator less than one
 * sentence does — "Until 3 Dec" is read correctly at a glance, while a pair of
 * timestamps has to be compared against today's date first.
 */
export function describeWindow(
  startsAt: string | null | undefined,
  endsAt: string | null | undefined,
): string {
  if (!startsAt && !endsAt) return 'Always';

  if (startsAt && !endsAt) return `From ${formatDateTime(startsAt)}`;
  if (!startsAt && endsAt) return `Until ${formatDateTime(endsAt)}`;

  return `${formatDateTime(startsAt)} — ${formatDateTime(endsAt)}`;
}
