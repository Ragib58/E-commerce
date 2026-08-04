'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, Plus, Trash2 } from 'lucide-react';

import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import { ApiError } from '@/lib/api/errors';
import { ErrorNotice } from '@/features/catalog/components/admin/data-table';
import {
  createSection,
  deleteSection,
  fetchHomepageSections,
  reorderSections,
  setSectionEnabled,
  updateSection,
  type SectionInput,
} from '@/features/content/api/admin';
import type { AdminSection } from '@/features/content/types';
import { SortableList } from '@/features/content/components/admin/sortable-list';
import { SectionEditor } from '@/features/content/components/admin/section-editor';
import { Badge, Select, WindowBadge } from '@/features/content/components/admin/form-fields';
import { describeWindow } from '@/features/content/lib/dates';

/**
 * The homepage builder.
 *
 * The screen that makes the storefront's front page editable: sections are
 * added from a type catalogue the API supplies, dragged into order, scheduled,
 * toggled, and previewed — none of which requires a deploy.
 *
 * Ordering is optimistic. A drag that had to wait for a round trip before the
 * row moved would feel broken, so the list reorders immediately and reverts if
 * the save fails.
 */
export default function AdminHomepagePage() {
  return (
    <AdminGuard requiredPermissions={['manage_content', 'manage_banners', 'view_settings']}>
      <HomepageBuilder />
    </AdminGuard>
  );
}

