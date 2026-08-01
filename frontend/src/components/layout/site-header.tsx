'use client';

import Image from 'next/image';
import Link from 'next/link';
import { useSettings } from '@/components/providers/settings-provider';
import { useCustomerSession, useLogout } from '@/features/auth/hooks/use-customer-auth';

/**
 * Site header.
 *
 * Every visible element — the logo image, the company name fallback, the
 * tagline — is read from the settings context, which was populated from the
 * admin panel. No brand string appears in this file.
 *
 * Navigation links are hardcoded in this phase because the menus API is
 * delivered in a later phase; they are the one deliberate exception and are
 * replaced by a `useMenu('header')` call at that point.
 */
export function SiteHeader() {
  const { settings } = useSettings();
  const { user, isAuthenticated, isHydrated } = useCustomerSession();
  const logout = useLogout();

  const companyName = settings.general?.company_name ?? 'Store';
  const logo = settings.branding?.logo;

  return (
    <header className="sticky top-0 z-40 w-full border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
        <Link href="/" className="flex items-center gap-2.5" aria-label={`${companyName} home`}>
          {logo ? (
            <Image
              src={logo}
              alt={companyName}
              width={140}
              height={36}
              priority
              className="h-9 w-auto object-contain"
            />
          ) : (
            // Until an administrator uploads a logo, render the company name
            // as a wordmark rather than a broken image placeholder.
            <span className="text-lg font-semibold tracking-tight">{companyName}</span>
          )}
        </Link>

        <nav aria-label="Main navigation" className="hidden items-center gap-6 md:flex">
          <Link
            href="/"
            className="text-sm text-muted-foreground transition-colors hover:text-foreground"
          >
            Home
          </Link>
          <span
            className="cursor-not-allowed text-sm text-muted-foreground/50"
            title="Available in a later phase"
          >
            Shop
          </span>
          <span
            className="cursor-not-allowed text-sm text-muted-foreground/50"
            title="Available in a later phase"
          >
            Categories
          </span>
        </nav>

        {/* Rendered only after rehydration: showing "Sign in" to an
            authenticated user for one frame reads as a session loss. */}
        <div className="flex items-center gap-3">
          {!isHydrated ? (
            <div className="h-8 w-32" aria-hidden="true" />
          ) : isAuthenticated ? (
            <>
              <span className="hidden text-sm text-muted-foreground sm:inline">
                {user?.name ?? 'Account'}
              </span>

              {user?.email_verified === false ? (
                <Link
                  href="/verify-email"
                  className="rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400"
                >
                  Verify email
                </Link>
              ) : null}

              <button
                type="button"
                onClick={() => logout.mutate()}
                disabled={logout.isPending}
                className="rounded-md border border-border px-3 py-1.5 text-sm transition-colors hover:bg-muted disabled:opacity-60"
              >
                {logout.isPending ? 'Signing out…' : 'Sign out'}
              </button>
            </>
          ) : (
            <>
              <Link
                href="/login"
                className="text-sm text-muted-foreground transition-colors hover:text-foreground"
              >
                Sign in
              </Link>
              <Link
                href="/register"
                className="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
              >
                Register
              </Link>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
