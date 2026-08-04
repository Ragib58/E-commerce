'use client';

import Image from 'next/image';
import Link from 'next/link';
import { Minus, Plus, Trash2 } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { formatMinorUnits } from '@/features/catalog/lib/format';
import { cn } from '@/lib/utils/cn';
import type { CartItem as CartItemType } from '../types';
import { useRemoveCartItem, useUpdateCartItem } from '../hooks/use-cart';

/**
 * One line in the cart.
 *
 * Shared by the drawer and the full cart page — `compact` switches the layout
 * without duplicating the quantity controls, the issue messaging, or the price
 * formatting. Two implementations of "is this line buyable?" would eventually
 * disagree, and the one in the drawer is the one a shopper acts on.
 *
 * Every figure rendered here arrives from the server. Nothing multiplies a
 * price by a quantity locally: `line_total` is computed by CartService, and
 * recomputing it would create a second answer that can differ from the one at
 * checkout.
 */

interface CartItemProps {
  item: CartItemType;
  /** Drawer layout: tighter, no per-line subtotal column. */
  compact?: boolean;
}

export function CartItem({ item, compact = false }: CartItemProps) {
  const config = useStoreConfig();
  const updateItem = useUpdateCartItem();
  const removeItem = useRemoveCartItem();

  const isMutating = updateItem.isPending || removeItem.isPending;

  /*
   * The ceiling on the quantity stepper.
   *
   * `max_quantity` is null for products that are not stock-tracked — a digital
   * download, or one on backorder — which is deliberately distinct from 0. The
   * `??` chain must not collapse the two: treating null as 0 would disable the
   * stepper on exactly the products that have no limit.
   */
  const maxQuantity = item.max_quantity ?? 99;
  const canIncrease = !isMutating && item.quantity < maxQuantity;

  const issue = item.issues[0];

  return (
    <li
      className={cn(
        'flex gap-3 py-4',
        // Dimmed rather than hidden: a shopper needs to see what became
        // unavailable, or they will assume the site lost it.
        !item.is_available && 'opacity-60',
      )}
    >
      <Link
        href={`/products/${item.product.slug}`}
        className="relative size-20 shrink-0 overflow-hidden rounded-md border border-border bg-muted"
      >
        {item.product.thumbnail ? (
          <Image
            src={item.product.thumbnail}
            alt=""
            fill
            sizes="80px"
            className="object-cover"
          />
        ) : null}
      </Link>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <Link
              href={`/products/${item.product.slug}`}
              className="line-clamp-2 text-sm font-medium hover:underline"
            >
              {item.product.name}
            </Link>

            {item.variant ? (
              <p className="mt-0.5 text-xs text-muted-foreground">{item.variant.name}</p>
            ) : null}

            {/* Personalisation, for customizable products. */}
            {item.options && Object.keys(item.options).length > 0 ? (
              <dl className="mt-1 space-y-0.5 text-xs text-muted-foreground">
                {Object.entries(item.options).map(([key, value]) => (
                  <div key={key} className="flex gap-1">
                    <dt className="font-medium">{key}:</dt>
                    <dd className="truncate">{value}</dd>
                  </div>
                ))}
              </dl>
            ) : null}
          </div>

          {!compact ? (
            <div className="shrink-0 text-right">
              <p className="text-sm font-semibold">
                {formatMinorUnits(config, item.line_total)}
              </p>
              {item.list_price ? (
                <p className="text-xs text-muted-foreground line-through">
                  {formatMinorUnits(config, item.list_price * item.quantity)}
                </p>
              ) : null}
            </div>
          ) : null}
        </div>

        <div className="mt-auto flex flex-wrap items-center justify-between gap-2 pt-1">
          <div className="flex items-center gap-2">
            <div className="flex items-center rounded-md border border-border">
              <button
                type="button"
                onClick={() =>
                  updateItem.mutate({ itemId: item.id, quantity: item.quantity - 1 })
                }
                disabled={isMutating}
                // Names the product, so a screen-reader user with several lines
                // knows which one this control belongs to.
                aria-label={`Decrease quantity of ${item.product.name}`}
                className="p-1.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
              >
                <Minus className="size-3.5" aria-hidden="true" />
              </button>

              <span
                className="min-w-8 text-center text-sm tabular-nums"
                aria-label={`Quantity: ${item.quantity}`}
              >
                {item.quantity}
              </span>

              <button
                type="button"
                onClick={() =>
                  updateItem.mutate({ itemId: item.id, quantity: item.quantity + 1 })
                }
                disabled={!canIncrease}
                aria-label={`Increase quantity of ${item.product.name}`}
                className="p-1.5 text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40"
              >
                <Plus className="size-3.5" aria-hidden="true" />
              </button>
            </div>

            <span className="text-xs text-muted-foreground">
              {formatMinorUnits(config, item.unit_price)} each
            </span>
          </div>

          <div className="flex items-center gap-3">
            {compact ? (
              <span className="text-sm font-semibold">
                {formatMinorUnits(config, item.line_total)}
              </span>
            ) : null}

            <button
              type="button"
              onClick={() => removeItem.mutate(item.id)}
              disabled={isMutating}
              aria-label={`Remove ${item.product.name} from your cart`}
              className="text-muted-foreground transition-colors hover:text-destructive disabled:opacity-40"
            >
              <Trash2 className="size-4" aria-hidden="true" />
            </button>
          </div>
        </div>

        {/*
          The blocking issue, if any. `role="status"` rather than "alert": this
          is information about a line the shopper is looking at, not an
          interruption that should preempt whatever they are reading.
        */}
        {issue ? (
          <p role="status" className="mt-1 text-xs font-medium text-amber-600 dark:text-amber-500">
            {issue.message}
          </p>
        ) : null}
      </div>
    </li>
  );
}
