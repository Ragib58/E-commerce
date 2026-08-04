'use client';

import Image from 'next/image';
import Link from 'next/link';
import { BarChart2, Check, Loader2, Minus, X } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { AddToCartButton } from '@/features/cart/components/add-to-cart-button';
import { resolveDisplayPrice } from '@/features/catalog/lib/format';
import type { Product } from '@/features/catalog/types';
import { cn } from '@/lib/utils/cn';
import { useCompare, useCompareProducts } from '../hooks/use-compare';

/**
 * Side-by-side product comparison.
 *
 * A real `<table>`, not a grid of divs. The data is genuinely tabular — the
 * same attributes across several products — and a table gives row and column
 * headers that let a screen reader announce "Price, Kettle A, £24.99" instead
 * of reading a wall of disconnected values. That is the difference between a
 * usable comparison and an unusable one.
 *
 * Rows are built from the union of the products' attributes rather than a fixed
 * list, so a variant attribute an operator added last week appears here
 * automatically.
 *
 * The table scrolls inside its own container so a four-column comparison never
 * widens the page on a phone.
 */
export function CompareView() {
  const config = useStoreConfig();
  const { items, remove, clear, isReady, max } = useCompare();
  const { products, isLoading } = useCompareProducts();

  if (!isReady) {
    return (
      <Shell>
        <div className="flex justify-center py-24">
          <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden="true" />
          <span className="sr-only">Loading comparison…</span>
        </div>
      </Shell>
    );
  }

  if (items.length === 0) {
    return (
      <Shell>
        <div className="flex flex-col items-center gap-3 rounded-lg border border-dashed border-border py-20 text-center">
          <BarChart2 className="size-10 text-muted-foreground/40" aria-hidden="true" />
          <p className="text-base font-medium">Nothing to compare yet</p>
          <p className="max-w-sm text-sm text-muted-foreground">
            Add up to {max} products from any listing to see them side by side.
          </p>
          <Link
            href="/products"
            className="mt-2 rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
          >
            Browse products
          </Link>
        </div>
      </Shell>
    );
  }

  if (isLoading) {
    return (
      <Shell>
        <div className="flex justify-center py-24">
          <Loader2 className="size-6 animate-spin text-muted-foreground" aria-hidden="true" />
          <span className="sr-only">Loading products…</span>
        </div>
      </Shell>
    );
  }

  const rows = buildRows(products);

  return (
    <Shell count={products.length}>
      <div className="mb-4 flex justify-end">
        <button
          type="button"
          onClick={clear}
          className="text-sm text-muted-foreground underline-offset-4 hover:text-destructive hover:underline"
        >
          Clear comparison
        </button>
      </div>

      {/* Scrolls within its own box rather than widening the page. */}
      <div className="overflow-x-auto rounded-lg border border-border">
        <table className="w-full min-w-[640px] border-collapse text-sm">
          <caption className="sr-only">
            Comparison of {products.length} products
          </caption>

          <thead>
            <tr className="border-b border-border">
              {/* The corner cell labels the row-header column for assistive
                  technology; leaving it empty makes the first column's purpose
                  unannounced. */}
              <th scope="col" className="w-36 p-3 text-left align-bottom">
                <span className="sr-only">Attribute</span>
              </th>

              {products.map((product) => (
                <th key={product.id} scope="col" className="min-w-48 p-3 align-bottom">
                  <div className="flex flex-col gap-2">
                    <div className="flex justify-end">
                      <button
                        type="button"
                        onClick={() => remove(product.id)}
                        aria-label={`Remove ${product.name} from comparison`}
                        className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-destructive"
                      >
                        <X className="size-4" aria-hidden="true" />
                      </button>
                    </div>

                    <Link
                      href={`/products/${product.slug}`}
                      className="relative mx-auto block aspect-square w-24 overflow-hidden rounded-md bg-muted"
                    >
                      {product.thumbnail ? (
                        <Image
                          src={product.thumbnail}
                          alt=""
                          fill
                          sizes="96px"
                          className="object-cover"
                        />
                      ) : null}
                    </Link>

                    <Link
                      href={`/products/${product.slug}`}
                      className="line-clamp-2 text-sm font-medium hover:underline"
                    >
                      {product.name}
                    </Link>
                  </div>
                </th>
              ))}
            </tr>
          </thead>

          <tbody>
            {rows.map((row) => (
              <tr key={row.label} className="border-b border-border last:border-0">
                {/* `scope="row"` pairs each cell with this label, which is what
                    makes the table readable out of visual order. */}
                <th
                  scope="row"
                  className="bg-muted/40 p-3 text-left align-top text-xs font-medium uppercase tracking-wide text-muted-foreground"
                >
                  {row.label}
                </th>

                {products.map((product) => (
                  <td key={product.id} className="p-3 align-top">
                    {row.render(product, config)}
                  </td>
                ))}
              </tr>
            ))}

            <tr>
              <th scope="row" className="bg-muted/40 p-3 text-left">
                <span className="sr-only">Actions</span>
              </th>

              {products.map((product) => (
                <td key={product.id} className="p-3 align-top">
                  {product.inventory.in_stock && product.type !== 'variable' ? (
                    <AddToCartButton
                      product={product.slug}
                      variantStyle="secondary"
                      className="w-full"
                    />
                  ) : (
                    <Link
                      href={`/products/${product.slug}`}
                      className="inline-flex w-full items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted"
                    >
                      View product
                    </Link>
                  )}
                </td>
              ))}
            </tr>
          </tbody>
        </table>
      </div>
    </Shell>
  );
}

