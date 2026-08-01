import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="mx-auto flex max-w-2xl flex-col items-start px-4 py-24 sm:px-6">
      <p className="text-sm font-medium text-primary">404</p>
      <h1 className="mt-2 text-2xl font-semibold tracking-tight">Page not found</h1>
      <p className="mt-3 text-muted-foreground">
        The page you are looking for does not exist or has been moved.
      </p>

      <Link
        href="/"
        className="mt-6 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
      >
        Back to home
      </Link>
    </div>
  );
}
