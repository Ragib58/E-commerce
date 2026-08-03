'use client';

import type { ReactNode } from 'react';
import { cn } from '@/lib/utils/cn';
import type { ApiPagination } from '@/lib/api/types';

/**
 * Presentational primitives shared by the admin catalog tables.
 *
 * Deliberately not a generic table abstraction: the products, categories, and
 * brands tables have genuinely different columns and actions, and a
 * configuration-driven table would end up more complex than the three it
 * replaced. These are the pieces they actually share — chrome, states, and
 * pagination.
 */

export function TableShell({
  children,
  isLoading = false,
}: {
  children: ReactNode;
  isLoading?: boolean;
}) {
  return (
    <div
      className={cn(
        'overflow-x-auto rounded-lg border border-border transition-opacity',
        // Dimmed rather than replaced: keeping the previous rows visible while
        // refetching avoids the layout collapsing on every keystroke of a
        // search.
        isLoading && 'opacity-60',
      )}
    >
      <table className="w-full text-sm">{children}</table>
    </div>
  );
}

export function TableHead({ columns }: { columns: Array<{ key: string; label: string; className?: string }> }) {
  return (
    <thead className="border-b border-border bg-muted/50 text-left">
      <tr>
        {columns.map((column) => (
          <th
            key={column.key}
            scope="col"
            className={cn('px-4 py-3 font-medium text-muted-foreground', column.className)}
          >
            {column.label}
          </th>
        ))}
      </tr>
    </thead>
  );
}

export function EmptyRow({ colSpan, message }: { colSpan: number; message: string }) {
  return (
    <tr>
      <td colSpan={colSpan} className="px-4 py-12 text-center text-muted-foreground">
        {message}
      </td>
    </tr>
  );
}

export function ErrorNotice({ message }: { message: string }) {
  return (
    <div role="alert" className="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
      <p className="text-sm font-medium text-destructive">Something went wrong</p>
      <p className="mt-1 text-sm text-muted-foreground">{message}</p>
    </div>
  );
}

/**
 * A coloured pill for a catalog status.
 */
export function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    published: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    archived: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  };

  return (
    <span
      className={cn(
        'inline-flex rounded-full px-2 py-0.5 text-xs font-medium capitalize',
        styles[status] ?? styles.draft,
      )}
    >
      {status}
    </span>
  );
}

/**
 * Stock level with a severity colour.
 *
 * Out of stock and low stock are visually distinct because they demand
 * different responses — one is lost sales now, the other is a reorder soon.
 */
export function StockBadge({
  stock,
  threshold = 5,
}: {
  stock: number | null | undefined;
  threshold?: number | null;
}) {
  if (stock === null || stock === undefined) {
    return <span className="text-muted-foreground">—</span>;
  }

  const limit = threshold ?? 5;

  return (
    <span
      className={cn(
        'font-medium tabular-nums',
        stock <= 0
          ? 'text-destructive'
          : stock <= limit
            ? 'text-amber-600'
            : 'text-foreground',
      )}
    >
      {stock}
    </span>
  );
}

/**
 * Pagination for an admin table.
 *
 * Simpler than the storefront's: an operator scanning a table wants a position
 * and next/previous, not a deep-linkable window of page numbers.
 */
export function TablePagination({
  pagination,
  onPageChange,
}: {
  pagination: ApiPagination | null;
  onPageChange: (page: number) => void;
}) {
  if (!pagination || pagination.last_page <= 1) return null;

  return (
    <div className="flex items-center justify-between gap-4 pt-4 text-sm">
      <p className="text-muted-foreground">
        Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total}
      </p>

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => onPageChange(pagination.current_page - 1)}
          disabled={pagination.current_page <= 1}
          className="rounded-md border border-border px-3 py-1.5 font-medium disabled:cursor-not-allowed disabled:opacity-40 hover:enabled:bg-muted"
        >
          Previous
        </button>

        <span className="text-muted-foreground">
          Page {pagination.current_page} of {pagination.last_page}
        </span>

        <button
          type="button"
          onClick={() => onPageChange(pagination.current_page + 1)}
          disabled={pagination.current_page >= pagination.last_page}
          className="rounded-md border border-border px-3 py-1.5 font-medium disabled:cursor-not-allowed disabled:opacity-40 hover:enabled:bg-muted"
        >
          Next
        </button>
      </div>
    </div>
  );
}
