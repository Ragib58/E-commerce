'use client';

import { useCallback, useState, useTransition } from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { SlidersHorizontal, X } from 'lucide-react';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { cn } from '@/lib/utils/cn';
import { formatMinorUnits, toMajorUnits } from '../lib/format';
import type { CatalogFilters, Category } from '../types';

/**
 * The faceted filter rail.
 *
 * ## Why the URL holds the state
 *
 * Every filter lives in the query string, not in React state. That is what
 * makes a filtered view shareable, survive a refresh, and respond correctly to
 * the back button — none of which works when a filter is component state. It
 * also means the *server* renders the filtered results, so a crawler and a
 * shopper with JavaScript disabled both see real products.
 *
 * Navigation runs inside a transition, so the previous results stay on screen
 * while the server renders the next set instead of flashing an empty grid.
 *
 * ## Layout
 *
 * A persistent sidebar on desktop and a slide-over sheet on mobile, from one
 * implementation. Two would drift, and the mobile one — used by most shoppers —
 * would be the neglected copy.
 */

interface ProductFilterProps {
  filters: CatalogFilters;
  /** Rendered as a facet when present; absent on a category page, which is already scoped. */
  categories?: Category[];
  /** Hides the category facet on a page that is already inside one. */
  hideCategories?: boolean;
}

export function ProductFilter({
  filters,
  categories = [],
  hideCategories = false,
}: ProductFilterProps) {
  const [isOpen, setIsOpen] = useState(false);

  const activeCount = useActiveFilterCount();

  return (
    <>
      {/* Mobile trigger. Hidden on desktop, where the rail is always visible. */}
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        aria-expanded={isOpen}
        className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted lg:hidden"
      >
        <SlidersHorizontal className="size-4" aria-hidden="true" />
        Filters
        {activeCount > 0 ? (
          <span className="rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground">
            {activeCount}
          </span>
        ) : null}
      </button>

      {/* Desktop rail. */}
      <div className="hidden lg:block">
        <FilterPanel filters={filters} categories={categories} hideCategories={hideCategories} />
      </div>

      {/* Mobile sheet. */}
      {isOpen ? (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div
            aria-hidden="true"
            onClick={() => setIsOpen(false)}
            className="absolute inset-0 bg-black/40"
          />

          <div
            role="dialog"
            aria-modal="true"
            aria-label="Product filters"
            className="absolute left-0 top-0 flex h-full w-full max-w-xs flex-col border-r border-border bg-background"
          >
            <header className="flex items-center justify-between border-b border-border px-4 py-3">
              <h2 className="text-sm font-semibold">Filters</h2>
              <button
                type="button"
                onClick={() => setIsOpen(false)}
                aria-label="Close filters"
                className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                <X className="size-5" aria-hidden="true" />
              </button>
            </header>

            <div className="flex-1 overflow-y-auto p-4">
              <FilterPanel
                filters={filters}
                categories={categories}
                hideCategories={hideCategories}
              />
            </div>

            <footer className="border-t border-border p-4">
              <button
                type="button"
                onClick={() => setIsOpen(false)}
                className="w-full rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground"
              >
                Show results
              </button>
            </footer>
          </div>
        </div>
      ) : null}
    </>
  );
}

/**
 * Rewrite the query string, preserving what is not being changed.
 *
 * Shared by every control so one place decides how a filter change affects the
 * rest of the URL — in particular that it always resets pagination.
 */
function useFilterParams() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  const update = useCallback(
    (updates: Record<string, string | null>) => {
      const params = new URLSearchParams(searchParams.toString());

      for (const [key, value] of Object.entries(updates)) {
        if (value === null || value === '') {
          params.delete(key);
        } else {
          params.set(key, value);
        }
      }

      /*
       * Any filter change invalidates the page number. Staying on page 4 of a
       * newly narrowed result set usually lands on nothing, which reads as
       * "the filter returned no products".
       */
      params.delete('page');

      startTransition(() => {
        router.push(`${pathname}?${params.toString()}`, { scroll: false });
      });
    },
    [pathname, router, searchParams],
  );

  const toggleFacet = useCallback(
    (key: string, value: string) => {
      const current = (searchParams.get(key) ?? '').split(',').filter(Boolean);

      const next = current.includes(value)
        ? current.filter((item) => item !== value)
        : [...current, value];

      update({ [key]: next.length > 0 ? next.join(',') : null });
    },
    [searchParams, update],
  );

  return { update, toggleFacet, searchParams, isPending };
}

