import Link from 'next/link';
import { ArrowRight } from 'lucide-react';

import { ProductCard } from '@/features/catalog/components/product-card';
import type { Product } from '@/features/catalog/types';
import type { StoreConfig } from '@/features/settings/lib/store-config';
import { cn } from '@/lib/utils/cn';
import type { Section } from '../../types';
import { SectionShell } from '../section-shell';
import { gridColumnsClass, settingBoolean, settingNumber } from '../../lib/settings';

/**
 * A grid of products.
 *
 * Serves four section types — featured, new arrivals, best sellers, and
 * hand-picked collections — because they differ only in how the backend
 * *selects* the products, never in how they are drawn. One renderer means a
 * change to card spacing or the "View all" affordance lands in all four.
 *
 * `ProductCard` is reused verbatim from the catalog feature rather than
 * reimplemented: the homepage and a category page must not disagree about how
 * a discount badge or an out-of-stock overlay looks.
 */

interface ProductRailProps {
  section: Section;
  products: Product[];
  config: StoreConfig;
  /** Where "View all" points. Omitted for hand-picked collections. */
  viewAllHref?: string;
  /**
   * True only for the topmost section of the page — its first row of images is
   * the likely largest contentful paint and should not lazy-load.
   */
  isAboveFold?: boolean;
}

export function ProductRail({
  section,
  products,
  config,
  viewAllHref,
  isAboveFold = false,
}: ProductRailProps) {
  const columns = settingNumber(section.settings, 'columns', 4, 1, 6);
  const showViewAll = settingBoolean(section.settings, 'show_view_all', true);

  if (products.length === 0) return null;

  return (
    <SectionShell
      section={section}
      action={
        showViewAll && viewAllHref ? (
          <Link
            href={viewAllHref}
            className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            View all
            <ArrowRight className="size-4" aria-hidden="true" />
          </Link>
        ) : null
      }
    >
      <div className={cn('grid gap-4', gridColumnsClass(columns))}>
        {products.map((product, index) => (
          <ProductCard
            key={product.id}
            product={product}
            config={config}
            // Only the first row of the first section. Marking more than that
            // as priority makes every one of them compete and defeats the
            // purpose of the hint.
            priority={isAboveFold && index < columns}
          />
        ))}
      </div>
    </SectionShell>
  );
}
