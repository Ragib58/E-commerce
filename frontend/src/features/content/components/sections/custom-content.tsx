import Image from 'next/image';
import Link from 'next/link';

import { cn } from '@/lib/utils/cn';
import type { Section } from '../../types';
import { SectionShell } from '../section-shell';
import { RichText } from '../rich-text';
import { safeUrl, settingNullableString, settingString } from '../../lib/settings';

/**
 * A free-form block: rich text, an optional image, and an optional call to
 * action.
 *
 * The escape hatch that stops the other ten section types from having to grow
 * an option for every one-off request — a shipping promise, a founder's note, a
 * size guide teaser.
 */

interface CustomContentProps {
  section: Section;
}

export function CustomContent({ section }: CustomContentProps) {
  const content = settingString(section.settings, 'content', '');
  const image = settingNullableString(section.settings, 'image');
  const position = settingString(section.settings, 'image_position', 'right');
  const ctaLabel = settingNullableString(section.settings, 'cta_label');
  const ctaUrl = safeUrl(settingNullableString(section.settings, 'cta_url'));

  if (!content && !image) return null;

  const body = (
    <div className="flex flex-col justify-center">
      <RichText html={content} />

      {ctaLabel && ctaUrl ? (
        <div className="mt-6">
          <Link
            href={ctaUrl}
            className="inline-flex items-center rounded-md bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            {ctaLabel}
          </Link>
        </div>
      ) : null}
    </div>
  );

  // No image: the text alone, constrained to a readable measure rather than
  // stretched across the full container width.
  if (!image) {
    return (
      <SectionShell section={section}>
        <div className="max-w-3xl">{body}</div>
      </SectionShell>
    );
  }

  const media = (
    <div className="relative aspect-[4/3] overflow-hidden rounded-lg bg-muted">
      <Image
        src={image}
        alt=""
        fill
        sizes="(max-width: 768px) 100vw, 50vw"
        loading="lazy"
        className="object-cover"
      />
    </div>
  );

  if (position === 'top' || position === 'bottom') {
    return (
      <SectionShell section={section}>
        <div className="mx-auto flex max-w-4xl flex-col gap-8">
          {position === 'top' ? media : null}
          {body}
          {position === 'bottom' ? media : null}
        </div>
      </SectionShell>
    );
  }

  return (
    <SectionShell section={section}>
      <div className="grid items-center gap-8 md:grid-cols-2">
        {/*
          Source order puts the text first so it is read before the decorative
          image on a screen reader and on a narrow viewport; `md:order-2` moves
          the image visually on wide screens without disturbing that order.
        */}
        {body}
        <div className={cn(position === 'left' && 'md:order-first')}>{media}</div>
      </div>
    </SectionShell>
  );
}
