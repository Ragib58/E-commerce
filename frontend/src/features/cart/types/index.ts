import { z } from 'zod';

/**
 * Schemas for the cart API.
 *
 * Note what the client never sends: a price. `AddToCartInput` below carries a
 * product, an optional variant, and a quantity — nothing else. Every monetary
 * figure in `Cart` is *received*, computed by the server from the catalog, and
 * the frontend's only job is to format it.
 *
 * That asymmetry is deliberate and worth stating: this file would be the
 * natural place for a `price` field to creep in "so the UI can show it
 * instantly", and an optimistic price displayed before the server responds is
 * exactly how a UI ends up disagreeing with the checkout total.
 *
 * Money is an integer count of minor units end to end, matching the API.
 */

/** Why a line cannot currently be purchased. */
export const cartIssueSchema = z.object({
  code: z.enum(['UNAVAILABLE', 'VARIANT_UNAVAILABLE', 'OUT_OF_STOCK', 'INSUFFICIENT_STOCK']).catch('UNAVAILABLE'),
  message: z.string(),
  /** Present on INSUFFICIENT_STOCK: how many remain. */
  available: z.number().optional(),
});

export type CartIssue = z.infer<typeof cartIssueSchema>;

export const cartItemSchema = z.object({
  id: z.number(),
  quantity: z.number(),
  options: z.record(z.string()).nullish(),

  product: z.object({
    id: z.string(),
    name: z.string(),
    slug: z.string(),
    sku: z.string().nullish(),
    thumbnail: z.string().nullish(),
    type: z.string(),
  }),

  variant: z
    .object({
      id: z.string(),
      name: z.string(),
    })
    .nullish(),

  /** Server-computed. Minor units. */
  unit_price: z.number(),
  /** The struck-through original, present only when genuinely higher. */
  list_price: z.number().nullish(),
  line_total: z.number(),
  line_discount: z.number(),

  is_taxable: z.boolean().default(false),
  is_available: z.boolean().default(true),
  /** Null means not stock-tracked, distinct from 0 meaning none left. */
  max_quantity: z.number().nullish(),
  issues: z.array(cartIssueSchema).default([]),
});

export type CartItem = z.infer<typeof cartItemSchema>;

export const cartTotalsSchema = z.object({
  subtotal: z.number().default(0),
  discount: z.number().default(0),
  tax: z.number().default(0),
  /**
   * Null until checkout. Shipping depends on a delivery address the cart does
   * not have, and a placeholder that later changes reads as a hidden cost.
   */
  shipping: z.number().nullish(),
  total: z.number().default(0),
});

export type CartTotals = z.infer<typeof cartTotalsSchema>;

export const cartCouponSchema = z.object({
  code: z.string().nullish(),
  /** Always false in this phase — promotions are a later one. */
  applied: z.boolean().default(false),
  discount: z.number().default(0),
  message: z.string().nullish(),
});

export const cartSchema = z.object({
  id: z.number().nullish(),
  items: z.array(cartItemSchema).default([]),
  /** Total units, for the header badge. */
  item_count: z.number().default(0),
  line_count: z.number().default(0),
  totals: cartTotalsSchema,
  coupon: cartCouponSchema,
  /** True when any line has an issue, so the UI can surface a banner once. */
  has_issues: z.boolean().default(false),
});

export type Cart = z.infer<typeof cartSchema>;

/**
 * The empty cart.
 *
 * Mirrors what the API returns for a shopper who has never added anything, so
 * first use and an emptied cart render through the same code path — and so a
 * failed fetch can degrade to a valid shape rather than null-guarding every
 * consumer.
 */
export const EMPTY_CART: Cart = {
  id: null,
  items: [],
  item_count: 0,
  line_count: 0,
  totals: { subtotal: 0, discount: 0, tax: 0, shipping: null, total: 0 },
  coupon: { code: null, applied: false, discount: 0, message: null },
  has_issues: false,
};

/**
 * What a client may send when adding to the cart.
 *
 * There is no price field, and there is no place to add one — the API discards
 * unknown keys, and the request class defines no monetary rule at all.
 */
export interface AddToCartInput {
  /** A product slug or uuid. */
  product: string;
  /** Required for variable products, rejected for the rest. */
  variant?: string | null;
  quantity?: number;
  /** Personalisation for customizable products. */
  options?: Record<string, string> | null;
}