interface CompareRow {
  label: string;
  render: (product: Product, config: ReturnType<typeof useStoreConfig>) => React.ReactNode;
}

/**
 * The rows of the table.
 *
 * The fixed rows are the ones every product has. The attribute rows are derived
 * from the union of the compared products' variant options, so a product that
 * lacks an attribute the others have shows an explicit "—" rather than a blank
 * cell — an absence is information, and a blank reads as missing data.
 */
function buildRows(products: Product[]): CompareRow[] {
  const rows: CompareRow[] = [
    {
      label: 'Price',
      render: (product, config) => {
        const price = resolveDisplayPrice(config, product.pricing);

        return (
          <div>
            <span className="text-base font-semibold">{price.current}</span>
            {price.original ? (
              <span className="ml-2 text-xs text-muted-foreground line-through">
                {price.original}
              </span>
            ) : null}
          </div>
        );
      },
    },
    {
      label: 'Availability',
      render: (product) =>
        product.inventory.in_stock ? (
          <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
            <Check className="size-3.5" aria-hidden="true" />
            In stock
          </span>
        ) : (
          <span className="text-muted-foreground">Out of stock</span>
        ),
    },
    {
      label: 'Brand',
      render: (product) => product.brand?.name ?? <Absent />,
    },
    {
      label: 'Category',
      render: (product) => product.category?.name ?? <Absent />,
    },
    {
      label: 'SKU',
      render: (product) => <span className="font-mono text-xs">{product.sku}</span>,
    },
    {
      label: 'Description',
      render: (product) =>
        product.short_description ? (
          <p className="line-clamp-4 text-xs text-muted-foreground">
            {product.short_description}
          </p>
        ) : (
          <Absent />
        ),
    },
  ];

  /*
   * Attribute rows, from the union across all compared products.
   *
   * Derived rather than hardcoded, so an attribute an operator added from the
   * admin panel is comparable without a frontend change — the same principle
   * that drives the filter rail.
   */
  const attributeNames = new Set<string>();

  for (const product of products) {
    for (const variant of product.variants ?? []) {
      for (const option of variant.options) {
        if (option.attribute_name) attributeNames.add(option.attribute_name);
      }
    }
  }

  for (const name of attributeNames) {
    rows.push({
      label: name,
      render: (product) => {
        const values = new Set<string>();

        for (const variant of product.variants ?? []) {
          for (const option of variant.options) {
            if (option.attribute_name === name) values.add(option.value);
          }
        }

        if (values.size === 0) return <Absent />;

        return (
          <div className="flex flex-wrap gap-1">
            {[...values].map((value) => (
              <span key={value} className="rounded border border-border px-1.5 py-0.5 text-xs">
                {value}
              </span>
            ))}
          </div>
        );
      },
    });
  }

  return rows;
}

/** An explicit absence, so a blank cell never reads as failed data. */
function Absent() {
  return (
    <span className="text-muted-foreground" aria-label="Not applicable">
      <Minus className="size-4" aria-hidden="true" />
    </span>
  );
}

function Shell({ children, count }: { children: React.ReactNode; count?: number }) {
  return (
    <div className={cn('mx-auto max-w-7xl px-4 py-10 sm:px-6')}>
      <header className="mb-8">
        <h1 className="text-3xl font-semibold tracking-tight">Compare products</h1>
        {count !== undefined ? (
          <p className="mt-1 text-sm text-muted-foreground">
            {count} product{count === 1 ? '' : 's'} side by side
          </p>
        ) : null}
      </header>

      {children}
    </div>
  );
}
