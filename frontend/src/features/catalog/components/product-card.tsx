import Link from 'next/link';
import Image from 'next/image';
import type { StoreConfig } from '@/features/settings/lib/store-config';
import { AddToCartButton } from '@/features/cart/components/add-to-cart-button';
import { CompareToggle, WishlistToggle } from '@/features/shopping/components/product-actions';
import { cn } from '@/lib/utils/cn';
import { resolveDisplayPrice } from '../lib/format';
import type { Product } from '../types';

/**
 * One product in a grid.
 *
 * Still a server component. The card's markup — image, name, price, badges — is
 * static catalog data rendered on the server, so a shopper and a crawler both
 * receive it in the HTML. Only the wishlist, compare, and add-to-cart controls
 * are client components, imported into it.
 *
 * That split is the point: making the whole card a client component to get a
 * working wishlist button would ship every product's markup as JavaScript, on a
 * page that renders twenty-four of them.
 *
 * ## The link structure
 *
 * The title carries the only anchor, stretched across the card with an
 * `::after` overlay so the whole tile is clickable. The alternative — wrapping
 * everything in one `<Link>` — would nest the action buttons inside an anchor,
 * which is invalid HTML and leaves them unreachable for assistive technology
 * regardless of how many `preventDefault` calls are added. The buttons sit
 * above the overlay on the z-axis, so they receive their own clicks.
 */

interface ProductCardProps {
  product: Product;
  config: StoreConfig;
  /**
   * Cards above the fold should not lazy-load — the largest contentful paint on
   * a listing page is usually the first row's imagery.
   */
  priority?: boolean;
  /**
   * Quick-add straight from the grid.
   *
   * Ignored for variable products: they need an option chosen, and the API
   * refuses a variable product with no variant. Sending the shopper to the
   * detail page is the correct outcome, not a request that cannot succeed.
   */
  showQuickAdd?: boolean;
}

export function ProductCard({
  product,
  config,
  priority = false,
  showQuickAdd = true,
}: ProductCardProps) {
  const price = resolveDisplayPrice(config, product.pricing);
  const isOutOfStock = !product.inventory.in_stock;

  const canQuickAdd = showQuickAdd && !isOutOfStock && product.type !== 'variable';

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-lg border border-border bg-card transition-shadow hover:shadow-md">
      <div className="relative aspect-square overflow-hidden bg-muted">
        {product.thumbnail ? (
          <Image
            src={product.thumbnail}
            alt=""
            fill
            // Tells the browser which resolution it actually needs, so a phone
            // does not download a desktop-sized image.
            sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
            priority={priority}
            loading={priority ? 'eager' : 'lazy'}
            className="object-cover transition-transform duration-300 group-hover:scale-105"
          />
        ) : (
          <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
            No image
          </div>
        )}

        <div className="absolute left-2 top-2 flex flex-col gap-1">
          {price.hasDiscount && product.pricing.discount_percentage ? (
            <span className="rounded bg-destructive px-2 py-0.5 text-xs font-semibold text-white">
              -{product.pricing.discount_percentage}%
            </span>
          ) : null}

          {product.flags?.is_new_arrival ? (
            <span className="rounded bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground">
              New
            </span>
          ) : null}
        </div>

        {/* Above the stretched link's overlay, so these receive their clicks. */}
        <div className="absolute right-2 top-2 z-20 flex flex-col gap-1.5">
          <WishlistToggle identifier={product.id} productName={product.name} />
          <CompareToggle identifier={product.id} productName={product.name} />
        </div>

        {isOutOfStock ? (
          <div className="absolute inset-0 flex items-center justify-center bg-background/70">
            <span className="rounded bg-foreground px-3 py-1 text-xs font-semibold uppercase tracking-wide text-background">
              Out of stock
            </span>
          </div>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col gap-1 p-3">
        {product.brand ? (
          <p className="text-xs uppercase tracking-wide text-muted-foreground">
            {product.brand.name}
          </p>
        ) : null}

        <h3 className="line-clamp-2 text-sm font-medium leading-snug">
          <Link
            href={`/products/${product.slug}`}
            // The stretched link: the anchor itself is inline, but its ::after
            // covers the card, making the whole tile clickable without nesting
            // anything inside it.
            className={cn(
              'after:absolute after:inset-0 after:z-10 after:content-[""]',
              'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
            )}
          >
            {product.name}
          </Link>
        </h3>

        <div className="mt-auto flex items-baseline gap-2 pt-2">
          <span className="text-base font-semibold">{price.current}</span>
          {price.original ? (
            <span className="text-sm text-muted-foreground line-through">{price.original}</span>
          ) : null}
        </div>

        {/* A nudge, not a number: the exact figure is deliberately not public,
            but "only a few left" still converts. */}
        {product.inventory.low_stock && !isOutOfStock ? (
          <p className="text-xs font-medium text-amber-600">Low stock</p>
        ) : null}

        {canQuickAdd ? (
          <div className="relative z-20 mt-2">
            <AddToCartButton
              product={product.slug}
              variantStyle="secondary"
              label="Add to cart"
              className="w-full"
            />
          </div>
        ) : null}
      </div>
    </article>
  );
}

/**
 * A responsive grid of product cards, with an explicit empty state.
 *
 * The empty state is a first-class case rather than a bare absence: a shopper
 * whose filters matched nothing needs to be told that, not shown blank space
 * they will read as a failure to load.
 */
export function ProductGrid({
  products,
  config,
  emptyMessage = 'No products match your selection.',
  columns = 4,
  showQuickAdd = true,
}: {
  products: Product[];
  config: StoreConfig;
  emptyMessage?: string;
  columns?: 2 | 3 | 4;
  showQuickAdd?: boolean;
}) {
  if (products.length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-border py-16 text-center">
        <p className="text-sm text-muted-foreground">{emptyMessage}</p>
      </div>
    );
  }

  // A lookup rather than an interpolated class: Tailwind scans source
  // statically, so `lg:grid-cols-${n}` is never emitted into the stylesheet.
  const gridClass = {
    2: 'grid-cols-1 sm:grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-2 lg:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4',
  }[columns];

  return (
    <div className={cn('grid gap-4', gridClass)}>
      {products.map((product, index) => (
        <ProductCard
          key={product.id}
          product={product}
          config={config}
          showQuickAdd={showQuickAdd}
          // The first row only. Marking more makes them compete and defeats
          // the purpose of the hint.
          priority={index < columns}
        />
      ))}
    </div>
  );
}

/**
 * Placeholder tiles while a grid loads.
 *
 * Shaped like the real cards so the page does not reflow when products arrive —
 * a spinner replaced by content of a different height is a visible jump, and on
 * a listing page it moves everything the shopper was about to click.
 */
export function ProductGridSkeleton({ count = 8 }: { count?: number }) {
  return (
    <div
      className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
      aria-hidden="true"
    >
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className="overflow-hidden rounded-lg border border-border">
          <div className="aspect-square animate-pulse bg-muted" />
          <div className="space-y-2 p-3">
            <div className="h-3 w-1/3 animate-pulse rounded bg-muted" />
            <div className="h-4 w-4/5 animate-pulse rounded bg-muted" />
            <div className="h-5 w-1/2 animate-pulse rounded bg-muted" />
          </div>
        </div>
      ))}
    </div>
  );
}
