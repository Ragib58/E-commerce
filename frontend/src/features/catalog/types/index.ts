import { z } from 'zod';

/**
 * Schemas for the catalog API.
 *
 * Parsed at the boundary rather than trusted: the Laravel API is a separate
 * deployable that can change independently, and a silently-renamed field would
 * otherwise surface as an empty grid or a blank price far from its cause.
 *
 * Money is an integer count of minor units (cents) end to end, exactly as the
 * API stores it. Nothing here converts to a float — see `formatMinorUnits` in
 * ../lib/format, which is the only place the conversion happens.
 */

export const productStatusSchema = z.enum(['draft', 'published', 'archived']);

export const productTypeSchema = z.enum(['simple', 'variable', 'digital', 'customizable']);

/**
 * How the storefront renders an attribute's chooser.
 *
 * Supplied by the API per attribute, so adding "Material" from the admin panel
 * renders a working control with no frontend change.
 */
export const displayTypeSchema = z.enum(['button', 'swatch', 'dropdown', 'radio']);

export const seoSchema = z.object({
  meta_title: z.string().nullish(),
  meta_description: z.string().nullish(),
  og_image: z.string().nullish(),
});

/**
 * Typed as ZodType<Category, ZodTypeDef, unknown> rather than
 * ZodType<Category>: the single-argument form requires the *input* type to
 * match the output, but fields with `.default()` are optional on input and
 * guaranteed on output. `unknown` is also the honest input type here — this
 * parses an untrusted API response.
 */
export const categorySchema: z.ZodType<Category, z.ZodTypeDef, unknown> = z.lazy(() =>
  z.object({
    id: z.number(),
    name: z.string(),
    slug: z.string(),
    description: z.string().nullish(),
    image: z.string().nullish(),
    banner: z.string().nullish(),
    parent_id: z.number().nullish(),
    depth: z.number().default(0),
    sort_order: z.number().default(0),
    status: productStatusSchema,
    seo: seoSchema.optional(),
    // Recursive: nesting is unlimited, so the type must be too.
    children: z.array(categorySchema).optional(),
    products_count: z.number().optional(),
  }),
);

/**
 * Declared explicitly because the schema is recursive — z.lazy cannot infer a
 * type that refers to itself.
 */
export interface Category {
  id: number;
  name: string;
  slug: string;
  description?: string | null;
  image?: string | null;
  banner?: string | null;
  parent_id?: number | null;
  depth: number;
  sort_order: number;
  status: z.infer<typeof productStatusSchema>;
  seo?: z.infer<typeof seoSchema>;
  children?: Category[];
  products_count?: number;
}

export const brandSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
  logo: z.string().nullish(),
  description: z.string().nullish(),
  status: productStatusSchema,
  sort_order: z.number().default(0),
  seo: seoSchema.optional(),
  products_count: z.number().optional(),
});

export const productMediaSchema = z.object({
  id: z.number(),
  type: z.enum(['image', 'video']),
  url: z.string().nullish(),
  alt_text: z.string().nullish(),
  is_thumbnail: z.boolean().default(false),
  sort_order: z.number().default(0),
  /** Set when the image belongs to one variant, so selecting it swaps the gallery. */
  variant_id: z.string().nullish(),
});

export const variantOptionSchema = z.object({
  attribute: z.string().nullish(),
  attribute_name: z.string().nullish(),
  display_type: displayTypeSchema.catch('button'),
  value: z.string(),
  slug: z.string(),
  colour_code: z.string().nullish(),
});

/**
 * Prices are already resolved by the API, with variant-to-product inheritance
 * applied. The frontend must never re-derive them: doing so would require
 * knowing that a null variant price means "inherit", and a picker that showed a
 * blank price for non-overriding variants is a bug that appears only for some
 * products.
 */
export const pricingSchema = z.object({
  price: z.number(),
  discount_price: z.number().nullish(),
  effective_price: z.number(),
  is_on_sale: z.boolean().optional(),
  discount_percentage: z.number().nullish(),
  is_taxable: z.boolean().optional(),
  tax_rate: z.number().nullish(),
  /** Admin-only. Absent on every public response. */
  cost_price: z.number().nullish(),
  /** Admin-only: the raw column, so a form can tell "inherits" from an override. */
  own_price: z.number().nullish(),
});

export const inventorySchema = z.object({
  in_stock: z.boolean(),
  low_stock: z.boolean().optional(),
  allow_backorder: z.boolean().optional(),
  /** Admin-only. The public API sends only the booleans above. */
  stock: z.number().nullish(),
  low_stock_threshold: z.number().nullish(),
});

export const productVariantSchema = z.object({
  id: z.string(),
  sku: z.string(),
  name: z.string().nullish(),
  pricing: pricingSchema,
  inventory: inventorySchema,
  image: z.string().nullish(),
  weight: z.number().nullish(),
  options: z.array(variantOptionSchema).default([]),
  is_default: z.boolean().default(false),
  sort_order: z.number().default(0),
  is_active: z.boolean().optional(),
});

