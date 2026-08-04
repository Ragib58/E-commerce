'use client';

import { useState } from 'react';
import Image from 'next/image';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';

import { AdminGuard, Can } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import { ApiError } from '@/lib/api/errors';
import { ErrorNotice } from '@/features/catalog/components/admin/data-table';
import {
  createBanner,
  deleteBanner,
  fetchAdminBanners,
  updateBanner,
  type BannerInput,
} from '@/features/content/api/admin';
import type { AdminBanner, PlacementOption } from '@/features/content/types';
import {
  Checkbox,
  Field,
  ScheduleFields,
  Select,
  TextInput,
  WindowBadge,
} from '@/features/content/components/admin/form-fields';
import { describeWindow, fromDateTimeLocal, toDateTimeLocal } from '@/features/content/lib/dates';

/**
 * Banner management.
 *
 * Banners are grouped by placement rather than listed flat: they are ordered
 * *within* a placement, and a single list mixing hero slides with checkout
 * strips would make the ordering meaningless.
 */
export default function AdminBannersPage() {
  return (
    <AdminGuard requiredPermissions={['manage_banners', 'manage_content', 'view_settings']}>
      <BannerManager />
    </AdminGuard>
  );
}

function BannerManager() {
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState<AdminBanner | null>(null);
  const [isCreating, setIsCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data, isPending, isError, error: queryError } = useQuery({
    queryKey: queryKeys.content.banners.list(),
    queryFn: () => fetchAdminBanners(),
  });

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.content.all });
  }

  const deleteMutation = useMutation({
    mutationFn: (id: number) => deleteBanner(id),
    onSuccess: invalidate,
    onError: () => setError('The banner could not be deleted.'),
  });

  const banners = data?.banners ?? [];
  const placements = data?.placements ?? [];

  // Grouped by placement, preserving the API's ordering within each group.
  const grouped = placements
    .map((placement) => ({
      placement,
      banners: banners.filter((banner) => banner.placement === placement.value),
    }))
    // A placement with no banners is still shown: it tells an operator the slot
    // exists and is empty, which is why a hero might not be rendering.
    .filter((group) => group.banners.length > 0 || group.placement.value === 'hero_slider');

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Banners</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Campaign imagery for the hero slider, promotional strips, and other storefront slots.
          </p>
        </div>

        <Can permission="manage_banners">
          <button
            type="button"
            onClick={() => {
              setIsCreating(true);
              setEditing(null);
            }}
            className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <Plus className="size-4" aria-hidden="true" />
            New banner
          </button>
        </Can>
      </header>

      {error ? <ErrorNotice message={error} /> : null}
      {isError ? (
        <ErrorNotice
          message={
            queryError instanceof ApiError
              ? queryError.message
              : 'The banners could not be loaded.'
          }
        />
      ) : null}

      {isCreating ? (
        <BannerForm
          placements={placements}
          onDone={() => {
            setIsCreating(false);
            invalidate();
          }}
          onCancel={() => setIsCreating(false)}
        />
      ) : null}

      {editing ? (
        <BannerForm
          banner={editing}
          placements={placements}
          onDone={() => {
            setEditing(null);
            invalidate();
          }}
          onCancel={() => setEditing(null)}
        />
      ) : null}

      {isPending ? (
        <p className="py-12 text-center text-sm text-muted-foreground">Loading banners…</p>
      ) : banners.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-16 text-center">
          <p className="text-sm text-muted-foreground">
            No banners yet. The homepage hero stays hidden until at least one is published.
          </p>
        </div>
      ) : (
        <div className="space-y-8">
          {grouped.map(({ placement, banners: group }) => (
            <section key={placement.value}>
              <h2 className="text-sm font-semibold">{placement.label}</h2>

              {group.length === 0 ? (
                <p className="mt-2 rounded-lg border border-dashed border-border py-8 text-center text-sm text-muted-foreground">
                  Nothing in this slot.
                </p>
              ) : (
                <ul className="mt-3 space-y-2">
                  {group.map((banner) => (
                    <li
                      key={banner.id}
                      className="flex items-center gap-3 rounded-lg border border-border bg-card p-3"
                    >
                      <div className="relative h-14 w-24 shrink-0 overflow-hidden rounded bg-muted">
                        {banner.image ? (
                          <Image
                            src={banner.image}
                            alt=""
                            fill
                            sizes="96px"
                            className="object-cover"
                          />
                        ) : null}
                      </div>

                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="truncate text-sm font-medium">{banner.title}</p>
                          <WindowBadge state={banner.window_state} isEnabled={banner.is_live} />
                        </div>
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                          {describeWindow(banner.starts_at, banner.ends_at)}
                          {banner.link_url ? ` · ${banner.link_url}` : ''}
                        </p>
                      </div>

                      <Can permission="manage_banners">
                        <div className="flex shrink-0 items-center gap-1.5">
                          <button
                            type="button"
                            onClick={() => {
                              setEditing(banner);
                              setIsCreating(false);
                            }}
                            className="rounded-md border border-border px-2.5 py-1 text-xs font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                          >
                            Edit
                          </button>

                          <button
                            type="button"
                            onClick={() => {
                              if (window.confirm(`Delete “${banner.title}”?`)) {
                                deleteMutation.mutate(banner.id);
                              }
                            }}
                            aria-label={`Delete ${banner.title}`}
                            className="rounded-md border border-border p-1.5 text-destructive hover:bg-destructive/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                          >
                            <Trash2 className="size-3.5" aria-hidden="true" />
                          </button>
                        </div>
                      </Can>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * Create or edit a banner.
 *
 * One form for both, because the fields are identical — only whether an image
 * is required differs, and that is one conditional rather than a second
 * component.
 */
function BannerForm({
  banner,
  placements,
  onDone,
  onCancel,
}: {
  banner?: AdminBanner;
  placements: PlacementOption[];
  onDone: () => void;
  onCancel: () => void;
}) {
  const isEdit = banner !== undefined;

  const [title, setTitle] = useState(banner?.title ?? '');
  const [subtitle, setSubtitle] = useState(banner?.subtitle ?? '');
  const [altText, setAltText] = useState(banner?.alt_text ?? '');
  const [linkUrl, setLinkUrl] = useState(banner?.link_url ?? '');
  const [linkLabel, setLinkLabel] = useState(banner?.link_label ?? '');
  const [linkExternal, setLinkExternal] = useState(banner?.link_external ?? false);
  const [placement, setPlacement] = useState(banner?.placement ?? placements[0]?.value ?? 'hero_slider');
  // Typed as a plain string rather than the PublishStatus union: the value
  // comes from a <select>, whose onChange yields string, and the API validates
  // it against the enum anyway.
  const [status, setStatus] = useState<string>(banner?.status ?? 'draft');
  const [startsAt, setStartsAt] = useState(toDateTimeLocal(banner?.starts_at));
  const [endsAt, setEndsAt] = useState(toDateTimeLocal(banner?.ends_at));
  const [image, setImage] = useState<File | null>(null);
  const [mobileImage, setMobileImage] = useState<File | null>(null);

  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const mutation = useMutation({
    mutationFn: (input: BannerInput) =>
      isEdit ? updateBanner(banner.id, input) : createBanner(input),
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
        setError('The banner could not be saved.');
      }
    },
  });

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldErrors({});

    mutation.mutate({
      title: title.trim(),
      subtitle: subtitle.trim() || null,
      alt_text: altText.trim() || null,
      link_url: linkUrl.trim() || null,
      link_label: linkLabel.trim() || null,
      link_external: linkExternal,
      placement,
      status,
      starts_at: fromDateTimeLocal(startsAt),
      ends_at: fromDateTimeLocal(endsAt),
      image,
      mobile_image: mobileImage,
    });
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5 rounded-lg border border-border p-4">
      <h2 className="text-sm font-semibold">{isEdit ? `Edit “${banner.title}”` : 'New banner'}</h2>

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
              maxLength={180}
            />
          )}
        </Field>

        <Field label="Subtitle" error={fieldErrors.subtitle}>
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={subtitle}
              describedBy={describedBy}
              onChange={(event) => setSubtitle(event.target.value)}
              maxLength={320}
            />
          )}
        </Field>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label={isEdit ? 'Replace image' : 'Image'}
          hint={isEdit ? 'Leave empty to keep the current image.' : 'Required.'}
          error={fieldErrors.image}
        >
          {({ id, describedBy }) => (
            <input
              id={id}
              type="file"
              accept="image/*"
              required={!isEdit}
              aria-describedby={describedBy}
              onChange={(event) => setImage(event.target.files?.[0] ?? null)}
              className="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium"
            />
          )}
        </Field>

        <Field
          label="Mobile image"
          hint="Optional. A portrait crop for phones; the main image is used when absent."
          error={fieldErrors.mobile_image}
        >
          {({ id, describedBy }) => (
            <input
              id={id}
              type="file"
              accept="image/*"
              aria-describedby={describedBy}
              onChange={(event) => setMobileImage(event.target.files?.[0] ?? null)}
              className="w-full text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium"
            />
          )}
        </Field>
      </div>

      <Field
        label="Alt text"
        hint="Describes the image for screen readers. The title is used if left blank."
        error={fieldErrors.alt_text}
      >
        {({ id, describedBy }) => (
          <TextInput
            id={id}
            value={altText}
            describedBy={describedBy}
            onChange={(event) => setAltText(event.target.value)}
            maxLength={255}
          />
        )}
      </Field>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          label="Link URL"
          hint="A full https:// address or a path beginning with “/”."
          error={fieldErrors.link_url}
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={linkUrl}
              describedBy={describedBy}
              placeholder="/categories/sale"
              onChange={(event) => setLinkUrl(event.target.value)}
              maxLength={512}
            />
          )}
        </Field>

        <Field label="Button label" error={fieldErrors.link_label}>
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              value={linkLabel}
              describedBy={describedBy}
              placeholder="Shop the sale"
              onChange={(event) => setLinkLabel(event.target.value)}
              maxLength={80}
            />
          )}
        </Field>
      </div>

      <Checkbox
        label="Open the link in a new tab"
        hint="Usually only for links to another site."
        checked={linkExternal}
        onChange={setLinkExternal}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Placement" error={fieldErrors.placement}>
          {({ id }) => (
            <Select
              id={id}
              value={placement}
              onChange={(event) => setPlacement(event.target.value)}
            >
              {placements.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          )}
        </Field>

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
          {mutation.isPending ? 'Saving…' : 'Save banner'}
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
