import type { StoreConfig } from '@/features/settings/lib/store-config';
import type { Section } from '../types';
import {
  sectionBannersSchema,
  sectionCategoriesSchema,
  sectionProductsSchema,
  sectionTestimonialsSchema,
} from '../types';
import { HeroSlider } from './sections/hero-slider';
import { PromoBanners } from './sections/promo-banners';
import { ProductRail } from './sections/product-rail';
import { FlashSale } from './sections/flash-sale';
import { CategoryGrid } from './sections/category-grid';
import { Testimonials } from './sections/testimonials';
import { CustomContent } from './sections/custom-content';

/**
 * Maps one API section onto its component.
 *
 * This is the whole of the homepage's structural logic. The page itself does
 * not know what a hero or a product rail is — it maps over whatever the API
 * returned and hands each entry here, which is what makes the homepage's
 * composition editable data rather than code.
 *
 * Two rules the dispatch enforces:
 *
 *   - **An unknown type renders nothing and breaks nothing.** The backend can
 *     ship a new section type before this build knows how to draw it; when that
 *     happens the section is skipped and the rest of the page renders normally.
 *     Throwing, or rendering a placeholder, would make a backend deploy
 *     capable of breaking the storefront.
 *   - **`items` is parsed per type, at the point of use.** The array is
 *     heterogeneous — products, banners, categories, or testimonials depending
 *     on `type` — and each schema is `.catch([])`, so a malformed entry costs
 *     that one section rather than the page.
 */

interface SectionRendererProps {
  section: Section;
  config: StoreConfig;
  /**
   * True for the first section only. Cascades into image `priority`, so exactly
   * one region of the page competes for the largest-contentful-paint hint.
   */
  isFirst?: boolean;
}

export function SectionRenderer({ section, config, isFirst = false }: SectionRendererProps) {
  switch (section.type) {
    case 'hero_slider':
      return <HeroSlider section={section} slides={sectionBannersSchema.parse(section.items)} />;

    case 'promo_banner':
      return (
        <PromoBanners section={section} banners={sectionBannersSchema.parse(section.items)} />
      );

    /*
     * Four selection strategies, one renderer. They differ in how the backend
     * chooses the products and in where "View all" points — never in how a card
     * is drawn, which is why a single component serves all of them.
     */
    case 'featured_products':
      return (
        <ProductRail
          section={section}
          products={sectionProductsSchema.parse(section.items)}
          config={config}
          viewAllHref="/products?featured=1"
          isAboveFold={isFirst}
        />
      );

    case 'new_arrivals':
      return (
        <ProductRail
          section={section}
          products={sectionProductsSchema.parse(section.items)}
          config={config}
          viewAllHref="/products?sort=newest"
          isAboveFold={isFirst}
        />
      );

    case 'best_sellers':
      return (
        <ProductRail
          section={section}
          products={sectionProductsSchema.parse(section.items)}
          config={config}
          viewAllHref="/products?best_seller=1"
          isAboveFold={isFirst}
        />
      );

    case 'product_collection':
      // No "View all": a hand-picked collection has no listing page that would
      // reproduce the same set, and a link to the full catalog would misrepresent
      // what the operator curated.
      return (
        <ProductRail
          section={section}
          products={sectionProductsSchema.parse(section.items)}
          config={config}
          isAboveFold={isFirst}
        />
      );

    case 'flash_sale':
      return (
        <FlashSale
          section={section}
          products={sectionProductsSchema.parse(section.items)}
          config={config}
        />
      );

    case 'categories':
      return (
        <CategoryGrid
          section={section}
          categories={sectionCategoriesSchema.parse(section.items)}
        />
      );

    case 'testimonials':
      return (
        <Testimonials
          section={section}
          testimonials={sectionTestimonialsSchema.parse(section.items)}
        />
      );

    case 'custom_content':
      return <CustomContent section={section} />;

    /*
     * The blog module lands in a later phase. The section type is already
     * placeable and schedulable in the admin panel, and the backend resolves it
     * to an empty list — so it renders nothing today and will begin rendering
     * when posts exist, with no change here beyond a case arm.
     */
    case 'blog_posts':
      return null;

    default:
      // An unrecognised type. See the class docblock: skipped, never thrown.
      return null;
  }
}
