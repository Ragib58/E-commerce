import type { Metadata } from 'next';

import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import { CartView } from '@/features/cart/components/cart-view';

/**
 * The cart page.
 *
 * A thin server shell around a client view. The cart is per-visitor and
 * mutates constantly, so there is nothing to render on the server and nothing
 * to cache — but the shell still resolves the store config for the title, so
 * the page's metadata is admin-managed like every other page's.
 *
 * Deliberately not indexed: a cart URL has no meaning to anyone but its owner,
 * and indexing it would put an empty basket in search results.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: 'Your cart',
    description: `Review the items in your ${config.companyName} basket.`,
    robots: { index: false, follow: false },
  };
}

export default function CartPage() {
  return <CartView />;
}
