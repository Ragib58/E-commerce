'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, RotateCcw } from 'lucide-react';

import { AdminGuard } from '@/features/auth/components/admin-guard';
import { queryKeys } from '@/config/query-keys';
import { ApiError } from '@/lib/api/errors';
import { ErrorNotice } from '@/features/catalog/components/admin/data-table';
import { fetchHomepagePreview } from '@/features/content/api/admin';
import { SectionRenderer } from '@/features/content/components/section-renderer';
import { useStoreConfig } from '@/components/providers/store-config-provider';
import { Field, TextInput } from '@/features/content/components/admin/form-fields';
import { fromDateTimeLocal, toDateTimeLocal } from '@/features/content/lib/dates';

/**
 * Homepage preview.
 *
 * Renders the storefront's own section components against the API's preview
 * endpoint, so what an operator sees here is what a shopper gets — not a
 * separate approximation that drifts from the real page.
 *
 * The time control is the point of the screen. Scheduling that can only be
 * verified by waiting for the scheduled moment is scheduling nobody trusts;
 * setting the clock forward answers "will the Black Friday hero actually
 * appear?" before Black Friday.
 */
export default function HomepagePreviewPage() {
  return (
    <AdminGuard requiredPermissions={['manage_content', 'manage_banners', 'view_settings']}>
      <HomepagePreview />
    </AdminGuard>
  );
}

function HomepagePreview() {
  const config = useStoreConfig();

  /** Empty means "now" — the API defaults to the current moment. */
  const [at, setAt] = useState('');

  const isoAt = at ? fromDateTimeLocal(at) : null;

  const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: queryKeys.content.homepage.preview(isoAt ?? undefined),
    queryFn: () => fetchHomepagePreview(isoAt ?? undefined),
  });

  const sections = data ?? [];

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link
            href="/admin/homepage"
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="size-4" aria-hidden="true" />
            Back to the builder
          </Link>

          <h1 className="mt-2 text-xl font-semibold tracking-tight">Homepage preview</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Exactly what the storefront renders. Set a date and time to check a scheduled section
            before it goes live.
          </p>
        </div>
      </header>

      <div className="flex flex-wrap items-end gap-3 rounded-lg border border-border p-4">
        <Field
          label="Preview as at"
          hint="Leave blank to preview the homepage as it is right now."
          className="flex-1 sm:max-w-xs"
        >
          {({ id, describedBy }) => (
            <TextInput
              id={id}
              type="datetime-local"
              value={at}
              describedBy={describedBy}
              onChange={(event) => setAt(event.target.value)}
            />
          )}
        </Field>

        {at ? (
          <button
            type="button"
            onClick={() => setAt('')}
            className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <RotateCcw className="size-4" aria-hidden="true" />
            Reset to now
          </button>
        ) : null}

        <div className="ml-auto flex gap-2">
          {/* Common checks, so an operator does not have to compute a date. */}
          {[
            { label: 'In 1 hour', ms: 3600_000 },
            { label: 'Tomorrow', ms: 86_400_000 },
            { label: 'Next week', ms: 604_800_000 },
          ].map((preset) => (
            <button
              key={preset.label}
              type="button"
              onClick={() => setAt(toDateTimeLocal(new Date(Date.now() + preset.ms).toISOString()))}
              className="rounded-md border border-border px-2.5 py-1.5 text-xs font-medium hover:bg-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            >
              {preset.label}
            </button>
          ))}
        </div>
      </div>

      {isError ? (
        <ErrorNotice
          message={error instanceof ApiError ? error.message : 'The preview could not be loaded.'}
        />
      ) : null}

      <div
        className={
          isFetching
            ? 'overflow-hidden rounded-lg border border-border opacity-60 transition-opacity'
            : 'overflow-hidden rounded-lg border border-border transition-opacity'
        }
      >
        {isPending ? (
          <p className="py-16 text-center text-sm text-muted-foreground">Building preview…</p>
        ) : sections.length === 0 ? (
          <p className="py-16 text-center text-sm text-muted-foreground">
            No sections would be visible at this time. Every section is either disabled or outside
            its schedule.
          </p>
        ) : (
          /*
           * The real storefront components, not a mock.
           *
           * They read the same store config the storefront does, so prices,
           * currency, and brand colours all match — a preview that got any of
           * those wrong would be worse than none.
           */
          <div className="bg-background">
            {sections.map((section, index) => (
              <SectionRenderer
                key={section.id}
                section={section}
                config={config}
                isFirst={index === 0}
              />
            ))}
          </div>
        )}
      </div>

      <p className="text-xs text-muted-foreground">
        {sections.length} section{sections.length === 1 ? '' : 's'} visible
        {at ? ` at ${new Date(at).toLocaleString()}` : ' right now'}.
      </p>
    </div>
  );
}
