import type { Metadata, Viewport } from 'next';
import type { ReactNode } from 'react';

import './globals.css';

import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import { AnalyticsScripts } from '@/features/settings/components/analytics-scripts';
import { buildThemeCss } from '@/lib/utils/theme';
import { QueryProvider } from '@/components/providers/query-provider';
import { StoreConfigProvider } from '@/components/providers/store-config-provider';
import { SiteHeader } from '@/components/layout/site-header';
import { SiteFooter } from '@/components/layout/site-footer';

/**
 * Root layout.
 *
 * A server component, deliberately: it resolves the admin-managed store
 * configuration and renders the company name, favicon, and brand colours into
 * the initial HTML. Doing this on the client instead would produce a visible
 * flash of the default theme and leave the real title invisible to crawlers.
 *
 * Nothing here is hardcoded — the title, description, icons, social image,
 * every colour, and the analytics tags all originate in the Laravel admin
 * panel. `getStoreConfig` is request-deduplicated, so the three consumers below
 * share a single fetch.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: {
      // Child pages supply only their own name; the website title is appended
      // from settings so renaming the store updates every page title.
      default: config.metaTitle,
      template: `%s — ${config.websiteTitle}`,
    },
    description: config.metaDescription,
    applicationName: config.companyName,
    keywords: config.metaKeywords.length > 0 ? config.metaKeywords : undefined,

    // A staging environment sets `indexable` to false in the admin panel and is
    // excluded from search results without a code change.
    robots: config.indexable ? { index: true, follow: true } : { index: false, follow: false },

    // The favicon is an admin upload, so it is declared here rather than
    // shipped as a static app/icon file.
    icons: config.favicon
      ? { icon: [{ url: config.favicon }], shortcut: [{ url: config.favicon }], apple: [{ url: config.favicon }] }
      : undefined,

    openGraph: {
      type: 'website',
      siteName: config.companyName,
      title: config.metaTitle,
      description: config.metaDescription,
      images: config.ogImage ? [{ url: config.ogImage, width: 1200, height: 630 }] : undefined,
    },

    twitter: {
      card: config.ogImage ? 'summary_large_image' : 'summary',
      title: config.metaTitle,
      description: config.metaDescription,
      images: config.ogImage ? [config.ogImage] : undefined,
    },
  };
}

export async function generateViewport(): Promise<Viewport> {
  const { config } = await getStoreConfig();

  return {
    width: 'device-width',
    initialScale: 1,
    // Omitted rather than defaulted when unset: an arbitrary browser-chrome
    // colour would be a hardcoded brand decision.
    themeColor: config.colors.primary ?? undefined,
  };
}

export default async function RootLayout({ children }: { children: ReactNode }) {
  const { config, version, isFallback } = await getStoreConfig();

  // Admin colours are converted to HSL triples and written as CSS custom
  // properties. Tailwind utilities read them via hsl(var(--primary)), so the
  // whole palette changes without a rebuild.
  const themeCss = buildThemeCss({
    primary_color: config.colors.primary,
    secondary_color: config.colors.secondary,
    accent_color: config.colors.accent,
    background_color: config.colors.background,
    foreground_color: config.colors.text,
    button_color: config.colors.button,
    destructive_color: config.colors.destructive,
    radius: config.radius,
    font_family: config.fontFamily,
  });

  return (
    <html lang={config.locale} suppressHydrationWarning>
      <head>
        {/*
          Safe despite dangerouslySetInnerHTML: buildThemeCss emits only HSL
          triples and hex values reconstructed from parsed integers, plus a
          radius and font family that are each validated against a strict
          allowlist pattern. No admin input reaches the stylesheet verbatim.
        */}
        {themeCss ? (
          <style id="brand-theme" dangerouslySetInnerHTML={{ __html: themeCss }} />
        ) : null}
      </head>
      <body className="min-h-screen antialiased">
        <QueryProvider>
          <StoreConfigProvider config={config} version={version} isFallback={isFallback}>
            <div className="flex min-h-screen flex-col">
              <SiteHeader />
              <main className="flex-1">{children}</main>
              <SiteFooter />
            </div>
          </StoreConfigProvider>
        </QueryProvider>

        {/* Rendered last and loaded `afterInteractive`, so measurement tags
            never sit on the critical path to first paint. */}
        <AnalyticsScripts config={config} />
      </body>
    </html>
  );
}
