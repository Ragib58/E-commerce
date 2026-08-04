'use client';

import { useMemo, useState } from 'react';
import type { StoreConfig } from '@/features/settings/lib/store-config';
import { AddToCartButton } from '@/features/cart/components/add-to-cart-button';
import { CompareToggle, WishlistToggle } from '@/features/shopping/components/product-actions';
import { useRecordProductView } from '@/features/shopping/hooks/use-compare';
import { resolveDisplayPrice } from '../lib/format';
import type { Product } from '../types';
import { ProductGallery } from './product-gallery';
import {
  VariantSelector,
  deriveAttributeGroups,
  findMatchingVariant,
} from './variant-selector';

/**
 * The interactive half of a product page.
 *
 * The page shell around it stays a server component; only this subtree is
 * shipped to the browser, because only this part reacts to a shopper choosing
 * an option. It owns the selection state that the gallery and the price both
 * read from, which is why they are rendered here rather than assembled in the
 * page.
 */

interface ProductDetailProps {
  product: Product;
  config: StoreConfig;
}

export function ProductDetail({ product, config }: ProductDetailProps) {
  const variants = useMemo(() => product.variants ?? [], [product.variants]);
  const groups = useMemo(() => deriveAttributeGroups(variants), [variants]);

  /**
   * Start from the default variant, so the page opens with a price and a
   * usable buy button rather than an empty selection.
   */
  const [selection, setSelection] = useState<Record<string, string>>(() => {
    const initial = variants.find((variant) => variant.is_default) ?? variants[0];

    if (!initial) return {};

    return Object.fromEntries(
      initial.options
        .filter((option) => option.attribute)
        .map((option) => [option.attribute as string, option.slug]),
    );
  });

  const selectedVariant = useMemo(
    () => findMatchingVariant(variants, selection, groups),
    [variants, selection, groups],
  );

  const isVariable = groups.length > 0;

  /*
   * A variable product's price and stock come from the chosen variant; a simple
   * product's come from the product itself. Falling back to the product's
   * pricing while a selection is incomplete would advertise a price the shopper
   * cannot actually buy at.
   */
  const pricing = selectedVariant?.pricing ?? product.pricing;
  const inventory = selectedVariant?.inventory ?? product.inventory;
  const price = resolveDisplayPrice(config, pricing);

  const awaitingSelection = isVariable && selectedVariant === null;
  const canPurchase = !awaitingSelection && inventory.in_stock;

  const [quantity, setQuantity] = useState(1);

  /*
   * Record the visit for the recently-viewed rail.
   *
   * Here rather than in the page shell because the shell is a server component
   * and this is per-device browsing history that never leaves the browser.
   */
  useRecordProductView(product.id);

  function handleSelect(attributeSlug: string, valueSlug: string) {
    setSelection((current) => ({ ...current, [attributeSlug]: valueSlug }));
  }

  return (
    <div className="grid gap-8 lg:grid-cols-2">
      <ProductGallery
        media={product.media ?? []}
        productName={product.name}
        videoUrl={product.video_url}
        activeVariantId={selectedVariant?.id}
      />

      <div className="flex flex-col gap-6">
        <div>
          {product.brand ? (
            <p className="text-sm uppercase tracking-wide text-muted-foreground">
              {product.brand.name}
            </p>
          ) : null}

          <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
            {product.name}
          </h1>

          <p className="mt-2 text-sm text-muted-foreground">
            SKU: {selectedVariant?.sku ?? product.sku}
          </p>
        </div>

        <div className="flex items-baseline gap-3">
          <span className="text-3xl font-semibold">{price.current}</span>
          {price.original ? (
            <span className="text-lg text-muted-foreground line-through">{price.original}</span>
          ) : null}
          {pricing.discount_percentage ? (
            <span className="rounded bg-destructive px-2 py-0.5 text-sm font-semibold text-white">
              -{pricing.discount_percentage}%
            </span>
          ) : null}
        </div>

        {product.short_description ? (
          <p className="text-muted-foreground">{product.short_description}</p>
        ) : null}

        {isVariable ? (
          <VariantSelector
            variants={variants}
            selection={selection}
            onSelect={handleSelect}
          />
        ) : null}

        <div className="flex flex-col gap-3">
          <p
            className="text-sm font-medium"
            // Availability changes as options are chosen, so it must be
            // announced rather than only re-rendered.
            aria-live="polite"
          >
            {awaitingSelection ? (
              <span className="text-muted-foreground">
                Select {groups.map((group) => group.name.toLowerCase()).join(' and ')} to continue
              </span>
            ) : inventory.in_stock ? (
              <span className="text-emerald-600">
                In stock
                {inventory.allow_backorder && !inventory.in_stock ? ' — available on backorder' : ''}
              </span>
            ) : (
              <span className="text-destructive">Out of stock</span>
            )}
          </p>

          <div className="flex flex-wrap items-end gap-3">
            {/*
              The quantity stepper is bounded by nothing here on purpose: the
              server checks availability against the resulting total and
              refuses what it cannot fulfil, with a message naming the real
              figure. Clamping locally would need the exact stock count, which
              the public API deliberately does not publish.
            */}
            <div>
              <label htmlFor="quantity" className="mb-1 block text-sm font-medium">
                Quantity
              </label>
              <input
                id="quantity"
                type="number"
                min={1}
                max={99}
                value={quantity}
                onChange={(event) =>
                  setQuantity(Math.max(1, Math.min(99, Number(event.target.value) || 1)))
                }
                className="w-20 rounded-md border border-border bg-background px-3 py-2.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              />
            </div>

            <AddToCartButton
              product={product.slug}
              // The chosen variant's public id. A variable product without one
              // is refused by the API, which is why the button is disabled
              // until the selection resolves.
              variant={selectedVariant?.id ?? null}
              quantity={quantity}
              disabled={!canPurchase}
              disabledReason={
                awaitingSelection
                  ? 'Choose an option to continue.'
                  : !inventory.in_stock
                    ? 'This item is out of stock.'
                    : undefined
              }
              className="flex-1 sm:flex-none"
            />
          </div>

          <div className="flex flex-wrap gap-2">
            <WishlistToggle
              identifier={product.id}
              productName={product.name}
              appearance="button"
            />
            <CompareToggle
              identifier={product.id}
              productName={product.name}
              appearance="button"
            />
          </div>
        </div>

        {product.description ? (
          <div className="border-t border-border pt-6">
            <h2 className="mb-2 text-lg font-medium">Description</h2>
            <div className="whitespace-pre-line text-sm leading-relaxed text-muted-foreground">
              {product.description}
            </div>
          </div>
        ) : null}

        {product.shipping?.weight ? (
          <div className="border-t border-border pt-6 text-sm text-muted-foreground">
            <h2 className="mb-2 text-lg font-medium text-foreground">Specifications</h2>
            <dl className="grid grid-cols-2 gap-1">
              <dt>Weight</dt>
              <dd>{product.shipping.weight} g</dd>
              {product.shipping.dimensions?.length ? (
                <>
                  <dt>Dimensions</dt>
                  <dd>
                    {product.shipping.dimensions.length} × {product.shipping.dimensions.width} ×{' '}
                    {product.shipping.dimensions.height} mm
                  </dd>
                </>
              ) : null}
            </dl>
          </div>
        ) : null}
      </div>
    </div>
  );
}
