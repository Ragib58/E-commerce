import Image from 'next/image';
import Link from 'next/link';

import { cn } from '@/lib/utils/cn';
import type { Section, SectionCategory } from '../../types';
import { SectionShell } from '../section-shell';
import { gridColumnsClass, settingBoolean, settingNumber } from '../../lib/settings';

/**
 * A grid of category tiles.
 *
 * Server-rendered static markup — no JavaScript ships for this section.
 */

interface CategoryGridProps {
  section: Section;
  categories: SectionCategory[];
}

export function CategoryGrid({ section, categories }: CategoryGridProps) {
  const columns = settingNumber(section.settings, 'columns', 4, 1, 6);
  const showCount = settingBoolean(section.settings, 'show_product_count', true);

  if (categories.length === 0) return null;

  return (
    <SectionShell section={section}>
      <div className={cn('grid gap-4', gridColumnsClass(columns))}>
        {categories.map((category) => (
          <Link
            key={category.id}
            href={`/categories/${category.slug}`}
            className="group relative flex flex-col overflow-hidden rounded-lg border border-border bg-card transition-shadow hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          >
            <div className="relative aspect-[4/3] overflow-hidden bg-muted">
              {category.image ? (
                <Image
                  src={category.image}
                  alt=""
                  fill
                  // Matches the responsive column counts in gridColumnsClass,
                  // so a phone fetches a phone-sized crop.
                  sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
                  loading="lazy"
                  className="object-cover transition-transform duration-300 group-hover:scale-105"
                />
              ) : (
                <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                  {category.name}
                </div>
              )}
            </div>

            <div className="p-3">
              <h3 className="text-sm font-medium leading-snug">{category.name}</h3>

              {showCount && typeof category.products_count === 'number' ? (
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {category.products_count}{' '}
                  {category.products_count === 1 ? 'product' : 'products'}
                </p>
              ) : null}
            </div>
          </Link>
        ))}
      </div>
    </SectionShell>
  );
}
