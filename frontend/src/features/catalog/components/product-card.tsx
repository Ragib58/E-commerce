import Link from 'next/link';
import Image from 'next/image';
import type { StoreConfig } from '@/features/settings/lib/store-config';
import { resolveDisplayPrice } from '../lib/format';
import type { Product } from '../types';

/**
 * One product in a grid.
 *
 * A server component: it renders static catalog data with no interactivity, so
 * shipping it as client JavaScript would cost bundle size for nothing. Only the
 * variant picker on the detail page genuinely needs the client.
 */

interface ProductCardProps {
  product: Product;
  config: StoreConfig;
  /**
   * Cards above the fold should not lazy-load — the largest contentful paint on
   * a listing page is usually the first row's imagery.
   */
  priority?: boolean;
}

export function ProductCard({ product, config, priority = false }: ProductCardProps) {
  const price = resolveDisplayPrice(config, product.pricing);
  const isOutOfStock = !product.inventory.in_stock;

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-lg border border-border bg-card transition-shadow hover:shadow-md">
      <Link
        href={`/products/${product.slug}`}
        className="flex flex-1 flex-col focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <div className="relative aspect-square overflow-hidden bg-muted">
          {product.thumbnail ? (
            <Image
              src={product.thumbnail}
              alt={product.name}
              fill
              // Tells the browser which resolution it actually needs, so a
              // phone does not download a desktop-sized image.
              sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
              priority={priority}
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

          <h3 className="line-clamp-2 text-sm font-medium leading-snug">{product.name}</h3>

          <div className="mt-auto flex items-baseline gap-2 pt-2">
            <span className="text-base font-semibold">{price.current}</span>
            {price.original ? (
              <span className="text-sm text-muted-foreground line-through">{price.original}</span>
            ) : null}
          </div>

          {/* A nudge, not a number: the exact figure is deliberately not
              public, but "only a few left" still converts. */}
          {product.inventory.low_stock && !isOutOfStock ? (
            <p className="text-xs font-medium text-amber-600">Low stock</p>
          ) : null}
        </div>
      </Link>
    </article>
  );
}

/**
 * A responsive grid of product cards, with an explicit empty state.
 */
export function ProductGrid({
  products,
  config,
  emptyMessage = 'No products match your selection.',
}: {
  products: Product[];
  config: StoreConfig;
  emptyMessage?: string;
}) {
  if (products.length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-border py-16 text-center">
        <p className="text-sm text-muted-foreground">{emptyMessage}</p>
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      {products.map((product, index) => (
        <ProductCard
          key={product.id}
          product={product}
          config={config}
          // The first row only.
          priority={index < 4}
        />
      ))}
    </div>
  );
}