function useActiveFilterCount(): number {
  const searchParams = useSearchParams();

  let count = 0;

  for (const [key, value] of searchParams.entries()) {
    if (!value) continue;

    // `search`, `sort`, and `page` are context rather than filters — counting
    // them would show a filter badge on an unfiltered page.
    if (key === 'search' || key === 'sort' || key === 'page') continue;

    count += value.split(',').filter(Boolean).length;
  }

  return count;
}

function FilterPanel({
  filters,
  categories,
  hideCategories,
}: {
  filters: CatalogFilters;
  categories: Category[];
  hideCategories: boolean;
}) {
  const { update, toggleFacet, searchParams, isPending } = useFilterParams();
  const activeCount = useActiveFilterCount();

  const activeBrands = (searchParams.get('brand') ?? '').split(',').filter(Boolean);
  const activeCategory = searchParams.get('category');

  return (
    <div className={cn('space-y-6', isPending && 'opacity-70')}>
      {activeCount > 0 ? (
        <button
          type="button"
          onClick={() => {
            // Search and sort are kept: they are the shopper's context, not a
            // filter they asked to drop.
            const params = new URLSearchParams();
            const sort = searchParams.get('sort');
            const search = searchParams.get('search');

            if (sort) params.set('sort', sort);
            if (search) params.set('search', search);

            update(
              Object.fromEntries(
                [...searchParams.keys()]
                  .filter((key) => key !== 'sort' && key !== 'search')
                  .map((key) => [key, null]),
              ),
            );
          }}
          className="text-sm text-primary underline-offset-4 hover:underline"
        >
          Clear all filters ({activeCount})
        </button>
      ) : null}

      {!hideCategories && categories.length > 0 ? (
        <FilterGroup legend="Category">
          <div className="flex flex-col gap-1">
            {categories.map((category) => (
              <label key={category.id} className="flex items-center gap-2 text-sm">
                <input
                  type="radio"
                  name="category"
                  checked={activeCategory === category.slug}
                  onChange={() => update({ category: category.slug })}
                  className="border-border"
                />
                <span className="flex-1">{category.name}</span>
                {typeof category.products_count === 'number' ? (
                  <span className="text-xs text-muted-foreground">
                    {category.products_count}
                  </span>
                ) : null}
              </label>
            ))}

            {activeCategory ? (
              <button
                type="button"
                onClick={() => update({ category: null })}
                className="mt-1 self-start text-xs text-muted-foreground underline-offset-4 hover:underline"
              >
                All categories
              </button>
            ) : null}
          </div>
        </FilterGroup>
      ) : null}

      {filters.brands.length > 0 ? (
        <FilterGroup legend="Brand">
          <div className="flex max-h-60 flex-col gap-1 overflow-y-auto">
            {filters.brands.map((brand) => (
              <label key={brand.id} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={activeBrands.includes(brand.slug)}
                  onChange={() => toggleFacet('brand', brand.slug)}
                  className="rounded border-border"
                />
                {brand.name}
              </label>
            ))}
          </div>
        </FilterGroup>
      ) : null}

      <PriceFilter range={filters.price_range} />

      {/*
        Attribute facets come from the API, so an operator adding "Material"
        from the admin panel gets a working filter with no frontend change.
        Each attribute's own display type decides how it renders.
      */}
      {filters.attributes.map((attribute) => {
        const key = `attr_${attribute.slug}`;
        const active = (searchParams.get(key) ?? '').split(',').filter(Boolean);

        return (
          <FilterGroup key={attribute.id} legend={attribute.name}>
            {attribute.display_type === 'swatch' ? (
              <div className="flex flex-wrap gap-2">
                {attribute.values.map((value) => (
                  <button
                    key={value.id}
                    type="button"
                    onClick={() => toggleFacet(key, value.slug)}
                    aria-pressed={active.includes(value.slug)}
                    aria-label={value.value}
                    title={value.value}
                    style={
                      value.colour_code ? { backgroundColor: value.colour_code } : undefined
                    }
                    className={cn(
                      'size-7 rounded-full border-2 transition-all',
                      active.includes(value.slug)
                        ? 'border-primary ring-2 ring-primary/30'
                        : 'border-border',
                      !value.colour_code && 'bg-muted',
                    )}
                  />
                ))}
              </div>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {attribute.values.map((value) => (
                  <button
                    key={value.id}
                    type="button"
                    onClick={() => toggleFacet(key, value.slug)}
                    aria-pressed={active.includes(value.slug)}
                    className={cn(
                      'rounded border px-2.5 py-1 text-xs transition-colors',
                      active.includes(value.slug)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border hover:border-foreground',
                    )}
                  >
                    {value.value}
                  </button>
                ))}
              </div>
            )}
          </FilterGroup>
        );
      })}

      <FilterGroup legend="Availability">
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={searchParams.get('in_stock') === '1'}
            onChange={(event) => update({ in_stock: event.target.checked ? '1' : null })}
            className="rounded border-border"
          />
          In stock only
        </label>
      </FilterGroup>
    </div>
  );
}

