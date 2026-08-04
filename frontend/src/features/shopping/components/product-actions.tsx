'use client';

import { useState } from 'react';
import { BarChart2, Heart } from 'lucide-react';

import { cn } from '@/lib/utils/cn';
import { useCompare } from '../hooks/use-compare';
import { useWishlist } from '../hooks/use-wishlist';

/**
 * Wishlist and compare toggles.
 *
 * Both render a neutral, unpressed control until their store has rehydrated.
 * That is not cosmetic: the server renders the empty state, so drawing a filled
 * heart on the first client pass is a React hydration mismatch as well as a
 * visible flicker on every card in a grid.
 */

interface ToggleProps {
  /** The product's public identifier — a uuid. */
  identifier: string;
  /** For the accessible label, so a grid of icons is not a row of "Save". */
  productName: string;
  className?: string;
  /** Solid pill for a detail page; bare icon for a card corner. */
  appearance?: 'button' | 'icon';
}

export function WishlistToggle({
  identifier,
  productName,
  className,
  appearance = 'icon',
}: ToggleProps) {
  const { isSaved, toggle, isReady, isPending } = useWishlist();

  // Before hydration this is always false, matching the server's render.
  const saved = isReady && isSaved(identifier);

  const label = saved
    ? `Remove ${productName} from your wishlist`
    : `Save ${productName} to your wishlist`;

  if (appearance === 'button') {
    return (
      <button
        type="button"
        onClick={() => toggle(identifier)}
        disabled={!isReady || isPending}
        aria-pressed={saved}
        aria-label={label}
        className={cn(
          'inline-flex items-center justify-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
          saved && 'border-destructive/40 text-destructive',
          className,
        )}
      >
        <Heart className={cn('size-4', saved && 'fill-current')} aria-hidden="true" />
        <span>{saved ? 'Saved' : 'Save'}</span>
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={(event) => {
        // Cards wrap their content in a link; without this the click navigates
        // to the product instead of saving it.
        event.preventDefault();
        event.stopPropagation();
        toggle(identifier);
      }}
      disabled={!isReady || isPending}
      aria-pressed={saved}
      aria-label={label}
      className={cn(
        'rounded-full bg-background/85 p-1.5 shadow-sm backdrop-blur transition-colors hover:bg-background disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
        className,
      )}
    >
      <Heart
        className={cn(
          'size-4 transition-colors',
          saved ? 'fill-destructive text-destructive' : 'text-muted-foreground',
        )}
        aria-hidden="true"
      />
    </button>
  );
}

export function CompareToggle({
  identifier,
  productName,
  className,
  appearance = 'icon',
}: ToggleProps) {
  const { has, toggle, isReady, isFull, max } = useCompare();
  const [refused, setRefused] = useState(false);

  const active = isReady && has(identifier);

  function handleToggle() {
    const accepted = toggle(identifier);

    /*
     * The tray is full and this product is not already in it.
     *
     * Surfaced rather than silently ignored: a button that does nothing when
     * clicked is indistinguishable from a broken one.
     */
    if (!accepted) {
      setRefused(true);
      window.setTimeout(() => setRefused(false), 3000);
    }
  }

  const label = active
    ? `Remove ${productName} from comparison`
    : `Add ${productName} to comparison`;

  if (appearance === 'button') {
    return (
      <div className={cn('flex flex-col gap-1', className)}>
        <button
          type="button"
          onClick={handleToggle}
          disabled={!isReady || (isFull && !active)}
          aria-pressed={active}
          aria-label={label}
          className={cn(
            'inline-flex items-center justify-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
            active && 'border-primary text-primary',
          )}
        >
          <BarChart2 className="size-4" aria-hidden="true" />
          <span>{active ? 'Comparing' : 'Compare'}</span>
        </button>

        {isFull && !active ? (
          <p className="text-xs text-muted-foreground">
            You can compare up to {max} products.
          </p>
        ) : null}
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={(event) => {
        event.preventDefault();
        event.stopPropagation();
        handleToggle();
      }}
      disabled={!isReady}
      aria-pressed={active}
      aria-label={label}
      title={refused ? `You can compare up to ${max} products.` : undefined}
      className={cn(
        'rounded-full bg-background/85 p-1.5 shadow-sm backdrop-blur transition-colors hover:bg-background disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
        className,
      )}
    >
      <BarChart2
        className={cn('size-4 transition-colors', active ? 'text-primary' : 'text-muted-foreground')}
        aria-hidden="true"
      />
    </button>
  );
}
