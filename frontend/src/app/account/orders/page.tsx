'use client';

import Link from 'next/link';
import { Package } from 'lucide-react';

/**
 * Order history.
 *
 * The orders module lands in the next phase. This page exists now because the
 * account navigation needs somewhere to point, and a nav item that 404s is
 * worse than one that explains itself.
 *
 * Stated plainly rather than dressed as an empty state: "You have no orders
 * yet" would be a lie to a customer who has placed one through another channel,
 * and it would give no hint that the feature is unbuilt.
 */
export default function AccountOrdersPage() {
  return (
    <section aria-labelledby="orders-heading" className="rounded-lg border border-border p-6">
      <h2 id="orders-heading" className="text-base font-semibold">
        Orders
      </h2>

      <div className="flex flex-col items-center gap-3 py-14 text-center">
        <Package className="size-10 text-muted-foreground/40" aria-hidden="true" />
        <p className="text-sm font-medium">Order history is not available yet</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          Checkout and order tracking arrive in the next phase. Your cart and saved items are
          unaffected.
        </p>
        <Link
          href="/products"
          className="mt-2 rounded-md border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted"
        >
          Continue shopping
        </Link>
      </div>
    </section>
  );
}
