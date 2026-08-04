import type { Metadata } from 'next';

import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import { fetchHomepage } from '@/features/content/api';
import { SectionRenderer } from '@/features/content/components/section-renderer';

/**
 * The storefront homepage.
 *
 * Composed entirely from the API response. There is no section list here, no
 * hero markup, no rail of featured products — the page fetches an ordered array
 * of sections and maps each one through SectionRenderer. Adding a section,
 * reordering the page, or scheduling a campaign is an admin action against the
 * database, never a change to this file.
 *
 * The consequence worth stating: **this component contains no business
 * content.** Every string a shopper reads here — headings, banner copy, calls
 * to action — originates in the admin panel. The only literal text below is the
 * empty-state and error copy, which describes the *application's* state rather
 * than the store's merchandising.
 *
 * Rendered as an ISR page rather than force-dynamic: the payload is identical
 * for every visitor, so it is cached and revalidated by tag when an admin
 * saves. The window is short because sections carry scheduling windows that
 * open and close with no admin action behind them, and therefore with no
 * revalidation webhook to trigger.
 */

export const revalidate = 60;

/**
 * Homepage metadata, from the admin-managed settings.
 *
 * `title.absolute` is deliberate: it suppresses the root layout's
 * `%s — Store` template so the homepage shows the store's own meta title rather
 * than "Home — Store".
 */
export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: { absolute: config.metaTitle },
    description: config.metaDescription,
    alternates: { canonical: '/' },
    openGraph: {
      type: 'website',
      url: '/',
      siteName: config.companyName,
      title: config.metaTitle,
      description: config.metaDescription,
      images: config.ogImage ? [{ url: config.ogImage, width: 1200, height: 630 }] : undefined,
    },
  };
}

export default async function HomePage() {
  // Issued concurrently: the store config and the homepage payload do not
  // depend on each other, and awaiting them in sequence would add a round trip
  // to every cold render.
  const [{ config }, homepage] = await Promise.all([getStoreConfig(), fetchHomepage()]);

  if (homepage.sections.length === 0) {
    return <EmptyHomepage isFallback={homepage.isFallback} companyName={config.companyName} />;
  }

  return (
    <>
      {homepage.sections.map((section, index) => (
        <SectionRenderer
          key={section.id}
          section={section}
          config={config}
          // Exactly one section carries the above-the-fold hint.
          isFirst={index === 0}
        />
      ))}
    </>
  );
}

/**
 * Shown when the homepage has no sections.
 *
 * The two causes are distinguished because they call for different actions: an
 * unreachable API is an operational fault, while an unconfigured homepage is a
 * task waiting in the admin panel. One message for both would send an operator
 * hunting for the wrong problem.
 */
function EmptyHomepage({
  isFallback,
  companyName,
}: {
  isFallback: boolean;
  companyName: string;
}) {
  return (
    <div className="mx-auto flex min-h-[60vh] max-w-2xl flex-col items-center justify-center px-4 py-20 text-center">
      <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{companyName}</h1>

      {isFallback ? (
        <div
          role="alert"
          className="mt-6 w-full rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm"
        >
          <p className="font-medium text-destructive">Storefront content is unavailable</p>
          <p className="mt-1 text-muted-foreground">
            The content API could not be reached. Confirm the Laravel API is running and that{' '}
            <code className="rounded bg-muted px-1 py-0.5">NEXT_PUBLIC_API_URL</code> is correct.
          </p>
        </div>
      ) : (
        <p className="mt-4 text-muted-foreground">
          This homepage has no sections yet. Add and arrange them under{' '}
          <span className="font-medium text-foreground">Content → Homepage</span> in the admin
          panel.
        </p>
      )}
    </div>
  );
}
