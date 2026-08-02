import { cache } from 'react';

import { fetchPublicSettings, withDefaults } from '../api';
import { buildStoreConfig, type StoreConfig } from './store-config';

/**
 * Server-side entry point for the store configuration.
 *
 * Wrapped in React's `cache()` so the root layout's three consumers —
 * generateMetadata, generateViewport, and the layout body — share one fetch per
 * request instead of issuing three. The underlying fetch is separately cached
 * across requests by tag (see `fetchPublicSettings`); this deduplicates within
 * a single render pass.
 *
 * Server components only — it performs a server-side fetch. Client components
 * read the same data through `useStoreConfig()`, which is fed by the provider
 * in the root layout.
 */
export const getStoreConfig = cache(
  async (): Promise<{ config: StoreConfig; version: string; isFallback: boolean }> => {
    const { settings, version, isFallback } = await fetchPublicSettings();

    return {
      config: buildStoreConfig(withDefaults(settings)),
      version,
      isFallback,
    };
  },
);
