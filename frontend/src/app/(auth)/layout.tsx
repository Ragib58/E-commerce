import type { ReactNode } from 'react';

/**
 * Layout for the customer auth screens.
 *
 * Centres the card vertically. The root layout still supplies the header,
 * footer, and dynamic branding, so these pages stay visually part of the
 * storefront rather than looking like a detached system page.
 */
export default function AuthLayout({ children }: { children: ReactNode }) {
  return <div className="flex min-h-[70vh] items-center justify-center">{children}</div>;
}
