'use client';

import { createContext, useContext, type ReactNode } from 'react';

import type { StoreConfig } from '@/features/settings/lib/store-config';

/**
 * Makes the resolved StoreConfig available to client components.
 *
 * The config is built once in the root layout (a server component) and passed
 * down, rather than refetched in the browser. That keeps branding in the
 * initial HTML — correct for SEO — and avoids a flash of unbranded content.
 *
 * Client components read branding from here; server components can call
 * `getStoreConfig()` directly instead.
 */

interface StoreConfigContextValue {
  config: StoreConfig;
  /** Version stamp of the settings payload this config was built from. */
  version: string;
  /** True when the API was unreachable and neutral fallbacks are in use. */
  isFallback: boolean;
}

const StoreConfigContext = createContext<StoreConfigContextValue | null>(null);

export function StoreConfigProvider({
  children,
  ...value
}: StoreConfigContextValue & { children: ReactNode }) {
  return <StoreConfigContext.Provider value={value}>{children}</StoreConfigContext.Provider>;
}

/**
 * Read the store configuration.
 *
 * Throws rather than falling back to a default when used outside the provider:
 * a silent default would render an unbranded component that looks like a
 * styling bug instead of the wiring mistake it actually is.
 */
export function useStoreConfig(): StoreConfig {
  const context = useContext(StoreConfigContext);

  if (context === null) {
    throw new Error('useStoreConfig must be used within a <StoreConfigProvider>.');
  }

  return context.config;
}

/** Config plus the payload metadata, for components that surface staleness. */
export function useStoreConfigState(): StoreConfigContextValue {
  const context = useContext(StoreConfigContext);

  if (context === null) {
    throw new Error('useStoreConfigState must be used within a <StoreConfigProvider>.');
  }

  return context;
}
