import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import './globals.css';

import { fetchPublicSettings, withDefaults } from '@/features/settings/api';
import { buildThemeCss } from '@/lib/utils/theme';
import { QueryProvider } from '@/components/providers/query-provider';
import { SettingsProvider } from '@/components/providers/settings-provider';
import { SiteHeader } from '@/components/layout/site-header';
import { SiteFooter } from '@/components/layout/site-footer';

/**
 * Root layout.
 *
 * A server component, deliberately: it fetches the admin-managed settings and
 * renders the company name, favicon, and brand colours into the initial HTML.
 * Doing this on the client instead would produce a visible flash of the
 * default theme and leave the real title invisible to crawlers.
 *
 * Nothing here is hardcoded — the title, description, icons, social image, and
 * every colour originate in the Laravel admin panel.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { settings } = await fetchPublicSettings();
  const resolved = withDefaults(settings);

  const companyName = resolved.general?.company_name ?? 'Store';
  const tagline = resolved.general?.tagline ?? '';
  const title = resolved.seo?.meta_title ?? (tagline ? `${companyName} — ${tagline}` : companyName);
  const description = resolved.seo?.meta_description ?? resolved.general?.description ?? '';
  const favicon = resolved.branding?.favicon;
  const ogImage = resolved.branding?.og_image;

  return {
    title: {
      // Child pages supply only their own name; the company name is appended
      // from settings so renaming the company updates every page title.
      default: title,
      template: `%s — ${companyName}`,
    },
    description,
    applicationName: companyName,
    keywords: resolved.seo?.meta_keywords?.split(',').map((keyword) => keyword.trim()),

    // A staging environment sets `indexable` to false in the admin panel and
    // is excluded from search results without a code change.
    robots: resolved.seo?.indexable
      ? { index: true, follow: true }
      : { index: false, follow: false },

    icons: favicon ? { icon: [{ url: favicon }], shortcut: [{ url: favicon }] } : undefined,

    openGraph: {
      type: 'website',
      siteName: companyName,
      title,
      description,
      images: ogImage ? [{ url: ogImage, width: 1200, height: 630 }] : undefined,
    },

    twitter: {
      card: ogImage ? 'summary_large_image' : 'summary',
      title,
      description,
      images: ogImage ? [ogImage] : undefined,
    },
  };
}

export async function generateViewport(): Promise<Viewport> {
  const { settings } = await fetchPublicSettings();

  return {
    width: 'device-width',
    initialScale: 1,
    themeColor: withDefaults(settings).theme?.primary_color ?? '#2563eb',
  };
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  const { settings, version, isFallback } = await fetchPublicSettings();
  const resolved = withDefaults(settings);

  // Admin colours are converted to HSL triples and written as CSS custom
  // properties. Tailwind utilities read them via hsl(var(--primary)), so the
  // whole palette changes without a rebuild.
  const themeCss = buildThemeCss(resolved.theme);

  const locale = resolved.general?.locale ?? 'en';

  return (
    <html lang={locale} suppressHydrationWarning>
      <head>
        {/*
          Safe despite dangerouslySetInnerHTML: buildThemeCss emits only HSL
          triples reconstructed from parsed integers, plus a radius and font
          family that are each validated against a strict allowlist pattern.
          No admin input reaches the stylesheet verbatim.
        */}
        {themeCss ? (
          <style id="brand-theme" dangerouslySetInnerHTML={{ __html: themeCss }} />
        ) : null}
      </head>
      <body className="min-h-screen antialiased">
        <QueryProvider>
          <SettingsProvider settings={resolved} version={version} isFallback={isFallback}>
            <div className="flex min-h-screen flex-col">
              <SiteHeader />
              <main className="flex-1">{children}</main>
              <SiteFooter />
            </div>
          </SettingsProvider>
        </QueryProvider>
      </body>
    </html>
  );
}
