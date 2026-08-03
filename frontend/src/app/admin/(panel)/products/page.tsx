'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import {
  bulkProductAction,
  fetchAdminProducts,
  setProductStatus,
} from '@/features/catalog/api/admin';
import {
  EmptyRow,
  ErrorNotice,
  StatusBadge,
  StockBadge,
  TableHead,
  TablePagination,
  TableShell,
} from '@/features/catalog/components/admin/data-table';
import { useStoreConfig } from '@/components/providers/store-config-provider';
import { formatMinorUnits } from '@/features/catalog/lib/format';

/**
 * The product catalog table.
 *
 * Wrapped in a second AdminGuard with explicit permissions: the panel layout
 * only proves the visitor is an authenticated admin, whereas this page also
 * requires the right to read products. Someone typing the URL directly gets the
 * access-denied notice rather than an empty table and a 403 in the console.
 */
export default function AdminProductsPage() {
  return (
    <AdminGuard requiredPermissions={['view_products']}>
      <ProductTable />
    </AdminGuard>
  );
}

const COLUMNS = [
  { key: 'select', label: '', className: 'w-10' },
  { key: 'name', label: 'Product' },
  { key: 'sku', label: 'SKU' },
  { key: 'price', label: 'Price' },
  { key: 'stock', label: 'Stock' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', className: 'text-right' },
];

function ProductTable() {
  const queryClient = useQueryClient();
  const config = useStoreConfig();

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [stockFilter, setStockFilter] = useState('');
  const [page, setPage] = useState(1);

  // Keyed by the product's public uuid, which is the only identifier the API
  // exposes — the integer primary key is deliberately never published.
  const [selected, setSelected] = useState<Set<string>>(new Set());

  const filters = useMemo(
    () => ({
      search: search.trim() || undefined,
      status: status || undefined,
      low_stock: stockFilter === 'low' ? 1 : undefined,
      out_of_stock: stockFilter === 'out' ? 1 : undefined,
      page,
      per_page: 20,
    }),
    [search, status, stockFilter, page],
  );

  const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: queryKeys.catalog.products.list(filters),
    queryFn: () => fetchAdminProducts(filters),
    // Keeps the previous page rendered while the next one loads, so the table
    // does not collapse to an empty state on every filter change.
    placeholderData: (previous) => previous,
  });

  /**
   * Invalidate every product list after a write.
   *
   * Keyed on the `products.all` prefix rather than the current filters: a
   * publish changes which rows appear under *any* status filter, so refreshing
   * only the active query would leave the others stale.
   */
  function invalidateProducts() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.catalog.products.all });
    setSelected(new Set());
  }

  const statusMutation = useMutation({
    mutationFn: ({ id, next }: { id: string; next: string }) => setProductStatus(id, next),
    onSuccess: invalidateProducts,
  });

  const bulkMutation = useMutation({
    mutationFn: ({ ids, action }: { ids: string[]; action: 'publish' | 'draft' | 'archive' | 'delete' }) =>
      bulkProductAction(ids, action),
    onSuccess: invalidateProducts,
  });

  const products = data?.items ?? [];

  function toggleSelection(id: string) {
    setSelected((current) => {
      const next = new Set(current);

      if (next.has(id)) next.delete(id);
      else next.add(id);

      return next;
    });
  }

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Products</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {data?.pagination
              ? `${data.pagination.total} product${data.pagination.total === 1 ? '' : 's'} in the catalog.`
              : 'Manage your catalog.'}
          </p>
        </div>

        <Can permission="create_products">
          <Link
            href="/admin/products/new"
            className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
          >
            New product
          </Link>
        </Can>
      </header>

      <div className="flex flex-wrap gap-3">
        <div className="flex-1 sm:max-w-xs">
          <label htmlFor="product-search" className="sr-only">
            Search products
          </label>
          <input
            id="product-search"
            type="search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value);
              // A narrower result set rarely has the page the operator is on.
              setPage(1);
            }}
            placeholder="Search by name or SKU…"
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          />
        </div>

        <select
          aria-label="Filter by status"
          value={status}
          onChange={(event) => {
            setStatus(event.target.value);
            setPage(1);
          }}
          className="rounded-md border border-border bg-background px-3 py-2 text-sm"
        >
          <option value="">All statuses</option>
          <option value="published">Published</option>
          <option value="draft">Draft</option>
          <option value="archived">Archived</option>
        </select>

        <select
          aria-label="Filter by stock"
          value={stockFilter}
          onChange={(event) => {
            setStockFilter(event.target.value);
            setPage(1);
          }}
          className="rounded-md border border-border bg-background px-3 py-2 text-sm"
        >
          <option value="">All stock levels</option>
          <option value="low">Low stock</option>
          <option value="out">Out of stock</option>
        </select>
      </div>

      {selected.size > 0 ? (
        <div className="flex flex-wrap items-center gap-3 rounded-md border border-border bg-muted/50 px-4 py-3 text-sm">
          <span className="font-medium">{selected.size} selected</span>

          <Can permission="update_products">
            <button
              type="button"
              onClick={() => bulkMutation.mutate({ ids: [...selected], action: 'publish' })}
              disabled={bulkMutation.isPending}
              className="rounded-md border border-border bg-background px-3 py-1.5 font-medium hover:bg-muted"
            >
              Publish
            </button>
            <button
              type="button"
              onClick={() => bulkMutation.mutate({ ids: [...selected], action: 'draft' })}
              disabled={bulkMutation.isPending}
              className="rounded-md border border-border bg-background px-3 py-1.5 font-medium hover:bg-muted"
            >
              Move to draft
            </button>
          </Can>

          <Can permission="delete_products">
            <button
              type="button"
              onClick={() => {
                // Bulk deletion is irreversible from this screen, so it is the
                // one action that asks first.
                if (window.confirm(`Delete ${selected.size} product(s)? This can be undone from the trash.`)) {
                  bulkMutation.mutate({ ids: [...selected], action: 'delete' });
                }
              }}
              disabled={bulkMutation.isPending}
              className="rounded-md border border-destructive/40 px-3 py-1.5 font-medium text-destructive hover:bg-destructive/10"
            >
              Delete
            </button>
          </Can>

          <button
            type="button"
            onClick={() => setSelected(new Set())}
            className="ml-auto text-muted-foreground underline-offset-4 hover:underline"
          >
            Clear selection
          </button>
        </div>
      ) : null}

      {isError ? (
        <ErrorNotice message={error instanceof Error ? error.message : 'Unable to load products.'} />
      ) : (
        <>
          <TableShell isLoading={isFetching && !isPending}>
            <TableHead
              columns={COLUMNS.map((column) =>
                column.key === 'select'
                  ? { ...column, label: '' }
                  : column,
              )}
            />

            <tbody className="divide-y divide-border">
              {isPending ? (
                <EmptyRow colSpan={COLUMNS.length} message="Loading products…" />
              ) : products.length === 0 ? (
                <EmptyRow
                  colSpan={COLUMNS.length}
                  message={
                    search || status || stockFilter
                      ? 'No products match these filters.'
                      : 'No products yet. Create your first one to get started.'
                  }
                />
              ) : (
                products.map((product) => (
                  <tr key={product.id} className="hover:bg-muted/40">
                    <td className="px-4 py-3">
                      <label className="sr-only" htmlFor={`select-${product.id}`}>
                        Select {product.name}
                      </label>
                      <input
                        id={`select-${product.id}`}
                        type="checkbox"
                        checked={selected.has(product.id)}
                        onChange={() => toggleSelection(product.id)}
                        className="rounded border-border"
                      />
                    </td>

                    <td className="px-4 py-3">
                      <Link
                        href={`/admin/products/${product.id}`}
                        className="font-medium hover:underline"
                      >
                        {product.name}
                      </Link>
                      <p className="text-xs text-muted-foreground">
                        {product.category?.name ?? 'Uncategorised'}
                        {product.brand ? ` · ${product.brand.name}` : ''}
                        {product.type !== 'simple' ? ` · ${product.type}` : ''}
                      </p>
                    </td>

                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                      {product.sku}
                    </td>

                    <td className="px-4 py-3 tabular-nums">
                      {formatMinorUnits(config, product.pricing.effective_price)}
                      {product.pricing.discount_price ? (
                        <span className="ml-1 text-xs text-muted-foreground line-through">
                          {formatMinorUnits(config, product.pricing.price)}
                        </span>
                      ) : null}
                    </td>

                    <td className="px-4 py-3">
                      {product.type === 'digital' ? (
                        <span className="text-xs text-muted-foreground">Unlimited</span>
                      ) : (
                        <StockBadge
                          stock={product.inventory.stock}
                          threshold={product.inventory.low_stock_threshold}
                        />
                      )}
                    </td>

                    <td className="px-4 py-3">
                      <StatusBadge status={product.status} />
                    </td>

                    <td className="px-4 py-3 text-right">
                      <Can permission="update_products">
                        <button
                          type="button"
                          onClick={() =>
                            statusMutation.mutate({
                              id: product.id,
                              next: product.status === 'published' ? 'draft' : 'published',
                            })
                          }
                          disabled={statusMutation.isPending}
                          className="rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted"
                        >
                          {product.status === 'published' ? 'Unpublish' : 'Publish'}
                        </button>
                      </Can>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </TableShell>

          <TablePagination pagination={data?.pagination ?? null} onPageChange={setPage} />
        </>
      )}
    </div>
  );
}
