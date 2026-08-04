'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { BarChart2, Heart, Menu, Search, ShoppingBag, User, X } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { BrandLogo } from '@/features/settings/components/brand-logo';
import { useCustomerSession } from '@/features/auth/hooks/use-customer-auth';
import { useCart } from '@/features/cart/hooks/use-cart';
import { useCartUiStore } from '@/features/cart/stores/cart-ui-store';
import { useCompare } from '@/features/shopping/hooks/use-compare';
import { useWishlist } from '@/features/shopping/hooks/use-wishlist';

/**
 * Site header.
 *
 * Every visible brand element — the logo, the company-name fallback — is read
 * from the settings context, populated from the admin panel. No brand string
 * appears in this file.
 *
 * The navigation links remain hardcoded. They are storefront *structure*
 * rather than business content — `/products` and `/cart` are routes this
 * application defines — and wiring the header to the menus API is the
 * navigation phase's work.
 */

const NAV_LINKS = [
  { href: '/products', label: 'Shop' },
  { href: '/categories', label: 'Categories' },
] as const;

export function SiteHeader() {
  const { companyName } = useStoreConfig();
  const { user, isAuthenticated, isHydrated } = useCustomerSession();
  const [isMobileNavOpen, setIsMobileNavOpen] = useState(false);

  return (
    <header className="sticky top-0 z-40 w-full border-b border-border bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
      <div className="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6">
        <button
          type="button"
          onClick={() => setIsMobileNavOpen((open) => !open)}
          aria-expanded={isMobileNavOpen}
          aria-label="Toggle navigation"
          className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground md:hidden"
        >
          {isMobileNavOpen ? (
            <X className="size-5" aria-hidden="true" />
          ) : (
            <Menu className="size-5" aria-hidden="true" />
          )}
        </button>

        <Link href="/" className="flex shrink-0 items-center" aria-label={`${companyName} home`}>
          <BrandLogo height={32} />
        </Link>

        <nav aria-label="Main navigation" className="hidden items-center gap-6 md:flex">
          {NAV_LINKS.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="text-sm text-muted-foreground transition-colors hover:text-foreground"
            >
              {link.label}
            </Link>
          ))}
        </nav>

        {/* Takes the remaining width on desktop. On mobile the form moves into
            the expanded nav, where there is room for it. */}
        <div className="mx-auto hidden w-full max-w-md md:block">
          <SearchForm />
        </div>

        <div className="ml-auto flex items-center gap-0.5">
          <CompareLink />
          <WishlistLink />
          <CartButton />

          <AccountMenu
            isHydrated={isHydrated}
            isAuthenticated={isAuthenticated}
            userName={user?.name}
            needsVerification={user?.email_verified === false}
          />
        </div>
      </div>

      {isMobileNavOpen ? (
        <div className="border-t border-border px-4 py-3 md:hidden">
          <SearchForm onSubmitted={() => setIsMobileNavOpen(false)} />

          <nav aria-label="Main navigation" className="mt-3 flex flex-col gap-1">
            {NAV_LINKS.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                onClick={() => setIsMobileNavOpen(false)}
                className="rounded-md px-2 py-2 text-sm hover:bg-muted"
              >
                {link.label}
              </Link>
            ))}
            <Link
              href="/wishlist"
              onClick={() => setIsMobileNavOpen(false)}
              className="rounded-md px-2 py-2 text-sm hover:bg-muted"
            >
              Wishlist
            </Link>
          </nav>
        </div>
      ) : null}
    </header>
  );
}

/**
 * The search form.
 *
 * A real form that navigates to `/search?q=`, not a client-side filter. That
 * makes the results a shareable URL rendered on the server — and, because the
 * form has a `method`-less `action` and a named input, pressing Enter works
 * before any JavaScript has loaded.
 */
