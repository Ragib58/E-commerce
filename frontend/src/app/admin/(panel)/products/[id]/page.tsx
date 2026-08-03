'use client';

import { use, useState } from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import {
  adjustStock,
  fetchAdminProduct,
  fetchStockHistory,
  updateProduct,
  uploadProductImage,
} from '@/features/catalog/api/admin';
import { ErrorNotice, StatusBadge, StockBadge } from '@/features/catalog/components/admin/data-table';
import { useStoreConfig } from '@/components/providers/store-config-provider';
import { formatMinorUnits, toMajorUnits, toMinorUnits } from '@/features/catalog/lib/format';
import { ApiError } from '@/lib/api/errors';
import { cn } from '@/lib/utils/cn';
import type { Product, ProductMedia } from '@/features/catalog/types';
import type { StoreConfig } from '@/features/settings/lib/store-config';

/**
 * A single product: details, gallery, variants, and stock.
 *
 * The stock panel deliberately does not edit a number in place — it posts an
 * adjustment with a reason, so every change reaches the ledger explained rather
 * than as an unattributed overwrite.
 */
export default function AdminProductPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);

  return (
    <AdminGuard requiredPermissions={['view_products']}>
      <ProductWorkspace productId={id} />
    </AdminGuard>
  );
}

function ProductWorkspace({ productId }: { productId: string }) {
  const config = useStoreConfig();
  const queryClient = useQueryClient();
  const [notice, setNotice] = useState<string | null>(null);

  const { data: product, isPending, isError, error } = useQuery({
    queryKey: queryKeys.catalog.products.detail(productId),
    queryFn: () => fetchAdminProduct(productId),
  });

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.catalog.products.all });
    void queryClient.invalidateQueries({ queryKey: queryKeys.inventory.all });
  }

  const detailsMutation = useMutation({
    mutationFn: (body: Record<string, unknown>) => updateProduct(productId, body),
    onSuccess: () => {
      setNotice('Product saved.');
      invalidate();
    },
    onError: (mutationError) => {
      setNotice(
        mutationError instanceof ApiError ? mutationError.message : 'The product could not be saved.',
      );
    },
  });

  if (isError) {
    return (
      <ErrorNotice message={error instanceof Error ? error.message : 'Unable to load the product.'} />
    );
  }

  if (isPending) {
    return <p className="text-sm text-muted-foreground">Loading product…</p>;
  }

  return (
    <div className="space-y-8">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <nav className="mb-1 text-sm text-muted-foreground">
            <Link href="/admin/products" className="hover:text-foreground">
              Products
            </Link>
            <span className="mx-2">/</span>
            <span className="text-foreground">{product.name}</span>
          </nav>

          <h1 className="text-xl font-semibold tracking-tight">{product.name}</h1>

          <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <StatusBadge status={product.status} />
            <span className="font-mono text-xs">{product.sku}</span>
            <span className="capitalize">{product.type}</span>
          </div>
        </div>

        <Link
          href={`/products/${product.slug}`}
          target="_blank"
          rel="noopener noreferrer"
          className="rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
        >
          View on storefront
        </Link>
      </header>

      {notice ? (
        <div role="status" className="rounded-md border border-border bg-muted/50 px-4 py-3 text-sm">
          {notice}
        </div>
      ) : null}

      <div className="grid gap-8 lg:grid-cols-3">
        <div className="space-y-8 lg:col-span-2">
          <DetailsPanel
            product={product}
            onSave={(body) => detailsMutation.mutate(body)}
            isSaving={detailsMutation.isPending}
          />

          <VariantsPanel product={product} config={config} />

          <MediaPanel
            productId={productId}
            media={product.media ?? []}
            onUploaded={() => {
              setNotice('Image uploaded.');
              invalidate();
              void queryClient.invalidateQueries({
                queryKey: queryKeys.catalog.products.detail(productId),
              });
            }}
          />
        </div>

        <div className="space-y-8">
          <StockPanel product={product} onAdjusted={invalidate} />
          <StockHistoryPanel productId={productId} />
        </div>
      </div>
    </div>
  );
}

/**
 * Core product fields.
 *
 * Prices are shown and entered in major units — an operator thinks in dollars,
 * not cents — and converted at the boundary by `toMinorUnits`.
 */
