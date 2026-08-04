'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, Lock, Plus, Trash2 } from 'lucide-react';

import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import { ApiError } from '@/lib/api/errors';
import { ErrorNotice } from '@/features/catalog/components/admin/data-table';
import {
  createPage,
  deletePage,
  fetchAdminPages,
  setPageStatus,
  updatePage,
  type CmsPageInput,
} from '@/features/content/api/admin';
import type { AdminCmsPage } from '@/features/content/types';
import {
  Badge,
  Checkbox,
  Field,
  ScheduleFields,
  Select,
  TextArea,
  TextInput,
} from '@/features/content/components/admin/form-fields';
import { formatDateTime, fromDateTimeLocal, toDateTimeLocal } from '@/features/content/lib/dates';

/**
 * CMS page management.
 *
 * The seeded legal pages are marked and cannot be deleted — only unpublished.
 * They are otherwise fully editable: a store must be able to write its own
 * refund policy, and one it cannot change would be worse than none.
 */
export default function AdminPagesPage() {
  return (
    <AdminGuard requiredPermissions={['manage_content', 'view_settings']}>
      <PageManager />
    </AdminGuard>
  );
}

function PageManager() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<AdminCmsPage | null>(null);
  const [isCreating, setIsCreating] = useState(false);
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);

  const { data, isPending, isError, error: queryError } = useQuery({
    queryKey: queryKeys.content.pages.list({ search }),
    queryFn: () => fetchAdminPages({ search: search || undefined }),
  });

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.content.all });
  }

  const statusMutation = useMutation({
    mutationFn: ({ slug, status }: { slug: string; status: string }) => setPageStatus(slug, status),
    onSuccess: invalidate,
    onError: () => setError('The page status could not be changed.'),
  });

  const deleteMutation = useMutation({
    mutationFn: (slug: string) => deletePage(slug),
    onSuccess: invalidate,
    onError: (deleteError) => {
      // The API explains *why* a system page cannot be deleted, and that
      // message is more useful than anything generic written here.
      setError(
        deleteError instanceof ApiError ? deleteError.message : 'The page could not be deleted.',
      );
    },
  });

  const pages = data ?? [];

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Pages</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            About, contact, and policy pages. Each is published at{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-xs">/p/slug</code> and linked from
            the site footer.
          </p>
        </div>

        <Can permission="manage_content">
          <button
            type="button"
            onClick={() => {
              setIsCreating(true);
              setEditing(null);
            }}
            className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <Plus className="size-4" aria-hidden="true" />
            New page
          </button>
        </Can>
      </header>

      {error ? <ErrorNotice message={error} /> : null}
      {isError ? (
        <ErrorNotice
          message={
            queryError instanceof ApiError ? queryError.message : 'The pages could not be loaded.'
          }
        />
      ) : null}

      {isCreating ? (
        <PageForm
          onDone={() => {
            setIsCreating(false);
            invalidate();
          }}
          onCancel={() => setIsCreating(false)}
        />
      ) : null}

      {editing ? (
        <PageForm
          page={editing}
          onDone={() => {
            setEditing(null);
            invalidate();
          }}
          onCancel={() => setEditing(null)}
        />
      ) : null}

      <div className="sm:max-w-xs">
        <label htmlFor="page-search" className="sr-only">
          Search pages
        </label>
        <TextInput
          id="page-search"
          type="search"
          value={search}
          placeholder="Search pages…"
          onChange={(event) => setSearch(event.target.value)}
        />
      </div>

      {isPending ? (
        <p className="py-12 text-center text-sm text-muted-foreground">Loading pages…</p>
      ) : pages.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-16 text-center">
          <p className="text-sm text-muted-foreground">No pages match your search.</p>
        </div>
      ) : (
        <ul className="space-y-2">
          {pages.map((page) => (
            <li
              key={page.id}
              className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-3"
            >
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="truncate text-sm font-medium">{page.title}</p>

                  <Badge tone={page.status === 'published' ? 'success' : 'muted'}>
                    {page.status}
                  </Badge>

                  {page.is_system ? (
                    <span
                      title="A required store page. It can be edited, but not deleted."
                      className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                    >
                      <Lock className="size-3" aria-hidden="true" />
                      Required
                    </span>
                  ) : null}
                </div>

                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                  /p/{page.slug}
                  {page.published_at ? ` · published ${formatDateTime(page.published_at)}` : ''}
                </p>
              </div>

              <div className="flex shrink-0 items-center gap-1.5">
                {page.status === 'published' ? (
                  <a
                    href={`/p/${page.slug}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={`View ${page.title} on the storefront`}
                    className="rounded-md border border-border p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  >
                    <ExternalLink className="size-3.5" aria-hidden="true" />
                  </a>
                ) : null}

                <Can permission="manage_content">
                  <button
                    type="button"
                    onClick={() =>
                      statusMutation.mutate({
                        slug: page.slug,
                        status: page.status === 'published' ? 'draft' : 'published',
                      })
                    }
                    className="rounded-md border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  >
                    {page.status === 'published' ? 'Unpublish' : 'Publish'}
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      setEditing(page);
                      setIsCreating(false);
                    }}
                    className="rounded-md border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                  >
                    Edit
                  </button>

                  {/*
                    Hidden rather than disabled for system pages: a disabled
                    delete button invites repeated clicking, while its absence
                    alongside the "Required" chip reads correctly.
                  */}
                  {!page.is_system ? (
                    <button
                      type="button"
                      onClick={() => {
                        if (window.confirm(`Delete “${page.title}”? This cannot be undone.`)) {
                          deleteMutation.mutate(page.slug);
                        }
                      }}
                      aria-label={`Delete ${page.title}`}
                      className="rounded-md border border-border p-1.5 text-destructive hover:bg-destructive/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                    >
                      <Trash2 className="size-3.5" aria-hidden="true" />
                    </button>
                  ) : null}
                </Can>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Create or edit a CMS page.
 */
function PageForm({
  page,
  onDone,
  onCancel,
}: {
  page?: AdminCmsPage;
  onDone: () => void;
  onCancel: () => void;
}) {
  const isEdit = page !== undefined;

  const [title, setTitle] = useState(page?.title ?? '');
  const [slug, setSlug] = useState(page?.slug ?? '');
  const [excerpt, setExcerpt] = useState(page?.excerpt ?? '');
  const [content, setContent] = useState(page?.content ?? '');
  const [seoTitle, setSeoTitle] = useState(page?.seo?.title ?? '');
  const [seoDescription, setSeoDescription] = useState(page?.seo?.description ?? '');
  const [seoKeywords, setSeoKeywords] = useState(page?.seo?.keywords ?? '');
  const [isIndexable, setIsIndexable] = useState(page?.seo?.indexable ?? true);
  // Typed as a plain string rather than the PublishStatus union — see the
  // banner form for the same reasoning.
  const [status, setStatus] = useState<string>(page?.status ?? 'draft');
  const [startsAt, setStartsAt] = useState(toDateTimeLocal(page?.starts_at));
  const [endsAt, setEndsAt] = useState(toDateTimeLocal(page?.ends_at));
  const [featuredImage, setFeaturedImage] = useState<File | null>(null);

  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const mutation = useMutation({
    mutationFn: (input: CmsPageInput) =>
      isEdit ? updatePage(page.slug, input) : createPage(input),
    onSuccess: onDone,
    onError: (mutationError) => {
      if (mutationError instanceof ApiError) {
        setError(mutationError.message);

        const errors: Record<string, string> = {};

        for (const [key, messages] of Object.entries(mutationError.errors ?? {})) {
          const first = messages[0];

          if (first) errors[key] = first;
        }

        setFieldErrors(errors);
      } else {
        setError('The page could not be saved.');
      }
    },
  });

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});

    mutation.mutate({
      title: title.trim(),
      // Only sent when the operator actually typed one. An unchanged slug must
      // not be resubmitted on every save — the API regenerates it when present,
      // and that would break inbound links on an edit that only fixed a typo.
      ...(slug.trim() && slug.trim() !== page?.slug ? { slug: slug.trim() } : {}),
      excerpt: excerpt.trim() || null,
      content: content || null,
      seo_title: seoTitle.trim() || null,
      seo_description: seoDescription.trim() || null,
      seo_keywords: seoKeywords.trim() || null,
      is_indexable: isIndexable,
      status,
      starts_at: fromDateTimeLocal(startsAt),
      ends_at: fromDateTimeLocal(endsAt),
      featured_image: featuredImage,
    });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5 rounded-lg border border-border p-4">
      <h2 className="text-sm font-semibold">{isEdit ? `Edit “${page.title}”` : 'New page'}</h2>

      {error ? (
        <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/5 p-3">
          <p className="text-sm text-destructive">{error}</p>
        </div>
      ) : null}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Title" error={fieldErrors.title}>
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={title}
              describedBy={describedBy}
              onChange={(event) => setTitle(event.target.value)}
              required
              minLength={2}
              maxLength={200}
            />
          )}
        </Field>

        <Field
          label="Slug"
          hint={
            isEdit
              ? 'Changing this breaks existing links to the page.'
              : 'Derived from the title when left blank.'
          }
          error={fieldErrors.slug}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={slug}
              describedBy={describedBy}
              placeholder="about-us"
              pattern="^[a-z0-9]+(?:-[a-z0-9]+)*$"
              onChange={(event) => setSlug(event.target.value)}
              maxLength={220}
            />
          )}
        </Field>
      </div>

      <Field
        label="Excerpt"
        hint="A short summary, used in the footer listing and as a fallback meta description."
        error={fieldErrors.excerpt}
      >
        {({ id, describedBy }) => (
          <TextArea
            id={id}
            rows={2}
            value={excerpt}
            describedBy={describedBy}
            onChange={(event) => setExcerpt(event.target.value)}
            maxLength={1000}
          />
        )}
      </Field>

      <Field
        label="Content"
        hint="Basic HTML is allowed. Scripts, styles, and event handlers are removed on save."
        error={fieldErrors.content}
      >
        {({ id, describedBy }) => (
          <TextArea
            id={id}
            rows={16}
            value={content}
            describedBy={describedBy}
            onChange={(event) => setContent(event.target.value)}
            className="font-mono text-xs"
          />
        )}
      </Field>

      <Field
        label={isEdit ? 'Replace featured image' : 'Featured image'}
        hint="Optional. Shown at the top of the page and used for social cards."
        error={fieldErrors.featured_image}
      >
        {({ id, describedBy }) => (
          <input
            id={id}
            type="file"
            accept="image/*"
            aria-describedby={describedBy}
            onChange={(event) => setFeaturedImage(event.target.files?.[0] ?? null)}
            className="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium"
          />
        )}
      </Field>

      <fieldset className="space-y-4 rounded-md border border-border p-3">
        <legend className="px-1 text-sm font-medium">Search engine listing</legend>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="SEO title"
            hint="Falls back to the page title."
            error={fieldErrors.seo_title}
          >
            {({ id, describedBy }) => (
              <TextInput
                id={id}
                value={seoTitle}
                describedBy={describedBy}
                onChange={(event) => setSeoTitle(event.target.value)}
                maxLength={255}
              />
            )}
          </Field>

          <Field label="Keywords" error={fieldErrors.seo_keywords}>
            {({ id, describedBy }) => (
              <TextInput
                id={id}
                value={seoKeywords}
                describedBy={describedBy}
                placeholder="returns, refunds, policy"
                onChange={(event) => setSeoKeywords(event.target.value)}
                maxLength={500}
              />
            )}
          </Field>
        </div>

        <Field
          label="Meta description"
          hint="Around 160 characters. Search results truncate beyond that."
          error={fieldErrors.seo_description}
        >
          {({ id, describedBy }) => (
            <TextArea
              id={id}
              rows={2}
              value={seoDescription}
              describedBy={describedBy}
              onChange={(event) => setSeoDescription(event.target.value)}
              maxLength={500}
            />
          )}
        </Field>

        <Checkbox
          label="Allow search engines to index this page"
          hint="Turn off for pages that must be reachable but should not appear in results."
          checked={isIndexable}
          onChange={setIsIndexable}
        />
      </fieldset>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Status" error={fieldErrors.status}>
          {({ id }) => (
            <Select id={id} value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="draft">Draft</option>
              <option value="published">Published</option>
              <option value="scheduled">Scheduled</option>
              <option value="archived">Archived</option>
            </Select>
          )}
        </Field>
      </div>

      <fieldset className="space-y-2 rounded-md border border-border p-3">
        <legend className="px-1 text-sm font-medium">Schedule</legend>
        <ScheduleFields
          startsAt={startsAt}
          endsAt={endsAt}
          onChange={(field, value) =>
            field === 'starts_at' ? setStartsAt(value) : setEndsAt(value)
          }
          disabled={mutation.isPending}
        />
        {fieldErrors.ends_at ? (
          <p className="text-xs font-medium text-destructive">{fieldErrors.ends_at}</p>
        ) : null}
      </fieldset>

      <div className="flex items-center gap-2">
        <button
          type="submit"
          disabled={mutation.isPending}
          className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          {mutation.isPending ? 'Saving…' : 'Save page'}
        </button>

        <button
          type="button"
          onClick={onCancel}
          className="rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >
          Cancel
        </button>
      </div>
    </form>
  );
}
