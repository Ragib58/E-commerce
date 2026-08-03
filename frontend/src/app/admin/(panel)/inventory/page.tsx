'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { AdminGuard } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import {
  fetchInventoryAlerts,
  fetchInventoryMovements,
  fetchInventorySummary,
} from '@/features/catalog/api/admin';
import {
  EmptyRow,
  ErrorNotice,
  StockBadge,
  TableHead,
  TablePagination,
  TableShell,
} from '@/features/catalog/components/admin/data-table';
import { useStoreConfig } from '@/components/providers/store-config-provider';
import { formatMinorUnits } from '@/features/catalog/lib/format';
import { cn } from '@/lib/utils/cn';

/**
 * Inventory overview: headline figures, reorder alerts, and the movement
 * ledger.
 */
export default function AdminInventoryPage() {
  return (
    <AdminGuard requiredPermissions={['view_products']}>
      <InventoryDashboard />
    </AdminGuard>
  );
}

function InventoryDashboard() {
  const config = useStoreConfig();
  const [page, setPage] = useState(1);

  const summary = useQuery({
    queryKey: queryKeys.inventory.summary(),
    queryFn: fetchInventorySummary,
  });

  const alerts = useQuery({
    queryKey: queryKeys.inventory.alerts(),
    queryFn: fetchInventoryAlerts,
  });

  const movements = useQuery({
    queryKey: queryKeys.inventory.movements({ page }),
    queryFn: () => fetchInventoryMovements({ page, per_page: 20 }),
    placeholderData: (previous) => previous,
  });

  return (
    <div className="space-y-8">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Inventory</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Stock levels, reorder alerts, and the full movement history.
        </p>
      </header>

      <section aria-labelledby="inventory-summary">
        <h2 id="inventory-summary" className="sr-only">
          Summary
        </h2>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label="Tracked products"
            value={summary.data?.tracked_products}
            isLoading={summary.isPending}
          />
          <StatCard
            label="Low stock"
            value={summary.data?.low_stock}
            isLoading={summary.isPending}
            tone={summary.data && summary.data.low_stock > 0 ? 'warning' : 'neutral'}
          />
          <StatCard
            label="Out of stock"
            value={summary.data?.out_of_stock}
            isLoading={summary.isPending}
            tone={summary.data && summary.data.out_of_stock > 0 ? 'danger' : 'neutral'}
          />
          <StatCard
            label="Stock value (at cost)"
            value={
              summary.data ? formatMinorUnits(config, summary.data.stock_value) : undefined
            }
            isLoading={summary.isPending}
          />
        </div>
      </section>

      <section aria-labelledby="inventory-alerts" className="space-y-4">
        <h2 id="inventory-alerts" className="text-lg font-medium">
          Needs attention
        </h2>

        {alerts.isError ? (
          <ErrorNotice message="Unable to load inventory alerts." />
        ) : alerts.isPending ? (
          <p className="text-sm text-muted-foreground">Loading alerts…</p>
        ) : (
          <div className="grid gap-6 lg:grid-cols-2">
            <AlertList
              title="Low stock"
              emptyMessage="Nothing is running low."
              items={[
                ...alerts.data.lowStockProducts.map((product) => ({
                  key: `p-${product.id}`,
                  href: `/admin/products/${product.id}`,
                  name: product.name,
                  detail: product.sku,
                  stock: product.inventory.stock,
                  threshold: product.inventory.low_stock_threshold,
                })),
                // Variants are listed alongside products because a variable
                // product can sit well above its threshold in total while one
                // size is about to run out.
                ...alerts.data.lowStockVariants.map((variant) => ({
                  key: `v-${variant.id}`,
                  href: null,
                  name: variant.name ?? variant.sku,
                  detail: variant.sku,
                  stock: variant.inventory.stock,
                  threshold: variant.inventory.low_stock_threshold,
                })),
              ]}
            />

            <AlertList
              title="Out of stock"
              emptyMessage="Everything is in stock."
              items={alerts.data.outOfStockProducts.map((product) => ({
                key: product.id,
                href: `/admin/products/${product.id}`,
                name: product.name,
                detail: product.sku,
                stock: product.inventory.stock,
                threshold: product.inventory.low_stock_threshold,
              }))}
            />
          </div>
        )}
      </section>

      <section aria-labelledby="inventory-movements" className="space-y-4">
        <h2 id="inventory-movements" className="text-lg font-medium">
          Movement history
        </h2>

        {movements.isError ? (
          <ErrorNotice message="Unable to load inventory movements." />
        ) : (
          <>
            <TableShell isLoading={movements.isFetching && !movements.isPending}>
              <TableHead
                columns={[
                  { key: 'date', label: 'When' },
                  { key: 'product', label: 'Item' },
                  { key: 'change', label: 'Change' },
                  { key: 'result', label: 'Resulting stock' },
                  { key: 'reason', label: 'Reason' },
                  { key: 'by', label: 'Recorded by' },
                ]}
              />

              <tbody className="divide-y divide-border">
                {movements.isPending ? (
                  <EmptyRow colSpan={6} message="Loading movements…" />
                ) : (movements.data?.items.length ?? 0) === 0 ? (
                  <EmptyRow colSpan={6} message="No stock movements recorded yet." />
                ) : (
                  movements.data?.items.map((movement) => (
                    <tr key={movement.id} className="hover:bg-muted/40">
                      <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                        {movement.created_at
                          ? new Date(movement.created_at).toLocaleString()
                          : '—'}
                      </td>

                      <td className="px-4 py-3">
                        <span className="font-medium">
                          {movement.product?.name ?? 'Unknown product'}
                        </span>
                        {movement.variant ? (
                          <span className="ml-1 text-xs text-muted-foreground">
                            ({movement.variant.name ?? movement.variant.sku})
                          </span>
                        ) : null}
                      </td>

                      <td
                        className={cn(
                          'px-4 py-3 font-medium tabular-nums',
                          movement.quantity > 0 ? 'text-emerald-600' : 'text-destructive',
                        )}
                      >
                        {movement.quantity > 0 ? '+' : ''}
                        {movement.quantity}
                      </td>

                      <td className="px-4 py-3 tabular-nums text-muted-foreground">
                        {movement.quantity_before} → {movement.quantity_after}
                      </td>

                      <td className="px-4 py-3">{movement.reason_label}</td>

                      <td className="px-4 py-3 text-muted-foreground">
                        {/* Null for system-generated movements: an order
                            pipeline decrement has no admin behind it. */}
                        {movement.recorded_by?.name ?? 'System'}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </TableShell>

            <TablePagination
              pagination={movements.data?.pagination ?? null}
              onPageChange={setPage}
            />
          </>
        )}
      </section>
    </div>
  );
}

function StatCard({
  label,
  value,
  isLoading,
  tone = 'neutral',
}: {
  label: string;
  value: number | string | undefined;
  isLoading: boolean;
  tone?: 'neutral' | 'warning' | 'danger';
}) {
  return (
    <div className="rounded-lg border border-border p-4">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={cn(
          'mt-1 text-2xl font-semibold tabular-nums',
          tone === 'warning' && 'text-amber-600',
          tone === 'danger' && 'text-destructive',
        )}
      >
        {isLoading ? '—' : (value ?? 0)}
      </p>
    </div>
  );
}

function AlertList({
  title,
  items,
  emptyMessage,
}: {
  title: string;
  emptyMessage: string;
  items: Array<{
    key: string;
    href: string | null;
    name: string;
    detail: string;
    stock: number | null | undefined;
    threshold: number | null | undefined;
  }>;
}) {
  return (
    <div className="rounded-lg border border-border">
      <h3 className="border-b border-border px-4 py-3 text-sm font-medium">{title}</h3>

      {items.length === 0 ? (
        <p className="px-4 py-8 text-center text-sm text-muted-foreground">{emptyMessage}</p>
      ) : (
        <ul className="divide-y divide-border">
          {items.map((item) => (
            <li key={item.key} className="flex items-center gap-3 px-4 py-2.5 text-sm">
              <div className="min-w-0 flex-1">
                {item.href ? (
                  <Link href={item.href} className="font-medium hover:underline">
                    {item.name}
                  </Link>
                ) : (
                  <span className="font-medium">{item.name}</span>
                )}
                <p className="font-mono text-xs text-muted-foreground">{item.detail}</p>
              </div>

              <StockBadge stock={item.stock} threshold={item.threshold} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
