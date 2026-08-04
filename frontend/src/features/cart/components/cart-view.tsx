'use client';

import { useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, Loader2, ShoppingBag, Tag } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { formatMinorUnits } from '@/features/catalog/lib/format';
import { ApiError } from '@/lib/api/errors';
import { useApplyCoupon, useCart, useClearCart } from '../hooks/use-cart';
import { CartItem } from './cart-item';

/**
 * The full cart page.
 *
 * Every one of the four states a data-backed page can be in is handled
 * explicitly — loading, error, empty, and populated. The empty and error cases
 * in particular are not afterthoughts: an empty basket rendered as blank space
 * reads as a failure to load, and a failed fetch rendered as an empty basket
 * tells the shopper their items are gone when they are not.
 *
 * No total is computed here. `subtotal`, `discount`, `tax`, and `total` all
 * arrive from the server, which derived them from the catalog — see
 * CartService. Multiplying a price by a quantity in this file would create a
 * second answer, and the second one is always the one that is wrong at
 * checkout.
 */
export function CartView() {
  const config = useStoreConfig();
  const { cart, isLoading, isError, error, refetch } = useCart();
  const clearCart = useClearCart();

  if (isLoading) {
    return (
      <Shell>
        <div className="flex items-center justify-center py-24">
          <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden="true" />
          <span className="sr-only">Loading your cart…</span>
        </div>
      </Shell>
    );
  }

  if (isError) {
    return (
      <Shell>
        <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-6">
          <h2 className="text-sm font-semibold text-destructive">
            Your cart could not be loaded
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">
            {error instanceof ApiError
              ? error.message
              : 'Something went wrong reaching the store. Your items have not been lost.'}
          </p>
          <button
            type="button"
            onClick={() => void refetch()}
            className="mt-4 rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
          >
            Try again
          </button>
        </div>
      </Shell>
    );
  }

  if (cart.items.length === 0) {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border py-20 text-center">
          <ShoppingBag className="size-10 text-muted-foreground/40" aria-hidden="true" />
          <p className="text-base font-medium">Your cart is empty</p>
          <p className="max-w-sm text-sm text-muted-foreground">
            Browse the catalog and add something you like.
          </p>
          <Link
            href="/products"
            className="mt-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
          >
            Start shopping
          </Link>
        </div>
      </Shell>
    );
  }

  return (
    <Shell itemCount={cart.item_count}>
      {cart.has_issues ? (
        <div
          role="status"
          className="mb-6 flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4"
        >
          <AlertTriangle
            className="size-5 shrink-0 text-amber-600 dark:text-amber-500"
            aria-hidden="true"
          />
          <div>
            <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
              Some items need attention
            </p>
            <p className="mt-0.5 text-sm text-amber-700 dark:text-amber-400">
              Prices and availability are checked against the catalog each time you open your
              cart. Items marked below cannot be purchased right now and are excluded from your
              total.
            </p>
          </div>
        </div>
      ) : null}

      <div className="grid gap-8 lg:grid-cols-[1fr_20rem]">
        <section aria-label="Cart items">
          <ul className="divide-y divide-border border-y border-border">
            {cart.items.map((item) => (
              <CartItem key={item.id} item={item} />
            ))}
          </ul>

          <div className="mt-4 flex items-center justify-between">
            <Link
              href="/products"
              className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
            >
              Continue shopping
            </Link>

            <button
              type="button"
              onClick={() => {
                if (window.confirm('Remove everything from your cart?')) {
                  clearCart.mutate();
                }
              }}
              disabled={clearCart.isPending}
              className="text-sm text-muted-foreground underline-offset-4 hover:text-destructive hover:underline disabled:opacity-50"
            >
              {clearCart.isPending ? 'Emptying…' : 'Empty cart'}
            </button>
          </div>
        </section>

        <aside aria-label="Order summary" className="lg:sticky lg:top-24 lg:self-start">
          <div className="rounded-lg border border-border p-5">
            <h2 className="text-sm font-semibold">Order summary</h2>

            <dl className="mt-4 space-y-2 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">
                  Subtotal
                  <span className="ml-1 text-xs">
                    ({cart.item_count} item{cart.item_count === 1 ? '' : 's'})
                  </span>
                </dt>
                <dd className="font-medium">{formatMinorUnits(config, cart.totals.subtotal)}</dd>
              </div>

              {cart.totals.discount > 0 ? (
                <div className="flex justify-between text-emerald-600 dark:text-emerald-400">
                  <dt>Discounts</dt>
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

              <div className="flex justify-between">
                <dt className="text-muted-foreground">Shipping</dt>
                {/*
                  Not a zero. Shipping depends on a delivery address the cart
                  does not have, and showing "Free" or "£0.00" here would be a
                  promise the checkout may not keep.
                */}
                <dd className="text-muted-foreground">Calculated at checkout</dd>
              </div>

              <div className="flex justify-between border-t border-border pt-3 text-base">
                <dt className="font-semibold">Total</dt>
                <dd className="font-semibold">{formatMinorUnits(config, cart.totals.total)}</dd>
              </div>
            </dl>

            <CouponForm currentCode={cart.coupon.code ?? null} message={cart.coupon.message ?? null} />

            <button
              type="button"
              disabled
              title="Checkout arrives in the next phase."
              className="mt-5 w-full cursor-not-allowed rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground opacity-60"
            >
              Proceed to checkout
            </button>

            <p className="mt-2 text-center text-xs text-muted-foreground">
              Checkout and payment arrive in the next phase.
            </p>
          </div>
        </aside>
      </div>
    </Shell>
  );
}

/**
 * The coupon field.
 *
 * Accepts and stores a code, and says plainly that nothing is discounted yet.
 * The alternative — hiding the field until promotions ship — would lose the
 * codes shoppers arrive holding; reporting a £0.00 discount as "applied" would
 * read as a broken promotion rather than an unbuilt feature.
 */
function CouponForm({
  currentCode,
  message,
}: {
  currentCode: string | null;
  message: string | null;
}) {
  const [code, setCode] = useState(currentCode ?? '');
  const [error, setError] = useState<string | null>(null);
  const applyCoupon = useApplyCoupon();

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        setError(null);

        applyCoupon.mutate(code.trim() || null, {
          onError: (mutationError) => {
            setError(
              mutationError instanceof ApiError
                ? mutationError.message
                : 'That code could not be applied.',
            );
          },
        });
      }}
      className="mt-5 border-t border-border pt-5"
    >
      <label htmlFor="coupon" className="mb-1.5 block text-sm font-medium">
        Coupon code
      </label>

      <div className="flex gap-2">
        <div className="relative flex-1">
          <Tag
            className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <input
            id="coupon"
            value={code}
            onChange={(event) => setCode(event.target.value)}
            placeholder="SUMMER20"
            className="w-full rounded-md border border-border bg-background py-2 pl-8 pr-3 text-sm uppercase focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          />
        </div>

        <button
          type="submit"
          disabled={applyCoupon.isPending}
          className="rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted disabled:opacity-50"
        >
          {applyCoupon.isPending ? '…' : 'Apply'}
        </button>
      </div>

      {error ? (
        <p role="alert" className="mt-1.5 text-xs font-medium text-destructive">
          {error}
        </p>
      ) : message ? (
        <p className="mt-1.5 text-xs text-muted-foreground">{message}</p>
      ) : null}
    </form>
  );
}

function Shell({
  children,
  itemCount,
}: {
  children: React.ReactNode;
  itemCount?: number;
}) {
  return (
    <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
      <header className="mb-8">
        <h1 className="text-3xl font-semibold tracking-tight">Your cart</h1>
        {itemCount !== undefined ? (
          <p className="mt-1 text-sm text-muted-foreground">
            {itemCount} item{itemCount === 1 ? '' : 's'}
          </p>
        ) : null}
      </header>

      {children}
    </div>
  );
}
