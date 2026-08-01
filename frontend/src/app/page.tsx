import { fetchPublicSettings, withDefaults } from '@/features/settings/api';
import { ConnectionStatus } from '@/features/health/components/connection-status';

/**
 * Homepage.
 *
 * In this foundation phase its job is to prove the wiring: it renders content
 * fetched from the Laravel settings API and shows a live dependency report, so
 * "is the stack connected end to end?" is answerable by loading one page.
 *
 * The marketing storefront replaces this content in a later phase.
 */

// Rendered per request rather than statically: the page displays live health
// data, which a build-time snapshot would render meaningless.
export const dynamic = 'force-dynamic';

export default async function HomePage() {
  const { settings, version, isFallback } = await fetchPublicSettings();
  const resolved = withDefaults(settings);

  const companyName = resolved.general?.company_name ?? 'Store';
  const tagline = resolved.general?.tagline;

  return (
    <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
      <section className="max-w-2xl">
        <p className="text-sm font-medium text-primary">Foundation phase</p>
        <h1 className="mt-3 text-4xl font-semibold tracking-tight sm:text-5xl">{companyName}</h1>
        {tagline ? <p className="mt-4 text-lg text-muted-foreground">{tagline}</p> : null}
        {resolved.general?.description ? (
          <p className="mt-3 text-muted-foreground">{resolved.general.description}</p>
        ) : null}
      </section>

      {isFallback ? (
        <div
          role="alert"
          className="mt-8 rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
        >
          <p className="font-medium text-destructive">Settings API unreachable</p>
          <p className="mt-1 text-muted-foreground">
            Neutral fallback values are being displayed. Confirm the Laravel API is running and that{' '}
            <code className="rounded bg-muted px-1 py-0.5">NEXT_PUBLIC_API_URL</code> is correct.
          </p>
        </div>
      ) : null}

      <section className="mt-12 grid gap-6 md:grid-cols-2">
        <ConnectionStatus />

        <div className="rounded-lg border border-border bg-card p-6">
          <h2 className="text-sm font-semibold">Dynamic configuration</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Every value below is served by the Laravel settings API and editable from the admin
            panel. None is hardcoded in this application.
          </p>

          <dl className="mt-4 space-y-2.5 text-sm">
            <ConfigRow label="Company name" value={resolved.general?.company_name} />
            <ConfigRow label="Currency" value={resolved.general?.currency} />
            <ConfigRow label="Primary colour" value={resolved.theme?.primary_color} swatch />
            <ConfigRow label="Accent colour" value={resolved.theme?.accent_color} swatch />
            <ConfigRow label="Support email" value={resolved.contact?.email} />
            <ConfigRow label="Logo" value={resolved.branding?.logo ?? 'not uploaded'} />
            <ConfigRow label="Settings version" value={version} />
          </dl>
        </div>
      </section>
    </div>
  );
}

function ConfigRow({
  label,
  value,
  swatch = false,
}: {
  label: string;
  value?: string | null;
  swatch?: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-border pb-2 last:border-0">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="flex items-center gap-2 font-medium">
        {swatch && value ? (
          <span
            aria-hidden="true"
            className="size-3.5 rounded-full border border-border"
            style={{ backgroundColor: value }}
          />
        ) : null}
        <span className="truncate">{value ?? '—'}</span>
      </dd>
    </div>
  );
}
