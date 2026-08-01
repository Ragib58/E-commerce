export default function Loading() {
  return (
    <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6" role="status" aria-label="Loading">
      <div className="h-4 w-32 animate-pulse rounded bg-muted" />
      <div className="mt-4 h-10 w-80 max-w-full animate-pulse rounded bg-muted" />
      <div className="mt-3 h-5 w-64 max-w-full animate-pulse rounded bg-muted" />

      <div className="mt-12 grid gap-6 md:grid-cols-2">
        <div className="h-56 animate-pulse rounded-lg bg-muted" />
        <div className="h-56 animate-pulse rounded-lg bg-muted" />
      </div>

      <span className="sr-only">Loading page content</span>
    </div>
  );
}