export const productSchema = z.object({
  /** The public uuid, never the integer primary key. */
  id: z.string(),
  name: z.string(),
  slug: z.string(),
  sku: z.string(),
  type: productTypeSchema,
  status: productStatusSchema,
  short_description: z.string().nullish(),
  description: z.string().nullish(),
  pricing: pricingSchema,
  inventory: inventorySchema,
  shipping: z
    .object({
      weight: z.number().nullish(),
      dimensions: z
        .object({
          length: z.number().nullish(),
          width: z.number().nullish(),
          height: z.number().nullish(),
        })
        .optional(),
    })
    .optional(),
  flags: z
    .object({
      is_featured: z.boolean().default(false),
      is_new_arrival: z.boolean().default(false),
      is_best_seller: z.boolean().default(false),
    })
    .optional(),
  category: categorySchema.nullish(),
  brand: brandSchema.nullish(),
  media: z.array(productMediaSchema).optional(),
  thumbnail: z.string().nullish(),
  video_url: z.string().nullish(),
  variants: z.array(productVariantSchema).optional(),
  /** Admin-only: includes inactive variants, so one can be re-enabled. */
  all_variants: z.array(productVariantSchema).optional(),
  seo: seoSchema.optional(),
  published_at: z.string().nullish(),
  created_at: z.string().nullish(),
  updated_at: z.string().nullish(),
});

export const attributeSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
  display_type: displayTypeSchema.catch('button'),
  is_filterable: z.boolean().default(true),
  sort_order: z.number().default(0),
  values: z
    .array(
      z.object({
        id: z.number(),
        value: z.string(),
        slug: z.string(),
        colour_code: z.string().nullish(),
        sort_order: z.number().default(0),
      }),
    )
    .default([]),
});

export const priceRangeSchema = z.object({
  min: z.number(),
  max: z.number(),
});

export const catalogFiltersSchema = z.object({
  attributes: z.array(attributeSchema).default([]),
  brands: z.array(brandSchema).default([]),
  price_range: priceRangeSchema,
  sorts: z.array(z.string()).default([]),
});

export const breadcrumbSchema = z.object({
  name: z.string(),
  slug: z.string(),
});

export const stockMovementSchema = z.object({
  id: z.number(),
  type: z.enum(['increase', 'decrease', 'adjustment']),
  type_label: z.string(),
  reason: z.string(),
  reason_label: z.string(),
  quantity: z.number(),
  quantity_before: z.number(),
  quantity_after: z.number(),
  product: z
    .object({
      id: z.string(),
      name: z.string(),
      sku: z.string(),
      slug: z.string(),
    })
    .nullish(),
  variant: z
    .object({
      id: z.string(),
      name: z.string().nullish(),
      sku: z.string(),
    })
    .nullish(),
  recorded_by: z
    .object({
      id: z.string(),
      name: z.string(),
    })
    .nullish(),
  note: z.string().nullish(),
  created_at: z.string().nullish(),
});

export const inventorySummarySchema = z.object({
  tracked_products: z.number(),
  low_stock: z.number(),
  out_of_stock: z.number(),
  stock_on_hand: z.number(),
  /** Valued at cost, in minor units. */
  stock_value: z.number(),
});

export type Brand = z.infer<typeof brandSchema>;
export type Product = z.infer<typeof productSchema>;
export type ProductVariant = z.infer<typeof productVariantSchema>;
export type ProductMedia = z.infer<typeof productMediaSchema>;
export type VariantOption = z.infer<typeof variantOptionSchema>;
export type Attribute = z.infer<typeof attributeSchema>;
export type CatalogFilters = z.infer<typeof catalogFiltersSchema>;
export type PriceRange = z.infer<typeof priceRangeSchema>;
export type Breadcrumb = z.infer<typeof breadcrumbSchema>;
export type StockMovement = z.infer<typeof stockMovementSchema>;
export type InventorySummary = z.infer<typeof inventorySummarySchema>;
export type ProductStatus = z.infer<typeof productStatusSchema>;
export type ProductType = z.infer<typeof productTypeSchema>;
export type DisplayType = z.infer<typeof displayTypeSchema>;

/** Query parameters accepted by the public product listing. */
export interface ProductListParams {
  search?: string;
  category?: string;
  brand?: string[];
  type?: ProductType;
  min_price?: number;
  max_price?: number;
  /** Faceted selections, keyed by attribute slug. */
  attributes?: Record<string, string[]>;
  featured?: boolean;
  new_arrival?: boolean;
  best_seller?: boolean;
  in_stock?: boolean;
  sort?: string;
  page?: number;
  per_page?: number;
}
