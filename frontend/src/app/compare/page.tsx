import type { Metadata } from 'next';

import { getStoreConfig } from '@/features/settings/lib/get-store-config';
import { CompareView } from '@/features/shopping/components/compare-view';

/**
 * Side-by-side product comparison.
 *
 * The tray is entirely client-side — see the compare store for why comparison
 * is not worth persisting server-side — so this is a shell around a client
 * view, like the wishlist and cart pages.
 */

export async function generateMetadata(): Promise<Metadata> {
  const { config } = await getStoreConfig();

  return {
    title: 'Compare products',
    description: `Compare products side by side at ${config.companyName}.`,
    robots: { index: false, follow: false },
  };
}

export default function ComparePage() {
  return <CompareView />;
}
