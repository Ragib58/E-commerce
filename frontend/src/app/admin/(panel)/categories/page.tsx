'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import {
  createCategory,
  deleteCategory,
  fetchCategoryTree,
  setCategoryStatus,
} from '@/features/catalog/api/admin';
import { ErrorNotice, StatusBadge } from '@/features/catalog/components/admin/data-table';
import { ApiError } from '@/lib/api/errors';
import type { Category } from '@/features/catalog/types';
import { cn } from '@/lib/utils/cn';

/**
 * Category management.
 *
 * Rendered as a tree rather than a flat table: nesting is the whole point of
 * this taxonomy, and a paginated list would hide the parent-child relationships
 * an operator is actually reasoning about.
 */
export default function AdminCategoriesPage() {
  return (
    <AdminGuard requiredPermissions={['view_categories', 'manage_categories', 'view_products']}>
      <CategoryManager />
    </AdminGuard>
  );
}

function CategoryManager() {
  const queryClient = useQueryClient();
  const [parentId, setParentId] = useState<number | null>(null);
  const [name, setName] = useState('');
  const [formError, setFormError] = useState<string | null>(null);

  const { data, isPending, isError, error } = useQuery({
    queryKey: queryKeys.catalog.categories.tree(),
    queryFn: fetchCategoryTree,
  });

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.catalog.categories.all });
  }

  const createMutation = useMutation({
    mutationFn: (payload: { name: string; parent_id: number | null }) => createCategory(payload),
    onSuccess: () => {
      setName('');
      setParentId(null);
      setFormError(null);
      invalidate();
    },
    onError: (mutationError) => {
      // Surface the API's own message: it explains *why* (a duplicate slug, an
      // invalid parent) far better than a generic failure notice.
      setFormError(
        mutationError instanceof ApiError
          ? mutationError.message
          : 'The category could not be created.',
      );
    },
  });

  const statusMutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => setCategoryStatus(id, status),
    onSuccess: invalidate,
  });

  const deleteMutation = useMutation({
    mutationFn: ({ id, cascade }: { id: number; cascade: boolean }) => deleteCategory(id, cascade),
    onSuccess: invalidate,
  });

  /**
   * Delete a category, escalating to a cascade only if the API objects.
   *
   * The first attempt deliberately omits the cascade: the API refuses a
   * non-empty category, and that refusal is what surfaces the consequence to
   * the operator. Asking "are you sure?" up front for every delete would train
   * them to dismiss the prompt without reading it.
   */
  async function handleDelete(category: Category) {
    setFormError(null);

    try {
      await deleteMutation.mutateAsync({ id: category.id, cascade: false });
    } catch (deleteError) {
      // A 422 means the category is not empty — the one case worth confirming.
      if (deleteError instanceof ApiError && deleteError.isValidationError) {
        const confirmed = window.confirm(
          `“${category.name}” still contains subcategories or products. Delete it anyway? ` +
            'Subcategories move up one level and products become uncategorised.',
        );

        if (confirmed) {
          try {
            await deleteMutation.mutateAsync({ id: category.id, cascade: true });
          } catch {
            setFormError('The category could not be deleted.');
          }
        }

        return;
      }

      setFormError(
        deleteError instanceof ApiError
          ? deleteError.message
          : 'The category could not be deleted.',
      );
    }
  }

  const categories = data ?? [];

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold tracking-tight">Categories</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Organise the catalog. Categories nest to any depth.
        </p>
      </header>

      <Can permission="manage_categories">
        <form
          onSubmit={(event) => {
            event.preventDefault();

            if (!name.trim()) return;

            createMutation.mutate({ name: name.trim(), parent_id: parentId });
          }}
          className="flex flex-wrap items-end gap-3 rounded-lg border border-border p-4"
        >
          <div className="flex-1 sm:max-w-xs">
            <label htmlFor="category-name" className="mb-1 block text-sm font-medium">
              New category
            </label>
            <input
              id="category-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="e.g. Outerwear"
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            />
          </div>

          <div className="flex-1 sm:max-w-xs">
            <label htmlFor="category-parent" className="mb-1 block text-sm font-medium">
              Parent
            </label>
            <select
              id="category-parent"
              value={parentId ?? ''}
              onChange={(event) => setParentId(event.target.value ? Number(event.target.value) : null)}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            >
              <option value="">No parent (top level)</option>
              {flatten(categories).map((category) => (
                <option key={category.id} value={category.id}>
                  {'— '.repeat(category.depth)}
                  {category.name}
                </option>
              ))}
            </select>
          </div>

          <button
            type="submit"
            disabled={createMutation.isPending || !name.trim()}
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 disabled:opacity-50"
          >
            {createMutation.isPending ? 'Creating…' : 'Add category'}
          </button>
        </form>
      </Can>

      {formError ? <ErrorNotice message={formError} /> : null}

      {isError ? (
        <ErrorNotice
          message={error instanceof Error ? error.message : 'Unable to load categories.'}
        />
      ) : isPending ? (
        <p className="text-sm text-muted-foreground">Loading categories…</p>
      ) : categories.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-12 text-center">
          <p className="text-sm text-muted-foreground">
            No categories yet. Add one above to start organising the catalog.
          </p>
        </div>
      ) : (
        <ul className="divide-y divide-border rounded-lg border border-border">
          {flatten(categories).map((category) => (
            <li
              key={category.id}
              className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/40"
            >
              <div
                className="min-w-0 flex-1"
                // Indentation is the only cue for depth in a flattened tree.
                style={{ paddingLeft: `${category.depth * 20}px` }}
              >
                <span className={cn('font-medium', category.depth > 0 && 'text-sm')}>
                  {category.name}
                </span>
                <span className="ml-2 text-xs text-muted-foreground">/{category.slug}</span>
                {typeof category.products_count === 'number' ? (
                  <span className="ml-2 text-xs text-muted-foreground">
                    {category.products_count} product{category.products_count === 1 ? '' : 's'}
                  </span>
                ) : null}
              </div>

              <StatusBadge status={category.status} />

              <Can permission="manage_categories">
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={() =>
                      statusMutation.mutate({
                        id: category.id,
                        status: category.status === 'published' ? 'draft' : 'published',
                      })
                    }
                    disabled={statusMutation.isPending}
                    className="rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted"
                  >
                    {category.status === 'published' ? 'Unpublish' : 'Publish'}
                  </button>

                  <button
                    type="button"
                    onClick={() => void handleDelete(category)}
                    disabled={deleteMutation.isPending}
                    className="rounded-md border border-destructive/40 px-2 py-1 text-xs font-medium text-destructive hover:bg-destructive/10"
                  >
                    Delete
                  </button>
                </div>
              </Can>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Depth-first flattening of the tree into renderable rows.
 *
 * Recursive because nesting is unbounded — iterating `children` once would
 * silently drop everything below the second level.
 */
function flatten(categories: Category[]): Category[] {
  const rows: Category[] = [];

  for (const category of categories) {
    rows.push(category);

    if (category.children?.length) {
      rows.push(...flatten(category.children));
    }
  }

  return rows;
}
