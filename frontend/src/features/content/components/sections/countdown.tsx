'use client';

import { useCallback, useSyncExternalStore } from 'react';

/**
 * A countdown to a flash sale's end.
 *
 * A client component because it ticks, and mounted deliberately late: the
 * server and the client would otherwise disagree about "now" and React would
 * report a hydration mismatch on every render. `null` until mounted means the
 * server emits nothing here and the first client paint fills it in.
 *
 * The wrapper is what carries the label and the layout; this renders only the
 * digits, so a sale whose end date passes while the page is open collapses to
 * "Ended" without leaving a broken clock on screen.
 */

interface CountdownProps {
  /** ISO-8601 timestamp. */
  endsAt: string;
}

interface Remaining {
  days: number;
  hours: number;
  minutes: number;
  seconds: number;
  isOver: boolean;
}

/**
 * @param target Epoch milliseconds the countdown runs to.
 * @param now    Epoch milliseconds "now", passed in rather than read here so
 *               rendering is a pure function of the tick — the same input
 *               always produces the same output, which is what lets
 *               `useSyncExternalStore` decide when a re-render is warranted.
 */
function remainingUntil(target: number, now: number): Remaining {
  const diff = target - now;

  if (diff <= 0) {
    return { days: 0, hours: 0, minutes: 0, seconds: 0, isOver: true };
  }

  const totalSeconds = Math.floor(diff / 1000);

  return {
    days: Math.floor(totalSeconds / 86400),
    hours: Math.floor((totalSeconds % 86400) / 3600),
    minutes: Math.floor((totalSeconds % 3600) / 60),
    seconds: totalSeconds % 60,
    isOver: false,
  };
}

/**
 * The current time, truncated to whole seconds.
 *
 * The truncation is load-bearing, not cosmetic. `useSyncExternalStore` compares
 * successive snapshots with `Object.is` and re-renders whenever they differ, so
 * a raw `Date.now()` — which changes on every single call — would report a new
 * value on each render and spin forever. Flooring to the second makes the
 * snapshot stable between ticks, which is exactly the contract the hook
 * requires.
 */
function currentSecond(): number {
  return Math.floor(Date.now() / 1000);
}

/**
 * A clock that ticks once a second, as an external store.
 *
 * `useSyncExternalStore` rather than an effect that calls setState on mount:
 * the wall clock is genuinely external state, and this is the API built for
 * reading one. It also gets SSR right — the server snapshot is null, so the
 * markup React renders on the server matches the first client render and no
 * hydration mismatch occurs.
 */
function useNow(stopAfter: number): number | null {
  const subscribe = useCallback(
    (onChange: () => void) => {
      const timer = window.setInterval(() => {
        onChange();

        /*
         * The interval stops itself once the target has passed.
         *
         * Done here rather than by flipping a state flag in an effect: a timer
         * that keeps firing on a finished countdown re-renders this component
         * every second for as long as the tab stays open, and stopping it is
         * the subscription's own business, not the component's.
         */
        if (Date.now() >= stopAfter) window.clearInterval(timer);
      }, 1000);

      return () => window.clearInterval(timer);
    },
    [stopAfter],
  );

  return useSyncExternalStore(
    subscribe,
    currentSecond,
    // Null on the server: the countdown renders a height placeholder until the
    // client knows what "now" is.
    () => null,
  );
}

export function Countdown({ endsAt }: CountdownProps) {
  const target = new Date(endsAt).getTime();
  const isValid = !Number.isNaN(target);

  // An invalid date stops the ticker immediately rather than counting toward a
  // NaN that would render as zeroes forever.
  const now = useNow(isValid ? target : 0);

  // `now` is in whole seconds; remainingUntil works in milliseconds.
  const remaining = now === null || !isValid ? null : remainingUntil(target, now * 1000);

  if (remaining === null) {
    // Reserves the row's height before hydration so the section does not jump
    // when the digits appear.
    return <div className="h-[58px]" aria-hidden="true" />;
  }

  if (remaining.isOver) {
    return <p className="text-sm font-medium text-muted-foreground">This offer has ended.</p>;
  }

  return (
    <div
      className="flex items-center gap-2"
      // The ticking seconds must not be announced every second; the label below
      // gives a screen reader the whole remaining time once.
      aria-hidden="true"
    >
      {remaining.days > 0 ? <TimeCell value={remaining.days} label="days" /> : null}
      <TimeCell value={remaining.hours} label="hrs" />
      <TimeCell value={remaining.minutes} label="min" />
      <TimeCell value={remaining.seconds} label="sec" />
    </div>
  );
}

function TimeCell({ value, label }: { value: number; label: string }) {
  return (
    <div className="flex min-w-[52px] flex-col items-center rounded-md bg-foreground px-2 py-1.5 text-background">
      <span className="font-mono text-lg font-semibold leading-none tabular-nums">
        {String(value).padStart(2, '0')}
      </span>
      <span className="mt-0.5 text-[10px] uppercase tracking-wide opacity-75">{label}</span>
    </div>
  );
}
