'use client';

import { useRouter, usePathname } from 'next/navigation';
import { useEffect, type ReactNode } from 'react';
import { useCustomerSession, useCurrentCustomer } from '../hooks/use-customer-auth';

/**
 * Protects customer-only pages.
 *
 * `requireVerified` mirrors the API's `verified` middleware: endpoints that
 * mutate the account reject an unverified caller, so the UI should not present
 * a form that cannot succeed.
 */
export function CustomerGuard({
  children,
  requireVerified = false,
}: {
  children: ReactNode;
  requireVerified?: boolean;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { isAuthenticated, isHydrated, isVerified } = useCustomerSession();
  const { isPending } = useCurrentCustomer();

  useEffect(() => {
    // Waiting for rehydration prevents a refresh bouncing a signed-in user.
    if (!isHydrated) {
      return;
    }

    if (!isAuthenticated) {
      router.replace(`/login?next=${encodeURIComponent(pathname)}`);
    }
  }, [isHydrated, isAuthenticated, pathname, router]);

  if (!isHydrated || (isAuthenticated && isPending)) {
    return (
      <div className="flex min-h-[40vh] items-center justify-center" role="status" aria-live="polite">
        <p className="text-sm text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  if (requireVerified && !isVerified) {
    return (
      <div className="mx-auto max-w-lg px-4 py-16 text-center">
        <h1 className="text-xl font-semibold tracking-tight">Verify your email address</h1>
        <p className="mt-3 text-muted-foreground">
          This section becomes available once you have confirmed your email address. Check your
          inbox for the verification link.
        </p>
      </div>
    );
  }

  return <>{children}</>;
}
