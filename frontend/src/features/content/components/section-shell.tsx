import type { ReactNode } from 'react';

import { cn } from '@/lib/utils/cn';
import type { Section } from '../types';
import { containerClass, safeBackgroundColor } from '../lib/settings';

/**
 * The chrome every homepage section shares: spacing, container width, an
 * optional background, and the heading pair.
 *
 * Factored out so the eleven renderers below it contain only what makes each
 * one different. It also means a change to section rhythm — vertical padding,
 * heading size, how a background bleeds full width — happens once rather than
 * eleven times.
 *
 * A section with neither heading nor subheading renders no header element at
 * all, rather than an empty one that would still occupy its margin.
 */

interface SectionShellProps {
  section: Section;
  children: ReactNode;
  /** Rendered opposite the heading — typically a "View all" link. */
  action?: ReactNode;
  /** Suppresses the default vertical padding, for sections that bleed. */
  flush?: boolean;
  className?: string;
}

export function SectionShell({
  section,
  children,
  action,
  flush = false,
  className,
}: SectionShellProps) {
  const background = safeBackgroundColor(section.style.background_color);
  const hasHeader = Boolean(section.heading || section.subheading);

  return (
    <section
      // A stable, section-scoped anchor. The admin preview scrolls to it, and
      // an operator can link to a section from elsewhere on the site.
      id={`section-${section.id}`}
      // The operator's own label for the section, which is a better accessible
      // name than a generic "region" when there is no visible heading.
      aria-label={section.heading ? undefined : section.name}
      className={cn(!flush && 'py-10 sm:py-14', className)}
      style={background ? { backgroundColor: background } : undefined}
    >
      <div className={containerClass(section.style.container_width)}>
        {hasHeader ? (
          <header className="mb-6 flex flex-wrap items-end justify-between gap-3 sm:mb-8">
            <div className="max-w-2xl">
              {section.heading ? (
                <h2 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                  {section.heading}
                </h2>
              ) : null}

              {section.subheading ? (
                <p className="mt-2 text-sm text-muted-foreground sm:text-base">
                  {section.subheading}
                </p>
              ) : null}
            </div>

            {action}
          </header>
        ) : null}

        {children}
      </div>
    </section>
  );
}
