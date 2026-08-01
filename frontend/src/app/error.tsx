'use client';

import { useEffect } from 'react';

/**
 * Route-level error boundary.
 *
 * Renders whatever branding is already in the DOM rather than fetching
 * settings again — the API being unreachable is a likely cause of getting here
 * in the first place.
 */
export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    console.error('[app] Unhandled render error.', error);
  }, [error]);

  return (
    <div className="mx-auto flex max-w-2xl flex-col items-start px-4 py-24 sm:px-6">
      <h1 className="text-2xl font-semibold tracking-tight">Something went wrong</h1>
      <p className="mt-3 text-muted-foreground">
        The page could not be displayed. This is usually temporary.
      </p>

      {/* The digest correlates this render with the server log entry without
          exposing the underlying message to the visitor. */}
      {error.digest ? (
        <p className="mt-2 text-xs text-muted-foreground">Reference: {error.digest}</p>
      ) : null}

      <button
        type="button"
        onClick={reset}
        className="mt-6 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
      >
        Try again
      </button>
    </div>
  );
}