function DetailsPanel({
  product,
  onSave,
  isSaving,
}: {
  product: Product;
  onSave: (body: Record<string, unknown>) => void;
  isSaving: boolean;
}) {
  const config = useStoreConfig();
  const [name, setName] = useState(product.name);
  const [shortDescription, setShortDescription] = useState(product.short_description ?? '');
  const [price, setPrice] = useState(String(toMajorUnits(product.pricing.price)));
  const [discount, setDiscount] = useState(
    product.pricing.discount_price ? String(toMajorUnits(product.pricing.discount_price)) : '',
  );
  const [status, setStatus] = useState(product.status);

  return (
    <section className="rounded-lg border border-border p-5">
      <h2 className="mb-4 text-lg font-medium">Details</h2>

      <form
        onSubmit={(event) => {
          event.preventDefault();

          onSave({
            name,
            short_description: shortDescription || null,
            price: toMinorUnits(price),
            // An empty field clears the discount, which the API distinguishes
            // from an absent one.
            discount_price: discount ? toMinorUnits(discount) : null,
            status,
          });
        }}
        className="grid gap-4 sm:grid-cols-2"
      >
        <Field label="Name" htmlFor="product-name" className="sm:col-span-2">
          <input
            id="product-name"
            value={name}
            onChange={(event) => setName(event.target.value)}
            required
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          />
        </Field>

        <Field label="Short description" htmlFor="product-short" className="sm:col-span-2">
          <textarea
            id="product-short"
            value={shortDescription}
            onChange={(event) => setShortDescription(event.target.value)}
            rows={2}
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          />
        </Field>

        <Field label={`Price (${config.business.currency})`} htmlFor="product-price">
          <input
            id="product-price"
            type="number"
            step="0.01"
            min="0"
            value={price}
            onChange={(event) => setPrice(event.target.value)}
            required
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          />
        </Field>

        <Field label="Discount price" htmlFor="product-discount">
          <input
            id="product-discount"
            type="number"
            step="0.01"
            min="0"
            value={discount}
            onChange={(event) => setDiscount(event.target.value)}
            placeholder="No discount"
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          />
        </Field>

        <Field label="Status" htmlFor="product-status">
          <select
            id="product-status"
            value={status}
            onChange={(event) =>
              setStatus(event.target.value as typeof status)
            }
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          >
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="archived">Archived</option>
          </select>
        </Field>

        <div className="sm:col-span-2">
          <Can permission="update_products">
            <button
              type="submit"
              disabled={isSaving}
              className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
            >
              {isSaving ? 'Saving…' : 'Save changes'}
            </button>
          </Can>
        </div>
      </form>
    </section>
  );
}

