import type { Metadata } from 'next';

import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import { WishlistView } from '@/features/shopping/components/wishlist-view';

/**
 * Saved products.
 *
 * A client view behind a server shell. The list is per-visitor and lives either
 * in localStorage (guests) or behind an authenticated request (customers) — the
 * server can render neither, so there is nothing to prerender beyond the
 * chrome and the admin-managed metadata.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: 'Your wishlist',
    description: `Products you have saved at ${config.companyName}.`,
    // Personal and per-device; there is nothing here for a search engine.
    robots: { index: false, follow: false },
  };
}

export default function WishlistPage() {
  return <WishlistView />;
}
