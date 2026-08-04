'use client';

import { useEffect, useRef } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { ShoppingBag, X } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { formatMinorUnits } from '@/features/catalog/lib/format';
import { cn } from '@/lib/utils/cn';
import { useCart } from '../hooks/use-cart';
import { useCartUiStore } from '../stores/cart-ui-store';
import { CartItem } from './cart-item';

/**
 * The slide-over cart.
 *
 * Rendered once in the root layout rather than per page, so opening it never
 * remounts its contents and the quantity controls keep their pending state
 * across a navigation.
 *
 * Implemented as a focus-trapping modal by hand rather than with a dialog
 * library: the interaction is a single panel, and the accessible behaviour it
 * needs — labelled dialog role, Escape to close, focus moved in and restored
 * on close, background made inert — is a few lines here against a dependency
 * that would ship far more.
 */
export function CartDrawer() {
  const config = useStoreConfig();
  const isOpen = useCartUiStore((state) => state.isOpen);
  const close = useCartUiStore((state) => state.close);
  const { cart, isLoading } = useCart();

  const panelRef = useRef<HTMLDivElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  /** The element focused before opening, so it can be restored on close. */
  const previouslyFocused = useRef<HTMLElement | null>(null);

  const pathname = usePathname();

  // Navigating away closes the drawer. Leaving it open over a new page reads
  // as the panel having failed to dismiss.
  useEffect(() => {
    close();
  }, [pathname, close]);

  useEffect(() => {
    if (!isOpen) return;

    previouslyFocused.current = document.activeElement as HTMLElement | null;

    // Focus moves into the panel, so a keyboard user is not left tabbing
    // through the page behind it.
    closeButtonRef.current?.focus();

    // The page behind must not scroll under the overlay.
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();

        return;
      }

      if (event.key !== 'Tab') return;

      /*
       * Focus trap. Without it, Tab walks out of the panel into the page
       * behind, which is still visible but not operable — a keyboard user
       * ends up focused on something they cannot see.
       */
      const focusable = panelRef.current?.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
      );

      if (!focusable || focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (!first || !last) return;

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
      // Focus returns where it came from, so the shopper resumes at the button
      // they opened the drawer with rather than at the top of the document.
      previouslyFocused.current?.focus();
    };
  }, [isOpen, close]);

  if (!isOpen) return null;

  const hasItems = cart.items.length > 0;

  return (
    <div className="fixed inset-0 z-50">
      {/* Decorative: Escape and the labelled close button are the accessible
          ways out, so this must not appear in the tab order. */}
      <div
        aria-hidden="true"
        onClick={close}
        className="absolute inset-0 bg-black/40 backdrop-blur-[2px]"
      />

      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label="Shopping cart"
        className="absolute right-0 top-0 flex h-full w-full max-w-md flex-col border-l border-border bg-background shadow-xl"
      >
        <header className="flex items-center justify-between border-b border-border px-4 py-3">
          <h2 className="flex items-center gap-2 text-base font-semibold">
            <ShoppingBag className="size-4" aria-hidden="true" />
            Your cart
            {cart.item_count > 0 ? (
              <span className="text-sm font-normal text-muted-foreground">
                ({cart.item_count})
              </span>
            ) : null}
          </h2>

          <button
            ref={closeButtonRef}
            type="button"
            onClick={close}
            aria-label="Close cart"
            className="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <X className="size-5" aria-hidden="true" />
          </button>
        </header>

        {cart.has_issues ? (
          <div role="status" className="border-b border-amber-500/30 bg-amber-500/10 px-4 py-2.5">
            <p className="text-xs text-amber-700 dark:text-amber-400">
              Some items need your attention before checkout.
            </p>
          </div>
        ) : null}

        <div className="flex-1 overflow-y-auto px-4">
          {isLoading && !hasItems ? (
            <CartSkeleton />
          ) : !hasItems ? (
            <EmptyCart onBrowse={close} />
          ) : (
            <ul className="divide-y divide-border">
              {cart.items.map((item) => (
                <CartItem key={item.id} item={item} compact />
              ))}
            </ul>
          )}
        </div>

        {hasItems ? (
          <footer className="space-y-3 border-t border-border px-4 py-4">
            <dl className="space-y-1.5 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Subtotal</dt>
                <dd className="font-medium">{formatMinorUnits(config, cart.totals.subtotal)}</dd>
              </div>

              {cart.totals.discount > 0 ? (
                <div className="flex justify-between text-emerald-600 dark:text-emerald-400">
                  <dt>You save</dt>
                  <dd className="font-medium">
                    −{formatMinorUnits(config, cart.totals.discount)}
                  </dd>
                </div>
              ) : null}

              {cart.totals.tax > 0 ? (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Tax</dt>
                  <dd>{formatMinorUnits(config, cart.totals.tax)}</dd>
                </div>
              ) : null}

              <div className="flex justify-between border-t border-border pt-1.5 text-base">
                <dt className="font-semibold">Total</dt>
                <dd className="font-semibold">{formatMinorUnits(config, cart.totals.total)}</dd>
              </div>
            </dl>

            {/* Stated rather than silently omitted: a total that grows at
                checkout without warning reads as a hidden cost. */}
            <p className="text-xs text-muted-foreground">
              Shipping calculated at checkout.
            </p>

            <div className="flex flex-col gap-2">
              <Link
                href="/cart"
                onClick={close}
                className="rounded-md bg-primary px-4 py-2.5 text-center text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
              >
                View cart
              </Link>

              <button
                type="button"
                onClick={close}
                className="rounded-md border border-border px-4 py-2.5 text-sm font-medium transition-colors hover:bg-muted"
              >
                Continue shopping
              </button>
            </div>
          </footer>
        ) : null}
      </div>
    </div>
  );
}

function EmptyCart({ onBrowse }: { onBrowse: () => void }) {
  return (
    <div className="flex h-full flex-col items-center justify-center gap-3 py-16 text-center">
      <ShoppingBag className="size-10 text-muted-foreground/40" aria-hidden="true" />
      <p className="text-sm font-medium">Your cart is empty</p>
      <p className="max-w-xs text-sm text-muted-foreground">
        Items you add will appear here.
      </p>
      <Link
        href="/products"
        onClick={onBrowse}
        className="mt-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
      >
        Browse products
      </Link>
    </div>
  );
}

/**
 * Placeholder rows while the first fetch resolves.
 *
 * Shaped like the real lines so the panel does not resize when they arrive —
 * a spinner followed by content of a different height is a visible jump.
 */
function CartSkeleton() {
  return (
    <ul className="divide-y divide-border" aria-hidden="true">
      {[0, 1, 2].map((row) => (
        <li key={row} className="flex gap-3 py-4">
          <div className="size-20 shrink-0 animate-pulse rounded-md bg-muted" />
          <div className="flex-1 space-y-2 py-1">
            <div className="h-3.5 w-3/4 animate-pulse rounded bg-muted" />
            <div className="h-3 w-1/3 animate-pulse rounded bg-muted" />
            <div className={cn('h-7 w-24 animate-pulse rounded bg-muted')} />
          </div>
        </li>
      ))}
    </ul>
  );
}
