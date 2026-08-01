'use client';

import { createContext, useContext, type ReactNode } from 'react';
import type { PublicSettings } from '@/features/settings/types';

/**
 * Makes the server-fetched settings payload available to client components.
 *
 * The value is fetched once in the root layout (a server component) and passed
 * down, rather than refetched in the browser. That keeps branding in the
 * initial HTML — correct for SEO, and it avoids a flash of unbranded content.
 */

interface SettingsContextValue {
  settings: PublicSettings;
  version: string;
  isFallback: boolean;
}

const SettingsContext = createContext<SettingsContextValue | null>(null);

export function SettingsProvider({
  children,
  ...value
}: SettingsContextValue & { children: ReactNode }) {
  return <SettingsContext.Provider value={value}>{children}</SettingsContext.Provider>;
}

/**
 * Read the storefront settings.
 *
 * Throws rather than returning a default when used outside the provider: a
 * silent fallback would render an unbranded component that looks like a
 * styling bug instead of the wiring mistake it actually is.
 */
export function useSettings(): SettingsContextValue {
  const context = useContext(SettingsContext);

  if (context === null) {
    throw new Error('useSettings must be used within a <SettingsProvider>.');
  }

  return context;
}

/** Convenience accessor for a single settings group. */
export function useSettingsGroup<TGroup extends keyof PublicSettings>(
  group: TGroup,
): PublicSettings[TGroup] {
  return useSettings().settings[group];
}
