import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { fetchProduct } from '@/features/catalog/api';
import { ProductDetail } from '@/features/catalog/components/product-detail';
import { ProductGrid } from '@/features/catalog/components/product-card';
import { RecentlyViewedRail } from '@/features/shopping/components/recently-viewed-rail';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';

/**
 * Product detail page.
 *
 * Rendered per request rather than statically: the page shows stock, and a
 * build-time snapshot would advertise availability for something that has since
 * sold out — producing a failed checkout.
 */
export const dynamic = 'force-dynamic';

interface PageProps {
  params: Promise<{ slug: string }>;
}

export async function generateMetadata({ params }: PageProps): Promise<Metadata> {
  const { slug } = await params;

  const [detail, { config }] = await Promise.all([fetchProduct(slug), getStoreConfig()]);

  if (!detail) {
    return { title: 'Product not found' };
  }

  const { product } = detail;
  const title = product.seo?.meta_title ?? product.name;
  const description = product.seo?.meta_description ?? product.short_description ?? undefined;
  const image = product.seo?.og_image ?? product.thumbnail ?? config.ogImage;

  return {
    title: `${title} — ${config.companyName}`,
    description,
    robots: { index: config.indexable, follow: config.indexable },
    openGraph: {
      title,
      description,
      type: 'website',
      images: image ? [{ url: image }] : undefined,
    },
  };
}

export default async function ProductPage({ params }: PageProps) {
  const { slug } = await params;

  const [detail, { config }] = await Promise.all([fetchProduct(slug), getStoreConfig()]);

  // The API returns 404 for both a missing product and an unpublished one, so
  // this covers both without distinguishing them to the visitor.
  if (!detail) {
    notFound();
  }

  const { product, related, breadcrumbs } = detail;

  /**
   * Product structured data.
   *
   * Lets search engines render price and availability in results. Built from
   * the same values the page displays, so the two cannot disagree — a mismatch
   * is what gets a site penalised.
   */
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.name,
    description: product.short_description ?? undefined,
    sku: product.sku,
    brand: product.brand ? { '@type': 'Brand', name: product.brand.name } : undefined,
    image: product.thumbnail ? [product.thumbnail] : undefined,
    offers: {
      '@type': 'Offer',
      // Schema.org expects a decimal string in major units.
      price: (product.pricing.effective_price / 100).toFixed(2),
      priceCurrency: config.business.currency,
      availability: product.inventory.in_stock
        ? 'https://schema.org/InStock'
        : 'https://schema.org/OutOfStock',
    },
  };

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <script
        type="application/ld+json"
        // The payload is built from validated API data, not from user input.
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />

      <nav aria-label="Breadcrumb" className="mb-6 text-sm text-muted-foreground">
        <Link href="/" className="hover:text-foreground">
          Home
        </Link>
        <span className="mx-2">/</span>
        <Link href="/products" className="hover:text-foreground">
          Shop
        </Link>
        {breadcrumbs.map((crumb) => (
          <span key={crumb.slug}>
            <span className="mx-2">/</span>
            <Link href={`/categories/${crumb.slug}`} className="hover:text-foreground">
              {crumb.name}
            </Link>
          </span>
        ))}
        <span className="mx-2">/</span>
        <span className="text-foreground">{product.name}</span>
      </nav>

      <ProductDetail product={product} config={config} />

      {related.length > 0 ? (
        <section aria-labelledby="related-heading" className="mt-16">
          <h2 id="related-heading" className="mb-6 text-xl font-semibold tracking-tight">
            You may also like
          </h2>
          <ProductGrid products={related} config={config} showQuickAdd={false} />
        </section>
      ) : null}

      {/*
        Per-device browsing history, so necessarily a client component — the
        server cannot know what this browser has seen. It renders nothing at all
        when there is no history, so it costs a first-time visitor no layout.
        The current product is excluded: a rail led by the page you are on is
        noise.
      */}
      <div className="mt-16">
        <RecentlyViewedRail exclude={product.id} />
      </div>
    </div>
  );
}
