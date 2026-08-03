'use client';

import { useMemo, useState } from 'react';
import Image from 'next/image';
import { cn } from '@/lib/utils/cn';
import type { ProductMedia } from '../types';

/**
 * The product image gallery.
 *
 * A client component because selecting a thumbnail is interaction. It also
 * responds to variant selection: choosing "Red" scrolls the gallery to the red
 * photographs, which is why `activeVariantId` is an input rather than internal
 * state.
 */

interface ProductGalleryProps {
  media: ProductMedia[];
  productName: string;
  videoUrl?: string | null;
  /** When set, images tagged to this variant are shown first. */
  activeVariantId?: string | null;
}

export function ProductGallery({
  media,
  productName,
  videoUrl,
  activeVariantId,
}: ProductGalleryProps) {
  /**
   * Images relevant to the current selection.
   *
   * A variant's own images lead, followed by the product-wide ones — a variant
   * rarely has a full set of its own, and hiding the shared shots would leave a
   * near-empty gallery.
   */
  const images = useMemo(() => {
    const all = media.filter((item) => item.type === 'image');

    if (!activeVariantId) return all;

    const variantImages = all.filter((item) => item.variant_id === activeVariantId);
    const shared = all.filter((item) => !item.variant_id);

    return variantImages.length > 0 ? [...variantImages, ...shared] : all;
  }, [media, activeVariantId]);

  const [activeIndex, setActiveIndex] = useState(0);

  /*
   * Reset the selection when the visible set changes.
   *
   * Tracked as a render-time comparison rather than an effect: an effect would
   * paint one frame with a stale index first, and selecting a variant whose
   * gallery is shorter than the current index would briefly show a blank main
   * image before correcting itself.
   */
  const [galleryKey, setGalleryKey] = useState(`${activeVariantId ?? ''}:${images.length}`);
  const currentKey = `${activeVariantId ?? ''}:${images.length}`;

  if (galleryKey !== currentKey) {
    setGalleryKey(currentKey);
    setActiveIndex(0);
  }

  // Clamped, so the index can never point past the end of the current set even
  // on the render where both are being reconciled.
  const safeIndex = Math.min(activeIndex, Math.max(0, images.length - 1));
  const active = images[safeIndex];

  if (images.length === 0 && !videoUrl) {
    return (
      <div className="flex aspect-square items-center justify-center rounded-lg bg-muted text-sm text-muted-foreground">
        No images available
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="relative aspect-square overflow-hidden rounded-lg border border-border bg-muted">
        {active ? (
          <Image
            src={active.url ?? ''}
            alt={active.alt_text ?? productName}
            fill
            sizes="(max-width: 1024px) 100vw, 50vw"
            // The main image is the page's largest contentful paint.
            priority
            className="object-cover"
          />
        ) : null}
      </div>

      {images.length > 1 ? (
        <div
          role="group"
          aria-label={`${productName} images`}
          className="grid grid-cols-5 gap-2"
        >
          {images.map((item, index) => (
            <button
              key={item.id}
              type="button"
              onClick={() => setActiveIndex(index)}
              aria-label={`View image ${index + 1} of ${images.length}`}
              aria-current={index === safeIndex}
              className={cn(
                'relative aspect-square overflow-hidden rounded border transition-colors',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                index === safeIndex
                  ? 'border-primary ring-1 ring-primary'
                  : 'border-border hover:border-muted-foreground',
              )}
            >
              <Image
                src={item.url ?? ''}
                alt=""
                fill
                sizes="80px"
                className="object-cover"
              />
            </button>
          ))}
        </div>
      ) : null}

      {videoUrl ? (
        <a
          href={videoUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="text-sm font-medium text-primary underline-offset-4 hover:underline"
        >
          Watch the product video
        </a>
      ) : null}
    </div>
  );
}