function HomepageBuilder() {
  const queryClient = useQueryClient();
  const [editingId, setEditingId] = useState<number | null>(null);
  const [newType, setNewType] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data, isPending, isError, error: queryError } = useQuery({
    queryKey: queryKeys.content.homepage.sections(),
    queryFn: fetchHomepageSections,
  });

  /**
   * The optimistic ordering, as ids.
   *
   * Only the *order* is held locally, never the sections themselves. A drag
   * therefore reorders the list instantly without this component owning a
   * second copy of server state that could drift from it — the section data
   * always comes from the query.
   *
   * Cleared whenever the server's own order matches, and on a failed save,
   * which is what reverts a move the API rejected.
   */
  const [pendingOrder, setPendingOrder] = useState<number[] | null>(null);

  const serverSections = data?.sections ?? [];

  /*
   * Derived during render rather than synced in an effect. An effect would
   * render the server order first and the optimistic one a frame later, which
   * is visible as the dragged row snapping back and then forward again.
   */
  const sections = (() => {
    if (pendingOrder === null) return serverSections;

    const byId = new Map(serverSections.map((section) => [section.id, section]));

    const ordered = pendingOrder
      .map((id) => byId.get(id))
      .filter((section): section is AdminSection => section !== undefined);

    // Anything the server has that the pending order does not — a section
    // added in another tab — is appended rather than dropped.
    const missing = serverSections.filter((section) => !pendingOrder.includes(section.id));

    return [...ordered, ...missing];
  })();

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.content.all });
  }

  const reorderMutation = useMutation({
    mutationFn: (items: Array<{ id: number; sort_order: number }>) => reorderSections(items),
    onSuccess: () => {
      // The save landed, so the server order is now the optimistic one and the
      // local override is redundant.
      setPendingOrder(null);
      invalidate();
    },
    onError: () => {
      setError('The new order could not be saved. The previous order has been restored.');
      // Drop the optimistic order and re-fetch: the server's is authoritative,
      // and another operator may have changed it meanwhile.
      setPendingOrder(null);
      invalidate();
    },
  });

  const createMutation = useMutation({
    mutationFn: (type: string) => createSection({ type }),
    onSuccess: (section) => {
      setNewType('');
      setError(null);
      // Open the new section straight away — it was created with default
      // settings and almost always needs configuring.
      setEditingId(section.id);
      invalidate();
    },
    onError: (mutationError) => {
      setError(
        mutationError instanceof ApiError
          ? mutationError.message
          : 'The section could not be added.',
      );
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, input }: { id: number; input: SectionInput }) => updateSection(id, input),
    onSuccess: () => {
      setEditingId(null);
      setError(null);
      invalidate();
    },
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, enabled }: { id: number; enabled: boolean }) =>
      setSectionEnabled(id, enabled),
    onSuccess: invalidate,
    onError: () => setError('The section could not be toggled.'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteSection(id),
    onSuccess: invalidate,
    onError: () => setError('The section could not be removed.'),
  });

  function handleReorder(next: AdminSection[]) {
    setPendingOrder(next.map((section) => section.id));

    // Spaced by ten so a later insertion between two sections does not require
    // renumbering the whole page.
    reorderMutation.mutate(
      next.map((section, index) => ({ id: section.id, sort_order: index * 10 })),
    );
  }

  function handleDelete(section: AdminSection) {
    const confirmed = window.confirm(
      `Remove “${section.name}” from the homepage? This cannot be undone from here.`,
    );

    if (confirmed) deleteMutation.mutate(section.id);
  }

  const availableTypes = data?.availableTypes ?? [];

  /*
   * Types already used and not repeatable are offered as disabled options
   * rather than hidden. Hiding them raises "where did the hero go?"; showing
   * them with a reason answers it.
   */
  const usedTypes = new Set(sections.map((section) => section.type));

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Homepage</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Add, arrange, and schedule the sections that make up the storefront homepage.
          </p>
        </div>

        <Link
          href="/admin/homepage/preview"
          className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          <Eye className="size-4" aria-hidden="true" />
          Preview
        </Link>
      </header>

      {error ? <ErrorNotice message={error} /> : null}
      {isError ? (
        <ErrorNotice
          message={
            queryError instanceof ApiError
              ? queryError.message
              : 'The homepage sections could not be loaded.'
          }
        />
      ) : null}

      <Can permission="manage_content">
        <form
          onSubmit={(event) => {
            event.preventDefault();

            if (newType) createMutation.mutate(newType);
          }}
          className="flex flex-wrap items-end gap-3 rounded-lg border border-border p-4"
        >
          <div className="flex-1 sm:max-w-sm">
            <label htmlFor="section-type" className="mb-1 block text-sm font-medium">
              Add a section
            </label>
            <Select
              id="section-type"
              value={newType}
              onChange={(event) => setNewType(event.target.value)}
            >
              <option value="">Choose a section type…</option>
              {availableTypes.map((option) => {
                const alreadyUsed = usedTypes.has(option.value) && !option.allows_multiple;

                return (
                  <option key={option.value} value={option.value} disabled={alreadyUsed}>
                    {option.label}
                    {alreadyUsed ? ' (already added)' : ''}
                  </option>
                );
              })}
            </Select>
          </div>

          <button
            type="submit"
            disabled={!newType || createMutation.isPending}
            className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <Plus className="size-4" aria-hidden="true" />
            {createMutation.isPending ? 'Adding…' : 'Add section'}
          </button>
        </form>
      </Can>

      {isPending ? (
        <p className="py-12 text-center text-sm text-muted-foreground">Loading sections…</p>
      ) : sections.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-16 text-center">
          <p className="text-sm text-muted-foreground">
            No sections yet. Add one above to start building the homepage.
          </p>
        </div>
      ) : (
        <SortableList
          items={sections}
          onReorder={handleReorder}
          disabled={reorderMutation.isPending}
          itemLabel={(section) => section.name}
          renderItem={(section) => (
            <div className="space-y-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="truncate text-sm font-medium">{section.name}</h2>
                    <Badge>{section.type_label}</Badge>
                    <WindowBadge state={section.window_state} isEnabled={section.is_enabled} />
                  </div>

                  <p className="mt-1 text-xs text-muted-foreground">
                    {section.heading ? `“${section.heading}” · ` : ''}
                    {describeWindow(section.starts_at, section.ends_at)}
                  </p>
                </div>

                <Can permission="manage_content">
                  <div className="flex shrink-0 items-center gap-1.5">
                    <button
                      type="button"
                      onClick={() =>
                        toggleMutation.mutate({ id: section.id, enabled: !section.is_enabled })
                      }
                      className="rounded-md border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                      {section.is_enabled ? 'Disable' : 'Enable'}
                    </button>

                    <button
                      type="button"
                      onClick={() => setEditingId(editingId === section.id ? null : section.id)}
                      className="rounded-md border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    >
                      {editingId === section.id ? 'Close' : 'Edit'}
                    </button>

                    <button
                      type="button"
                      onClick={() => handleDelete(section)}
                      aria-label={`Remove ${section.name}`}
                      className="rounded-md border border-border p-1.5 text-destructive hover:bg-destructive/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                    >
                      <Trash2 className="size-3.5" aria-hidden="true" />
                    </button>
                  </div>
                </Can>
              </div>

              {editingId === section.id ? (
                <SectionEditor
                  section={section}
                  typeOption={availableTypes.find((option) => option.value === section.type)}
                  isSaving={updateMutation.isPending}
                  onCancel={() => setEditingId(null)}
                  onSave={(input) => updateMutation.mutateAsync({ id: section.id, input })}
                />
              ) : null}
            </div>
          )}
        />
      )}
    </div>
  );
}
