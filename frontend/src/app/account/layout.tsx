'use client';

import type { ReactNode } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Heart, LogOut, Package, User } from 'lucide-react';

import { CustomerGuard } from '@/features/auth/components/customer-guard';
import { useLogout } from '@/features/auth/hooks/use-customer-auth';
import { cn } from '@/lib/utils/cn';

/**
 * The My Account area.
 *
 * One guard around the whole section rather than one per page. The guard
 * redirects an unauthenticated visitor to the login page with a `next`
 * parameter, so signing in returns them to the page they were trying to reach
 * rather than to the account root.
 *
 * `requireVerified` is deliberately *not* set here. An unverified customer must
 * be able to reach their profile — it is where they discover why parts of the
 * store are blocked and can request another verification email. The individual
 * forms that the API rejects for unverified accounts carry that constraint
 * themselves.
 */

const NAV_ITEMS = [
  { href: '/account', label: 'Profile', icon: User, exact: true },
  { href: '/account/orders', label: 'Orders', icon: Package, exact: false },
  { href: '/account/wishlist', label: 'Wishlist', icon: Heart, exact: false },
] as const;

export default function AccountLayout({ children }: { children: ReactNode }) {
  return (
    <CustomerGuard>
      <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <h1 className="mb-8 text-3xl font-semibold tracking-tight">Your account</h1>

        <div className="grid gap-8 md:grid-cols-[14rem_1fr]">
          <AccountNav />
          <div className="min-w-0">{children}</div>
        </div>
      </div>
    </CustomerGuard>
  );
}

function AccountNav() {
  const pathname = usePathname();
  const logout = useLogout();

  return (
    <nav aria-label="Account" className="md:sticky md:top-24 md:self-start">
      <ul className="flex gap-1 overflow-x-auto md:flex-col md:overflow-visible">
        {NAV_ITEMS.map((item) => {
          /*
           * `/account` needs an exact match; everything else matches its
           * prefix. Without the distinction the profile link stays highlighted
           * on every sub-page, since they all start with `/account`.
           */
          const isActive = item.exact
            ? pathname === item.href
            : pathname.startsWith(item.href);

          const Icon = item.icon;

          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={isActive ? 'page' : undefined}
                className={cn(
                  'flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm transition-colors',
                  isActive
                    ? 'bg-muted font-medium text-foreground'
                    : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                )}
              >
                <Icon className="size-4" aria-hidden="true" />
                {item.label}
              </Link>
            </li>
          );
        })}

        <li className="md:mt-4 md:border-t md:border-border md:pt-4">
          <button
            type="button"
            onClick={() => logout.mutate()}
            disabled={logout.isPending}
            className="flex w-full items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-muted/60 hover:text-destructive disabled:opacity-60"
          >
            <LogOut className="size-4" aria-hidden="true" />
            {logout.isPending ? 'Signing out…' : 'Sign out'}
          </button>
        </li>
      </ul>
    </nav>
  );
}
