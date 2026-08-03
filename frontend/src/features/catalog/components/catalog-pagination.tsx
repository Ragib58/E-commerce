'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useTransition } from 'react';
import { cn } from '@/lib/utils/cn';
import type { ApiPagination } from '@/lib/api/types';

/**
 * Page navigation for a catalog listing.
 *
 * Writes the page number into the URL rather than holding it in state, so a
 * shopper can link to page 3 and the back button returns to page 2.
 */

export function CatalogPagination({ pagination }: { pagination: ApiPagination }) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [isPending, startTransition] = useTransition();

  if (pagination.last_page <= 1) return null;

  function goToPage(page: number) {
    const params = new URLSearchParams(searchParams.toString());

    // Page 1 is the default; keeping it out of the URL avoids two distinct
    // addresses for the same content.
    if (page <= 1) {
      params.delete('page');
    } else {
      params.set('page', String(page));
    }

    startTransition(() => {
      router.push(`${pathname}?${params.toString()}`);
    });
  }

  const { current_page: current, last_page: last } = pagination;

  /**
   * A short window of page numbers around the current one.
   *
   * Rendering all of them would produce hundreds of links on a large catalog —
   * slow to render and useless to navigate.
   */
  const pages = buildPageWindow(current, last);

  return (
    <nav
      aria-label="Pagination"
      className={cn('mt-8 flex items-center justify-center gap-1', isPending && 'opacity-70')}
    >
      <button
        type="button"
        onClick={() => goToPage(current - 1)}
        disabled={current <= 1}
        className="rounded-md border border-border px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40 hover:enabled:bg-muted"
      >
        Previous
      </button>

      {pages.map((page, index) =>
        page === null ? (
          <span key={`gap-${index}`} aria-hidden className="px-2 text-muted-foreground">
            …
          </span>
        ) : (
          <button
            key={page}
            type="button"
            onClick={() => goToPage(page)}
            aria-current={page === current ? 'page' : undefined}
            aria-label={`Page ${page}`}
            className={cn(
              'min-w-[2.5rem] rounded-md border px-3 py-2 text-sm font-medium transition-colors',
              page === current
                ? 'border-primary bg-primary text-primary-foreground'
                : 'border-border hover:bg-muted',
            )}
          >
            {page}
          </button>
        ),
      )}

      <button
        type="button"
        onClick={() => goToPage(current + 1)}
        disabled={current >= last}
        className="rounded-md border border-border px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-40 hover:enabled:bg-muted"
      >
        Next
      </button>
    </nav>
  );
}

/**
 * Page numbers to render, with null marking an elided run.
 *
 * Always includes the first and last page so a shopper can jump to either end
 * without stepping through the middle.
 */
function buildPageWindow(current: number, last: number): Array<number | null> {
  if (last <= 7) {
    return Array.from({ length: last }, (_, index) => index + 1);
  }

  const pages: Array<number | null> = [1];

  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);

  if (start > 2) pages.push(null);

  for (let page = start; page <= end; page++) {
    pages.push(page);
  }

  if (end < last - 1) pages.push(null);

  pages.push(last);

  return pages;
}
