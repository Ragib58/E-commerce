import Image from 'next/image';
import { Star } from 'lucide-react';

import { cn } from '@/lib/utils/cn';
import type { Section, Testimonial } from '../../types';
import { SectionShell } from '../section-shell';
import { settingNumber, textGridColumnsClass } from '../../lib/settings';

/**
 * Customer quotes.
 *
 * Rendered as plain text, never as HTML: the backend strips markup from these
 * on write, and a testimonial has no editorial need for formatting. That makes
 * this section immune to the injection surface that the rich-text sections have
 * to defend against.
 */

interface TestimonialsProps {
  section: Section;
  testimonials: Testimonial[];
}

export function Testimonials({ section, testimonials }: TestimonialsProps) {
  const columns = settingNumber(section.settings, 'columns', 3, 1, 4);

  if (testimonials.length === 0) return null;

  return (
    <SectionShell section={section}>
      <div className={cn('grid gap-4', textGridColumnsClass(columns))}>
        {testimonials.map((testimonial, index) => (
          <figure
            key={`${testimonial.author ?? 'anonymous'}-${index}`}
            className="flex h-full flex-col rounded-lg border border-border bg-card p-5"
          >
            {typeof testimonial.rating === 'number' ? (
              <Rating value={testimonial.rating} />
            ) : null}

            <blockquote className="mt-3 flex-1 text-sm leading-relaxed text-foreground">
              {testimonial.quote}
            </blockquote>

            {testimonial.author ? (
              <figcaption className="mt-4 flex items-center gap-3 border-t border-border pt-4">
                {testimonial.avatar ? (
                  <Image
                    src={testimonial.avatar}
                    alt=""
                    width={36}
                    height={36}
                    loading="lazy"
                    className="size-9 rounded-full object-cover"
                  />
                ) : null}

                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{testimonial.author}</p>
                  {testimonial.role ? (
                    <p className="truncate text-xs text-muted-foreground">{testimonial.role}</p>
                  ) : null}
                </div>
              </figcaption>
            ) : null}
          </figure>
        ))}
      </div>
    </SectionShell>
  );
}

/**
 * A star rating.
 *
 * The stars themselves are decorative — the accessible value is the text label,
 * so a screen reader announces "Rated 4 out of 5" rather than five icons.
 */
function Rating({ value }: { value: number }) {
  const rounded = Math.max(0, Math.min(5, Math.round(value)));

  return (
    <div className="flex items-center gap-0.5">
      <span className="sr-only">Rated {rounded} out of 5</span>
      {Array.from({ length: 5 }, (_, index) => (
        <Star
          key={index}
          aria-hidden="true"
          className={cn(
            'size-4',
            index < rounded ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/30',
          )}
        />
      ))}
    </div>
  );
}
