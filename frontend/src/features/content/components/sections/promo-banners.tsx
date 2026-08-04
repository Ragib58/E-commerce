import Image from 'next/image';
import Link from 'next/link';

import { cn } from '@/lib/utils/cn';
import type { Banner, Section } from '../../types';
import { SectionShell } from '../section-shell';
import { aspectRatioClass, safeUrl, settingString } from '../../lib/settings';

/**
 * Promotional banners — one full-width strip, a two-up split, or a grid.
 *
 * A server component: these are static images and links with no interactivity,
 * so they ship no JavaScript at all. Only the hero needs the client.
 */

interface PromoBannersProps {
  section: Section;
  banners: Banner[];
}

export function PromoBanners({ section, banners }: PromoBannersProps) {
  const layout = settingString(section.settings, 'layout', 'full');
  const ratio = settingString(section.settings, 'aspect_ratio', '21:9');

  if (banners.length === 0) return null;

  const gridClass =
    layout === 'split'
      ? 'grid gap-4 md:grid-cols-2'
      : layout === 'grid'
        ? 'grid gap-4 sm:grid-cols-2 lg:grid-cols-3'
        : 'grid gap-4';

  /*
   * `sizes` must match the layout, or the browser downloads the wrong
   * resolution: a half-width banner told it occupies 100vw fetches an image
   * twice the size it will ever be displayed at.
   */
  const sizes =
    layout === 'split'
      ? '(max-width: 768px) 100vw, 50vw'
      : layout === 'grid'
        ? '(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw'
        : '100vw';

  return (
    <SectionShell section={section}>
      <div className={gridClass}>
        {banners.map((banner) => (
          <PromoBanner
            key={banner.id}
            banner={banner}
            ratioClass={aspectRatioClass(ratio)}
            sizes={sizes}
          />
        ))}
      </div>
    </SectionShell>
  );
}

function PromoBanner({
  banner,
  ratioClass,
  sizes,
}: {
  banner: Banner;
  ratioClass: string;
  sizes: string;
}) {
  const href = safeUrl(banner.link_url);

  const inner = (
    <div className={cn('relative w-full overflow-hidden rounded-lg bg-muted', ratioClass)}>
      {banner.image ? (
        <picture>
          {banner.mobile_image ? (
            <source media="(max-width: 640px)" srcSet={banner.mobile_image} />
          ) : null}
          <Image
            src={banner.image}
            alt={banner.alt_text || banner.title}
            fill
            sizes={sizes}
            // Promo strips sit below the hero, so they are never the LCP
            // element and always lazy-load.
            loading="lazy"
            className="object-cover transition-transform duration-300 group-hover:scale-[1.02]"
          />
        </picture>
      ) : null}

      {(banner.title || banner.subtitle || banner.link_label) ? (
        <div className="absolute inset-0 flex flex-col justify-center bg-gradient-to-r from-black/55 to-transparent p-6 sm:p-10">
          <div className="max-w-md text-white">
            <h3 className="text-xl font-semibold tracking-tight sm:text-2xl">{banner.title}</h3>

            {banner.subtitle ? (
              <p className="mt-2 text-sm text-white/90">{banner.subtitle}</p>
            ) : null}

            {banner.link_label && href ? (
              <span className="mt-4 inline-flex items-center rounded-md bg-white px-4 py-2 text-sm font-semibold text-black">
                {banner.link_label}
              </span>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  );

  if (!href) return <div className="group">{inner}</div>;

  return (
    <Link
      href={href}
      target={banner.link_external ? '_blank' : undefined}
      rel={banner.link_external ? 'noopener noreferrer' : undefined}
      className="group block rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      {inner}
    </Link>
  );
}