/**
 * The price range.
 *
 * Two number inputs rather than a drag slider: a slider needs a pointer to be
 * usable, is fiddly on a phone, and cannot be operated from a keyboard without
 * significant extra work. Typed bounds are precise, accessible by default, and
 * are what a shopper with a budget in mind actually wants.
 *
 * Bounds are entered in major units (pounds) and converted to the minor units
 * the API expects — the conversion happens here, at the boundary, exactly once.
 */
function PriceFilter({ range }: { range: { min: number; max: number } }) {
  const config = useStoreConfig();
  const { update, searchParams } = useFilterParams();

  const currentMin = searchParams.get('min_price');
  const currentMax = searchParams.get('max_price');

  const toField = (value: string | null) =>
    value ? String(toMajorUnits(Number(value))) : '';

  const [min, setMin] = useState(() => toField(currentMin));
  const [max, setMax] = useState(() => toField(currentMax));

  /*
   * Re-sync when the URL changes from outside this component — "clear all
   * filters", or the back button. Without this the inputs keep showing bounds
   * that are no longer applied.
   *
   * Adjusted during render against a remembered key rather than in an effect.
   * An effect would render once with the stale values and again with the
   * corrected ones, so the cleared filter would visibly flash its old bounds;
   * this is React's documented pattern for deriving state from a changed prop.
   */
  const urlKey = `${currentMin ?? ''}|${currentMax ?? ''}`;
  const [lastUrlKey, setLastUrlKey] = useState(urlKey);

  if (urlKey !== lastUrlKey) {
    setLastUrlKey(urlKey);
    setMin(toField(currentMin));
    setMax(toField(currentMax));
  }

  if (range.max <= 0) return null;

  return (
    <FilterGroup legend="Price">
      <form
        onSubmit={(event) => {
          event.preventDefault();

          update({
            // Major units in the field, minor units on the wire.
            min_price: min ? String(Math.round(Number(min) * 100)) : null,
            max_price: max ? String(Math.round(Number(max) * 100)) : null,
          });
        }}
        className="space-y-2"
      >
        <p className="text-xs text-muted-foreground">
          {formatMinorUnits(config, range.min)} – {formatMinorUnits(config, range.max)}
        </p>

        <div className="flex items-center gap-2">
          <label htmlFor="price-min" className="sr-only">
            Minimum price
          </label>
          <input
            id="price-min"
            type="number"
            inputMode="decimal"
            min={0}
            value={min}
            onChange={(event) => setMin(event.target.value)}
            placeholder="Min"
            className="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          />

          <span aria-hidden="true" className="text-muted-foreground">
            –
          </span>

          <label htmlFor="price-max" className="sr-only">
            Maximum price
          </label>
          <input
            id="price-max"
            type="number"
            inputMode="decimal"
            min={0}
            value={max}
            onChange={(event) => setMax(event.target.value)}
            placeholder="Max"
            className="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          />
        </div>

        <button
          type="submit"
          className="w-full rounded-md border border-border px-3 py-1.5 text-xs font-medium hover:bg-muted"
        >
          Apply
        </button>
      </form>
    </FilterGroup>
  );
}

function FilterGroup({
  legend,
  children,
}: {
  legend: string;
  children: React.ReactNode;
}) {
  return (
    <fieldset className="border-b border-border pb-5 last:border-0 last:pb-0">
      <legend className="mb-2.5 text-sm font-semibold">{legend}</legend>
      {children}
    </fieldset>
  );
}