function SearchForm({ onSubmitted }: { onSubmitted?: () => void }) {
  const router = useRouter();
  const [query, setQuery] = useState('');

  return (
    <form
      role="search"
      action="/search"
      onSubmit={(event) => {
        event.preventDefault();

        const trimmed = query.trim();

        // An empty search would land on /search, which redirects to the shop —
        // a pointless round trip, so it is refused here.
        if (trimmed === '') return;

        router.push(`/search?q=${encodeURIComponent(trimmed)}`);
        onSubmitted?.();
      }}
      className="relative"
    >
      <label htmlFor="site-search" className="sr-only">
        Search products
      </label>

      <Search
        className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden="true"
      />

      <input
        id="site-search"
        // `name` matters: without JavaScript the browser submits this as a GET
        // to /search?q=…, which is exactly the URL the route expects.
        name="q"
        type="search"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        placeholder="Search products…"
        className="w-full rounded-md border border-border bg-background py-2 pl-9 pr-3 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      />
    </form>
  );
}

/**
 * The cart button and its badge.
 *
 * Opens the drawer rather than navigating: a shopper adjusting quantities
 * should not lose the page they were browsing. The full cart page is one click
 * further, from inside the drawer.
 */
function CartButton() {
  const { cart } = useCart();
  const toggle = useCartUiStore((state) => state.toggle);

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={
        cart.item_count > 0
          ? `Open cart, ${cart.item_count} item${cart.item_count === 1 ? '' : 's'}`
          : 'Open cart'
      }
      className="relative rounded-md p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      <ShoppingBag className="size-5" aria-hidden="true" />
      <CountBadge count={cart.item_count} />
    </button>
  );
}

function WishlistLink() {
  const { count, isReady } = useWishlist();

  return (
    <Link
      href="/wishlist"
      aria-label={count > 0 ? `Wishlist, ${count} saved` : 'Wishlist'}
      className="relative hidden rounded-md p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary sm:block"
    >
      <Heart className="size-5" aria-hidden="true" />
      {/* Held until hydration: the server rendered no badge, so drawing one on
          the first client pass would be a hydration mismatch. */}
      {isReady ? <CountBadge count={count} /> : null}
    </Link>
  );
}

function CompareLink() {
  const { count, isReady } = useCompare();

  // Absent entirely when empty. A permanent compare icon that does nothing on
  // most visits is chrome nobody asked for; it appears once it has content.
  if (!isReady || count === 0) return null;

  return (
    <Link
      href="/compare"
      aria-label={`Compare, ${count} product${count === 1 ? '' : 's'}`}
      className="relative rounded-md p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      <BarChart2 className="size-5" aria-hidden="true" />
      <CountBadge count={count} />
    </Link>
  );
}

function CountBadge({ count }: { count: number }) {
  if (count <= 0) return null;

  return (
    <span
      // Decorative: the count is already in the control's aria-label, and
      // announcing it twice is noise.
      aria-hidden="true"
      className="absolute -right-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-4 text-primary-foreground"
    >
      {count > 99 ? '99+' : count}
    </span>
  );
}

function AccountMenu({
  isHydrated,
  isAuthenticated,
  userName,
  needsVerification,
}: {
  isHydrated: boolean;
  isAuthenticated: boolean;
  userName?: string;
  needsVerification: boolean;
}) {
  /*
   * A fixed-size placeholder until the session rehydrates.
   *
   * Showing "Sign in" to an authenticated shopper for one frame reads as a
   * dropped session, and the reserved space stops the icon row shifting when
   * the real control appears.
   */
  if (!isHydrated) {
    return <div className="size-9" aria-hidden="true" />;
  }

  if (!isAuthenticated) {
    return (
      <Link
        href="/login"
        className="ml-1 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        Sign in
      </Link>
    );
  }

  return (
    <Link
      href="/account"
      aria-label={`Your account, signed in as ${userName ?? 'customer'}`}
      className="relative rounded-md p-2 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      <User className="size-5" aria-hidden="true" />

      {/* A dot rather than a word: the explanation lives on the account page,
          and a header badge only needs to draw attention. */}
      {needsVerification ? (
        <span
          aria-hidden="true"
          className="absolute right-1 top-1 size-2 rounded-full bg-amber-500"
        />
      ) : null}
    </Link>
  );
}
