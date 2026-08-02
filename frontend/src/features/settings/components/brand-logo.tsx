'use client';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import { cn } from '@/lib/utils/cn';

/**
 * The store's logo, resolved from admin-managed settings.
 *
 * Light and dark variants are both rendered and toggled with CSS media queries
 * rather than by reading the colour scheme in JavaScript. A JS-driven choice
 * would render the wrong logo on the server and swap it after hydration, which
 * is a visible flicker on every page load.
 *
 * When no logo has been uploaded it falls back to the company name as text —
 * never to a placeholder image, and never to a hardcoded brand mark.
 */

interface BrandLogoProps {
  className?: string;
  /** Rendered height in pixels. Width scales with the asset's aspect ratio. */
  height?: number;
  /** Force a single variant, for surfaces with a fixed background. */
  variant?: 'light' | 'dark' | 'auto';
}

export function BrandLogo({ className, height = 32, variant = 'auto' }: BrandLogoProps) {
  const config = useStoreConfig();
  const { logoLight, logoDark, companyName } = config;

  const textFallback = (
    <span className={cn('text-lg font-semibold tracking-tight', className)}>{companyName}</span>
  );

  if (variant === 'light') {
    return logoLight ? (
      <LogoImage src={logoLight} alt={companyName} height={height} className={className} />
    ) : (
      textFallback
    );
  }

  if (variant === 'dark') {
    return logoDark ? (
      <LogoImage src={logoDark} alt={companyName} height={height} className={className} />
    ) : (
      textFallback
    );
  }

  if (!logoLight && !logoDark) {
    return textFallback;
  }

  // Both variants are emitted and toggled by `prefers-color-scheme`, matching
  // the media-query strategy globals.css already uses for its neutrals. The
  // classes are defined in globals.css rather than as Tailwind `dark:`
  // variants, which are class-based and would need a theme provider this app
  // does not have.
  return (
    <>
      {logoLight ? (
        <LogoImage
          src={logoLight}
          alt={companyName}
          height={height}
          className={cn('brand-logo-light', className)}
        />
      ) : null}

      {logoDark ? (
        <LogoImage
          src={logoDark}
          alt={companyName}
          height={height}
          className={cn('brand-logo-dark', className)}
        />
      ) : null}
    </>
  );
}

/**
 * Plain <img> rather than next/image: the source is an arbitrary admin-supplied
 * URL that may live on S3, MinIO, or a CDN the operator adds later. next/image
 * would require every one of those hosts to be declared in next.config, turning
 * an admin upload into a deploy.
 */
function LogoImage({
  src,
  alt,
  height,
  className,
}: {
  src: string;
  alt: string;
  height: number;
  className?: string;
}) {
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={src}
      alt={alt}
      height={height}
      style={{ height: `${height}px` }}
      className={cn('w-auto object-contain', className)}
    />
  );
}
