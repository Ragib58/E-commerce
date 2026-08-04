import type { Metadata } from 'next';
import Image from 'next/image';
import { notFound } from 'next/navigation';

import { fetchPage, fetchPages } from '@/features/content/api';
import { RichText } from '@/features/content/components/rich-text';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';

/**
 * A CMS page — About, Contact, a policy, or anything an operator has added.
 *
 * Mounted under `/p/` rather than at the root.
 *
 * A root-level `[slug]` route would sit at the same level as every other
 * storefront route and would be matched for any unrecognised path, so a page
 * slugged "products" or "checkout" could shadow real functionality — and
 * diagnosing that costs far more than one path segment. The `/p/` prefix makes
 * the namespace explicit and collision-free; the API additionally refuses to
 * mint a page on a reserved slug.
 */

interface PageProps {
  params: Promise<{ slug: string }>;
}

export const revalidate = 300;

/**
 * Pre-render the published pages at build time.
 *
 * These are the pages linked from the footer of every other page, so they are
 * worth having ready. `dynamicParams` stays at its default of true, so a page
 * created after the build is still served — it renders on first request and is
 * cached from then on.
 */
export async function generateStaticParams() {
  const pages = await fetchPages();

  return pages.map((page) => ({ slug: page.slug }));
}

/**
 * Per-page SEO, entirely from the CMS record.
 *
 * Every field falls back sensibly rather than being omitted: a page with no
 * SEO title still gets its own title, and one with no social image inherits the
 * store's. An empty tag is worse than a derived one — search engines invent
 * their own snippet when a description is missing.
 */
export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;
  const [page, { config }] = await Promise.all([fetchPage(slug), getStoreConfig()]);

  if (!page) {
    // A 404 must not be indexed under a title implying the page exists.
    return { title: 'Page not found', robots: { index: false, follow: false } };
  }

  const title = page.seo?.title || page.title;
  const description = page.seo?.description || page.excerpt || config.metaDescription;
  const image = page.seo?.og_image || page.featured_image || config.ogImage;

  return {
    title,
    description,
    keywords: page.seo?.keywords || undefined,
    alternates: { canonical: `/p/${page.slug}` },

    /*
     * Two independent conditions must both hold to be indexable: the page's own
     * flag, and the store-wide one. A staging environment sets the latter to
     * false and is excluded wholesale, regardless of what any page says.
     */
    robots:
      page.seo?.indexable !== false && config.indexable
        ? { index: true, follow: true }
        : { index: false, follow: false },

    openGraph: {
      type: 'article',
      url: `/p/${page.slug}`,
      siteName: config.companyName,
      title,
      description: description ?? undefined,
      images: image ? [{ url: image, width: 1200, height: 630 }] : undefined,
      publishedTime: page.published_at ?? undefined,
      modifiedTime: page.updated_at ?? undefined,
    },

    twitter: {
      card: image ? 'summary_large_image' : 'summary',
      title,
      description: description ?? undefined,
      images: image ? [image] : undefined,
    },
  };
}

export default async function CmsPageRoute({ params }: PageProps) {
  const { slug } = await params;
  const page = await fetchPage(slug);

  if (!page) notFound();

  return (
    <article className="mx-auto w-full max-w-3xl px-4 py-12 sm:px-6 sm:py-16">
      <header>
        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{page.title}</h1>

        {page.excerpt ? (
          <p className="mt-3 text-lg text-muted-foreground">{page.excerpt}</p>
        ) : null}

        {page.published_at ? (
          <p className="mt-4 text-sm text-muted-foreground">
            {/*
              A machine-readable date beside the human one, and the *published*
              date rather than the last edit: a policy page's effective date is
              what a reader needs, and it must not move because someone fixed a
              typo.
            */}
            Last updated{' '}
            <time dateTime={page.published_at}>
              {new Date(page.published_at).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
              })}
            </time>
          </p>
        ) : null}
      </header>

      {page.featured_image ? (
        <div className="relative mt-8 aspect-[16/9] overflow-hidden rounded-lg bg-muted">
          <Image
            src={page.featured_image}
            alt=""
            fill
            sizes="(max-width: 768px) 100vw, 768px"
            // The hero of the page a visitor navigated to, so it is the likely
            // largest contentful paint and is fetched eagerly.
            priority
            className="object-cover"
          />
        </div>
      ) : null}

      <div className="mt-8">
        <RichText html={page.content} />
      </div>
    </article>
  );
}
