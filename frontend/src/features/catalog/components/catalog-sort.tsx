'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useTransition } from 'react';

import { cn } from '@/lib/utils/cn';

/**
 * The sort control.
 *
 * Separated from the filter rail because the two live in different places on
 * the page — sort sits above the grid with the result count, filters sit beside
 * it — and bundling them forced the old toolbar to render both in one row on
 * every layout.
 *
 * Labels are mapped from the API's sort keys. An unrecognised key renders its
 * raw value rather than being dropped, so a sort added server-side appears in
 * the control immediately, just without a friendly name.
 */

const SORT_LABELS: Record<string, string> = {
  newest: 'Newest',
  oldest: 'Oldest',
  price_asc: 'Price: low to high',
  price_desc: 'Price: high to low',
  name_asc: 'Name: A to Z',
  name_desc: 'Name: Z to A',
};

export function CatalogSort({ sorts }: { sorts: string[] }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  if (sorts.length === 0) return null;

  return (
    <div className={cn('flex items-center gap-2', isPending && 'opacity-70')}>
      <label htmlFor="catalog-sort" className="whitespace-nowrap text-sm text-muted-foreground">
        Sort by
      </label>

      <select
        id="catalog-sort"
        value={searchParams.get('sort') ?? 'newest'}
        onChange={(event) => {
          const params = new URLSearchParams(searchParams.toString());

          params.set('sort', event.target.value);
          // Re-sorting reorders the whole result set, so page 3 of the old
          // order is meaningless in the new one.
          params.delete('page');

          startTransition(() => {
            router.push(`${pathname}?${params.toString()}`, { scroll: false });
          });
        }}
        className="rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        {sorts.map((sort) => (
          <option key={sort} value={sort}>
            {SORT_LABELS[sort] ?? sort}
          </option>
        ))}
      </select>
    </div>
  );
}
