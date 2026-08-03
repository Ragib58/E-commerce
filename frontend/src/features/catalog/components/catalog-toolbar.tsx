'use client';

import { useRouter, useSearchParams, usePathname } from 'next/navigation';
import { useCallback, useState, useTransition } from 'react';
import { cn } from '@/lib/utils/cn';
import type { CatalogFilters } from '../types';

/**
 * Search, sort, and facet controls for a product listing.
 *
 * All state lives in the URL rather than in React. That is what makes a
 * filtered view shareable, survive a refresh, and respond correctly to the back
 * button — none of which works when filters are component state.
 *
 * Navigation runs inside a transition so the previous results stay visible
 * while the server renders the next page, instead of flashing an empty grid.
 */

const SORT_LABELS: Record<string, string> = {
  newest: 'Newest',
  oldest: 'Oldest',
  price_asc: 'Price: low to high',
  price_desc: 'Price: high to low',
  name_asc: 'Name: A to Z',
  name_desc: 'Name: Z to A',
};

interface CatalogToolbarProps {
  filters: CatalogFilters;
  sorts: string[];
}

export function CatalogToolbar({ filters, sorts }: CatalogToolbarProps) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  const [search, setSearch] = useState(searchParams.get('search') ?? '');
  const [showFilters, setShowFilters] = useState(false);

  /**
   * Rewrite the query string, preserving everything not being changed.
   */
  const updateParams = useCallback(
    (updates: Record<string, string | null>) => {
      const params = new URLSearchParams(searchParams.toString());

      for (const [key, value] of Object.entries(updates)) {
        if (value === null || value === '') {
          params.delete(key);
        } else {
          params.set(key, value);
        }
      }

      // Any filter change invalidates the current page number: staying on
      // page 4 of a newly narrowed result set usually lands on nothing.
      params.delete('page');

      startTransition(() => {
        router.push(`${pathname}?${params.toString()}`, { scroll: false });
      });
    },
    [pathname, router, searchParams],
  );

  /**
   * Toggle one value of a multi-select facet.
   */
  const toggleFacet = useCallback(
    (key: string, value: string) => {
      const current = (searchParams.get(key) ?? '').split(',').filter(Boolean);

      const next = current.includes(value)
        ? current.filter((item) => item !== value)
        : [...current, value];

      updateParams({ [key]: next.length > 0 ? next.join(',') : null });
    },
    [searchParams, updateParams],
  );

  const activeBrands = (searchParams.get('brand') ?? '').split(',').filter(Boolean);
  const hasActiveFilters =
    activeBrands.length > 0 ||
    searchParams.has('min_price') ||
    searchParams.has('max_price') ||
    searchParams.has('in_stock') ||
    [...searchParams.keys()].some((key) => key.startsWith('attr_'));

  return (
    <div className={cn('flex flex-col gap-4', isPending && 'opacity-70')}>
      <div className="flex flex-wrap items-center gap-3">
        <form
          role="search"
          onSubmit={(event) => {
            event.preventDefault();
            updateParams({ search: search.trim() || null });
          }}
          className="flex flex-1 gap-2 sm:max-w-sm"
        >
          <label htmlFor="catalog-search" className="sr-only">
            Search products
          </label>
          <input
            id="catalog-search"
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search products…"
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          />
          <button
            type="submit"
            className="rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
          >
            Search
          </button>
        </form>

        <div className="flex items-center gap-2">
          <label htmlFor="catalog-sort" className="text-sm text-muted-foreground">
            Sort
          </label>
          <select
            id="catalog-sort"
            value={searchParams.get('sort') ?? 'newest'}
            onChange={(event) => updateParams({ sort: event.target.value })}
            className="rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            {sorts.map((sort) => (
              <option key={sort} value={sort}>
                {SORT_LABELS[sort] ?? sort}
              </option>
            ))}
          </select>
        </div>

        <button
          type="button"
          onClick={() => setShowFilters((open) => !open)}
          aria-expanded={showFilters}
          aria-controls="catalog-filters"
          className="rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
        >
          Filters{hasActiveFilters ? ' •' : ''}
        </button>

        {hasActiveFilters ? (
          <button
            type="button"
            onClick={() => {
              const params = new URLSearchParams();
              const sort = searchParams.get('sort');
              const query = searchParams.get('search');

              // Clearing filters keeps the search term and sort: those are the
              // shopper's context, not a filter they asked to drop.
              if (sort) params.set('sort', sort);
              if (query) params.set('search', query);

              startTransition(() => router.push(`${pathname}?${params.toString()}`));
            }}
            className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
          >
            Clear filters
          </button>
        ) : null}
      </div>

      {showFilters ? (
        <div
          id="catalog-filters"
          className="grid gap-6 rounded-lg border border-border p-4 sm:grid-cols-2 lg:grid-cols-4"
        >
          {filters.brands.length > 0 ? (
            <fieldset>
              <legend className="mb-2 text-sm font-medium">Brand</legend>
              <div className="flex flex-col gap-1">
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
            </fieldset>
          ) : null}

          {/* Attribute facets are rendered from the API's list, so an operator
              adding "Material" gets a working filter with no code change. */}
          {filters.attributes.map((attribute) => {
            const key = `attr_${attribute.slug}`;
            const active = (searchParams.get(key) ?? '').split(',').filter(Boolean);

            return (
              <fieldset key={attribute.id}>
                <legend className="mb-2 text-sm font-medium">{attribute.name}</legend>
                <div className="flex flex-wrap gap-1">
                  {attribute.values.map((value) => (
                    <button
                      key={value.id}
                      type="button"
                      onClick={() => toggleFacet(key, value.slug)}
                      aria-pressed={active.includes(value.slug)}
                      className={cn(
                        'rounded border px-2 py-1 text-xs transition-colors',
                        active.includes(value.slug)
                          ? 'border-primary bg-primary text-primary-foreground'
                          : 'border-border hover:border-foreground',
                      )}
                    >
                      {value.value}
                    </button>
                  ))}
                </div>
              </fieldset>
            );
          })}

          <fieldset>
            <legend className="mb-2 text-sm font-medium">Availability</legend>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={searchParams.get('in_stock') === '1'}
                onChange={(event) =>
                  updateParams({ in_stock: event.target.checked ? '1' : null })
                }
                className="rounded border-border"
              />
              In stock only
            </label>
          </fieldset>
        </div>
      ) : null}
    </div>
  );
}
