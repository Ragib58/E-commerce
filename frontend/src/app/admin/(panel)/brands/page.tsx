'use client';

import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import { createBrand, fetchAdminBrands, setBrandStatus } from '@/features/catalog/api/admin';
import {
  EmptyRow,
  ErrorNotice,
  StatusBadge,
  TableHead,
  TablePagination,
  TableShell,
} from '@/features/catalog/components/admin/data-table';
import { ApiError } from '@/lib/api/errors';

/**
 * Brand management.
 */
export default function AdminBrandsPage() {
  return (
    <AdminGuard requiredPermissions={['view_brands', 'manage_brands', 'view_products']}>
      <BrandTable />
    </AdminGuard>
  );
}

const COLUMNS = [
  { key: 'name', label: 'Brand' },
  { key: 'products', label: 'Products' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', className: 'text-right' },
];

function BrandTable() {
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [name, setName] = useState('');
  const [formError, setFormError] = useState<string | null>(null);

  const filters = useMemo(
    () => ({ search: search.trim() || undefined, page, per_page: 20 }),
    [search, page],
  );

  const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: queryKeys.catalog.brands.list(filters),
    queryFn: () => fetchAdminBrands(filters),
    placeholderData: (previous) => previous,
  });

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.catalog.brands.all });
  }

  const createMutation = useMutation({
    mutationFn: (payload: { name: string }) => createBrand(payload),
    onSuccess: () => {
      setName('');
      setFormError(null);
      invalidate();
    },
    onError: (mutationError) => {
      setFormError(
        mutationError instanceof ApiError ? mutationError.message : 'The brand could not be created.',
      );
    },
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => setBrandStatus(id, status),
    onSuccess: invalidate,
  });

  const brands = data?.items ?? [];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Brands</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manufacturers and labels products can be attributed to.
        </p>
      </header>

      <Can permission="manage_brands">
        <form
          onSubmit={(event) => {
            event.preventDefault();

            if (!name.trim()) return;

            createMutation.mutate({ name: name.trim() });
          }}
          className="flex flex-wrap items-end gap-3 rounded-lg border border-border p-4"
        >
          <div className="flex-1 sm:max-w-xs">
            <label htmlFor="brand-name" className="mb-1 block text-sm font-medium">
              New brand
            </label>
            <input
              id="brand-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="e.g. Northwind"
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            />
          </div>

          <button
            type="submit"
            disabled={createMutation.isPending || !name.trim()}
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {createMutation.isPending ? 'Creating…' : 'Add brand'}
          </button>
        </form>
      </Can>

      {formError ? <ErrorNotice message={formError} /> : null}

      <div className="sm:max-w-xs">
        <label htmlFor="brand-search" className="sr-only">
          Search brands
        </label>
        <input
          id="brand-search"
          type="search"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
          }}
          placeholder="Search brands…"
          className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        />
      </div>

      {isError ? (
        <ErrorNotice message={error instanceof Error ? error.message : 'Unable to load brands.'} />
      ) : (
        <>
          <TableShell isLoading={isFetching && !isPending}>
            <TableHead columns={COLUMNS} />

            <tbody className="divide-y divide-border">
              {isPending ? (
                <EmptyRow colSpan={COLUMNS.length} message="Loading brands…" />
              ) : brands.length === 0 ? (
                <EmptyRow
                  colSpan={COLUMNS.length}
                  message={search ? 'No brands match your search.' : 'No brands yet.'}
                />
              ) : (
                brands.map((brand) => (
                  <tr key={brand.id} className="hover:bg-muted/40">
                    <td className="px-4 py-3">
                      <span className="font-medium">{brand.name}</span>
                      <span className="ml-2 text-xs text-muted-foreground">/{brand.slug}</span>
                    </td>

                    <td className="px-4 py-3 tabular-nums text-muted-foreground">
                      {brand.products_count ?? 0}
                    </td>

                    <td className="px-4 py-3">
                      <StatusBadge status={brand.status} />
                    </td>

                    <td className="px-4 py-3 text-right">
                      <Can permission="manage_brands">
                        <button
                          type="button"
                          onClick={() =>
                            statusMutation.mutate({
                              id: brand.id,
                              status: brand.status === 'published' ? 'draft' : 'published',
                            })
                          }
                          disabled={statusMutation.isPending}
                          className="rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted"
                        >
                          {brand.status === 'published' ? 'Unpublish' : 'Publish'}
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