function VariantsPanel({
  product,
  config,
}: {
  product: Product;
  config: StoreConfig;
}) {
  // The admin payload includes inactive variants so one can be re-enabled.
  const variants = product.all_variants ?? product.variants ?? [];

  if (product.type !== 'variable') {
    return null;
  }

  return (
    <section className="rounded-lg border border-border p-5">
      <h2 className="mb-4 text-lg font-medium">
        Variants <span className="text-sm font-normal text-muted-foreground">({variants.length})</span>
      </h2>

      {variants.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          This variable product has no variants yet, so nothing can be bought. Generate the option
          matrix to create them.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b border-border text-left text-muted-foreground">
              <tr>
                <th scope="col" className="pb-2 font-medium">Variant</th>
                <th scope="col" className="pb-2 font-medium">SKU</th>
                <th scope="col" className="pb-2 font-medium">Price</th>
                <th scope="col" className="pb-2 font-medium">Stock</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {variants.map((variant) => (
                <tr key={variant.id} className={cn(variant.is_active === false && 'opacity-50')}>
                  <td className="py-2">
                    {variant.name ?? variant.sku}
                    {variant.is_default ? (
                      <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs">Default</span>
                    ) : null}
                    {variant.is_active === false ? (
                      <span className="ml-2 text-xs text-muted-foreground">Inactive</span>
                    ) : null}
                  </td>
                  <td className="py-2 font-mono text-xs text-muted-foreground">{variant.sku}</td>
                  <td className="py-2 tabular-nums">
                    {formatMinorUnits(config, variant.pricing.effective_price)}
                    {variant.pricing.own_price === null ? (
                      <span className="ml-1 text-xs text-muted-foreground">(inherited)</span>
                    ) : null}
                  </td>
                  <td className="py-2">
                    <StockBadge
                      stock={variant.inventory.stock}
                      threshold={variant.inventory.low_stock_threshold}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function MediaPanel({
  productId,
  media,
  onUploaded,
}: {
  productId: string;
  media: ProductMedia[];
  onUploaded: () => void;
}) {
  const [isUploading, setIsUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  async function handleUpload(file: File) {
    setIsUploading(true);
    setUploadError(null);

    try {
      await uploadProductImage(productId, file);
      onUploaded();
    } catch (error) {
      setUploadError(error instanceof ApiError ? error.message : 'The image could not be uploaded.');
    } finally {
      setIsUploading(false);
    }
  }

  return (
    <section className="rounded-lg border border-border p-5">
      <h2 className="mb-4 text-lg font-medium">Gallery</h2>

      <Can permission="update_products">
        <div className="mb-4">
          <label
            htmlFor="product-image"
            className="inline-block cursor-pointer rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"
          >
            {isUploading ? 'Uploading…' : 'Upload image'}
          </label>
          <input
            id="product-image"
            type="file"
            accept="image/*"
            className="sr-only"
            disabled={isUploading}
            onChange={(event) => {
              const file = event.target.files?.[0];

              if (file) void handleUpload(file);

              // Reset so selecting the same file again still fires a change.
              event.target.value = '';
            }}
          />
        </div>
      </Can>

      {uploadError ? <ErrorNotice message={uploadError} /> : null}

      {media.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          No images yet. The first image uploaded becomes the thumbnail.
        </p>
      ) : (
        <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4">
          {media.map((item) => (
            <li key={item.id} className="relative">
              {/* A plain img: these are admin thumbnails behind auth, so
                  next/image optimisation would add a round trip for no gain. */}
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={item.url ?? ''}
                alt={item.alt_text ?? ''}
                className="aspect-square w-full rounded-md border border-border object-cover"
              />
              {item.is_thumbnail ? (
                <span className="absolute left-1 top-1 rounded bg-primary px-1.5 py-0.5 text-xs font-medium text-primary-foreground">
                  Thumbnail
                </span>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/**
 * Stock adjustment.
 *
 * Offers both modes explicitly. Conflating them is how stock takes go wrong: an
 * operator who counts 40 and submits it as a delta *adds* 40 to a figure they
 * had just proved wrong.
 */
function StockPanel({
  product,
  onAdjusted,
}: {
  product: Product;
  onAdjusted: () => void;
}) {
  const queryClient = useQueryClient();
  const [mode, setMode] = useState<'delta' | 'absolute'>('delta');
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState('restock');
  const [variantId, setVariantId] = useState('');
  const [note, setNote] = useState('');
  const [feedback, setFeedback] = useState<string | null>(null);

  const isVariable = product.type === 'variable';
  const variants = product.all_variants ?? product.variants ?? [];

  const mutation = useMutation({
    mutationFn: () =>
      adjustStock(product.id, {
        mode,
        quantity: Number(quantity),
        reason,
        variantId: isVariable ? variantId || null : null,
        note: note || undefined,
      }),
    onSuccess: (result) => {
      setFeedback(`Stock is now ${result.stock}.`);
      setQuantity('');
      setNote('');
      onAdjusted();
      void queryClient.invalidateQueries({
        queryKey: queryKeys.catalog.products.detail(product.id),
      });
      void queryClient.invalidateQueries({
        queryKey: queryKeys.catalog.products.stockHistory(product.id),
      });
    },
    onError: (error) => {
      setFeedback(error instanceof ApiError ? error.message : 'The adjustment failed.');
    },
  });

  if (product.type === 'digital') {
    return (
      <section className="rounded-lg border border-border p-5">
        <h2 className="mb-2 text-lg font-medium">Stock</h2>
        <p className="text-sm text-muted-foreground">
          Digital products have unlimited inventory and are never out of stock.
        </p>
      </section>
    );
  }

  return (
    <section className="rounded-lg border border-border p-5">
      <h2 className="mb-1 text-lg font-medium">Stock</h2>
      <p className="mb-4 text-2xl font-semibold tabular-nums">
        <StockBadge
          stock={product.inventory.stock}
          threshold={product.inventory.low_stock_threshold}
        />
      </p>

      <Can permission="update_products">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            setFeedback(null);
            mutation.mutate();
          }}
          className="space-y-3"
        >
          {isVariable ? (
            <Field label="Variant" htmlFor="stock-variant">
              <select
                id="stock-variant"
                value={variantId}
                onChange={(event) => setVariantId(event.target.value)}
                required
                className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
              >
                <option value="">Select a variant…</option>
                {variants.map((variant) => (
                  <option key={variant.id} value={variant.id}>
                    {variant.name ?? variant.sku} ({variant.inventory.stock ?? 0})
                  </option>
                ))}
              </select>
            </Field>
          ) : null}

          <Field label="Adjustment type" htmlFor="stock-mode">
            <select
              id="stock-mode"
              value={mode}
              onChange={(event) => setMode(event.target.value as 'delta' | 'absolute')}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            >
              <option value="delta">Add or remove (+/−)</option>
              <option value="absolute">Set counted total</option>
            </select>
          </Field>

          <Field
            label={mode === 'delta' ? 'Quantity (negative to remove)' : 'Counted quantity'}
            htmlFor="stock-quantity"
          >
            <input
              id="stock-quantity"
              type="number"
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
              min={mode === 'absolute' ? 0 : undefined}
              required
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            />
          </Field>

          <Field label="Reason" htmlFor="stock-reason">
            <select
              id="stock-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            >
              {/* Sales and returns are absent by design: those are written by
                  the order pipeline, and a manual entry masquerading as one
                  would corrupt reconciliation against actual orders. */}
              <option value="restock">Restock</option>
              <option value="damage">Damage</option>
              <option value="theft">Theft or loss</option>
              <option value="correction">Stock take correction</option>
              <option value="transfer">Transfer</option>
              <option value="manual_edit">Manual edit</option>
            </select>
          </Field>

          <Field label="Note (optional)" htmlFor="stock-note">
            <input
              id="stock-note"
              value={note}
              onChange={(event) => setNote(event.target.value)}
              placeholder="Supplier invoice, stock take reference…"
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            />
          </Field>

          <button
            type="submit"
            disabled={mutation.isPending || !quantity}
            className="w-full rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {mutation.isPending ? 'Recording…' : 'Record adjustment'}
          </button>

          {feedback ? (
            <p role="status" className="text-sm text-muted-foreground">
              {feedback}
            </p>
          ) : null}
        </form>
      </Can>
    </section>
  );
}

function StockHistoryPanel({ productId }: { productId: string }) {
  const { data, isPending } = useQuery({
    queryKey: queryKeys.catalog.products.stockHistory(productId),
    queryFn: () => fetchStockHistory(productId, { per_page: 10 }),
  });

  return (
    <section className="rounded-lg border border-border p-5">
      <h2 className="mb-4 text-lg font-medium">Recent movements</h2>

      {isPending ? (
        <p className="text-sm text-muted-foreground">Loading history…</p>
      ) : (data?.items.length ?? 0) === 0 ? (
        <p className="text-sm text-muted-foreground">No movements recorded yet.</p>
      ) : (
        <ul className="space-y-3 text-sm">
          {data?.items.map((movement) => (
            <li key={movement.id} className="border-b border-border pb-2 last:border-0">
              <div className="flex items-baseline justify-between gap-2">
                <span
                  className={cn(
                    'font-medium tabular-nums',
                    movement.quantity > 0 ? 'text-emerald-600' : 'text-destructive',
                  )}
                >
                  {movement.quantity > 0 ? '+' : ''}
                  {movement.quantity}
                </span>
                <span className="text-xs text-muted-foreground">
                  {movement.quantity_before} → {movement.quantity_after}
                </span>
              </div>

              <p className="text-xs text-muted-foreground">
                {movement.reason_label}
                {movement.recorded_by ? ` · ${movement.recorded_by.name}` : ' · System'}
                {movement.created_at
                  ? ` · ${new Date(movement.created_at).toLocaleDateString()}`
                  : ''}
              </p>

              {movement.note ? <p className="mt-1 text-xs">{movement.note}</p> : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Field({
  label,
  htmlFor,
  children,
  className,
}: {
  label: string;
  htmlFor: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={className}>
      <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium">
        {label}
      </label>
      {children}
    </div>
  );
}
