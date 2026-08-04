'use client';

import { useState } from 'react';
import { Check, Loader2, ShoppingCart } from 'lucide-react';

import { ApiError } from '@/lib/api/errors';
import { cn } from '@/lib/utils/cn';
import { useAddToCart } from '../hooks/use-cart';

/**
 * Add a product to the cart.
 *
 * Note what is *not* passed in: a price. The button sends a product
 * identifier, an optional variant, and a quantity — the server prices the line.
 * A `price` prop here would be the first step toward a client-computed total.
 *
 * The API's own validation message is surfaced on failure rather than a generic
 * one. "Only 2 of this item are available" tells a shopper what to do next;
 * "Could not add to cart" does not.
 */

interface AddToCartButtonProps {
  /** Product slug or uuid. */
  product: string;
  /** Required for variable products; the server refuses without it. */
  variant?: string | null;
  quantity?: number;
  options?: Record<string, string> | null;
  /** Disables the control for reasons the caller knows — e.g. no variant picked. */
  disabled?: boolean;
  disabledReason?: string;
  variantStyle?: 'primary' | 'secondary' | 'icon';
  className?: string;
  label?: string;
}

export function AddToCartButton({
  product,
  variant = null,
  quantity = 1,
  options = null,
  disabled = false,
  disabledReason,
  variantStyle = 'primary',
  className,
  label = 'Add to cart',
}: AddToCartButtonProps) {
  const addToCart = useAddToCart();
  const [error, setError] = useState<string | null>(null);
  const [justAdded, setJustAdded] = useState(false);

  function handleClick() {
    setError(null);

    addToCart.mutate(
      { product, variant, quantity, options },
      {
        onSuccess: () => {
          /*
           * A brief confirmation on the button itself.
           *
           * The drawer opening is the primary feedback; this covers the case
           * where a shopper's attention is on the button they just pressed
           * rather than on the panel sliding in beside it.
           */
          setJustAdded(true);
          window.setTimeout(() => setJustAdded(false), 2000);
        },
        onError: (mutationError) => {
          setError(
            mutationError instanceof ApiError
              ? mutationError.message
              : 'This item could not be added to your cart.',
          );
        },
      },
    );
  }

  const isBusy = addToCart.isPending;
  const isDisabled = disabled || isBusy;

  const styles: Record<string, string> = {
    primary:
      'inline-flex items-center justify-center gap-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90',
    secondary:
      'inline-flex items-center justify-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted',
    icon: 'inline-flex items-center justify-center rounded-md border border-border p-2 transition-colors hover:bg-muted',
  };

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <button
        type="button"
        onClick={handleClick}
        disabled={isDisabled}
        title={disabled ? disabledReason : undefined}
        aria-label={variantStyle === 'icon' ? label : undefined}
        className={cn(
          styles[variantStyle],
          'disabled:cursor-not-allowed disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
        )}
      >
        {isBusy ? (
          <Loader2 className="size-4 animate-spin" aria-hidden="true" />
        ) : justAdded ? (
          <Check className="size-4" aria-hidden="true" />
        ) : (
          <ShoppingCart className="size-4" aria-hidden="true" />
        )}

        {variantStyle !== 'icon' ? (
          <span>{isBusy ? 'Adding…' : justAdded ? 'Added' : label}</span>
        ) : null}
      </button>

      {/*
        `role="alert"` because this is the outcome of an action the shopper just
        took and needs to hear about immediately — unlike the passive line-issue
        notices in the cart, which use `status`.
      */}
      {error ? (
        <p role="alert" className="text-xs font-medium text-destructive">
          {error}
        </p>
      ) : null}

      {disabled && disabledReason && !error ? (
        <p className="text-xs text-muted-foreground">{disabledReason}</p>
      ) : null}
    </div>
  );
}
