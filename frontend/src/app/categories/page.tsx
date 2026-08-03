import type { Metadata } from 'next';
import Link from 'next/link';
import Image from 'next/image';
import { fetchCategories } from '@/features/catalog/api';
import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import type { Category } from '@/features/catalog/types';

/**
 * Category listing.
 *
 * Renders the whole published tree. The nesting is unbounded, so the branch
 * renderer below recurses rather than assuming a fixed depth.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: `Categories — ${config.companyName}`,
    description: `Browse product categories at ${config.companyName}.`,
    robots: { index: config.indexable, follow: config.indexable },
  };
}

export default async function CategoriesPage() {
  const [categories, { config }] = await Promise.all([fetchCategories(), getStoreConfig()]);

  return (
    <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6">
      <header className="mb-8">
        <nav aria-label="Breadcrumb" className="mb-2 text-sm text-muted-foreground">
          <Link href="/" className="hover:text-foreground">
            Home
          </Link>
          <span className="mx-2">/</span>
          <span className="text-foreground">Categories</span>
        </nav>

        <h1 className="text-3xl font-semibold tracking-tight">Shop by category</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Browse the full range at {config.companyName}.
        </p>
      </header>

      {categories.length === 0 ? (
        <div className="rounded-lg border border-dashed border-border py-16 text-center">
          <p className="text-sm text-muted-foreground">No categories have been published yet.</p>
        </div>
      ) : (
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {categories.map((category) => (
            <CategoryBranch key={category.id} category={category} />
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * A top-level category and its descendants.
 *
 * Subcategories are rendered as links rather than nested cards: past two
 * levels, a visual hierarchy becomes harder to scan than a flat list, and the
 * tree here can be arbitrarily deep.
 */
function CategoryBranch({ category }: { category: Category }) {
  return (
    <section className="overflow-hidden rounded-lg border border-border bg-card">
      <Link
        href={`/categories/${category.slug}`}
        className="block focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      >
        <div className="relative aspect-[16/9] bg-muted">
          {category.image ? (
            <Image
              src={category.image}
              alt={category.name}
              fill
              sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw"
              className="object-cover"
            />
          ) : null}
        </div>

        <div className="p-4">
          <h2 className="font-medium">{category.name}</h2>
          {category.description ? (
            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
              {category.description}
            </p>
          ) : null}
        </div>
      </Link>

      {category.children && category.children.length > 0 ? (
        <ul className="border-t border-border px-4 py-3 text-sm">
          {flattenDescendants(category).map((child) => (
            <li key={child.id}>
              <Link
                href={`/categories/${child.slug}`}
                className="block py-1 text-muted-foreground transition-colors hover:text-foreground"
                // Indent by depth so the hierarchy stays legible in a flat list.
                style={{ paddingLeft: `${(child.depth - category.depth - 1) * 12}px` }}
              >
                {child.name}
              </Link>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

/**
 * Every descendant of a category, depth-first.
 *
 * Recursive because the tree's depth is unbounded — an iteration over
 * `children` alone would silently drop the third level and below.
 */
function flattenDescendants(category: Category): Category[] {
  const result: Category[] = [];

  for (const child of category.children ?? []) {
    result.push(child);
    result.push(...flattenDescendants(child));
  }

  return result;
}
