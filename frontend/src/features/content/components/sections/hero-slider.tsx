'use client';

import { useCallback, useEffect, useId, useRef, useState, useSyncExternalStore } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { ChevronLeft, ChevronRight } from 'lucide-react';

import { cn } from '@/lib/utils/cn';
import type { Banner, Section } from '../../types';
import {
  heroHeightClass,
  safeUrl,
  settingBoolean,
  settingNumber,
  settingString,
} from '../../lib/settings';

/**
 * The rotating hero.
 *
 * The only section that must be a client component — everything else on the
 * homepage is static markup and ships no JavaScript. Autoplay, keyboard
 * navigation, and the pause-on-interaction behaviour all need the client.
 *
 * Accessibility and motion decisions, none of which are incidental:
 *
 *   - Autoplay stops permanently once the visitor takes control. A carousel
 *     that resumes moving under someone who just clicked an arrow is the
 *     single most common complaint about this pattern.
 *   - Autoplay never starts if the visitor has asked for reduced motion.
 *   - Hover and keyboard focus pause rotation, so a slide cannot change out
 *     from under someone reading it or tabbing to its link.
 *   - Every slide stays in the DOM and is hidden with `aria-hidden` plus
 *     `inert`, rather than unmounted. Unmounting would break the layout height
 *     and lose the browser's image decode between transitions.
 */

interface HeroSliderProps {
  section: Section;
  slides: Banner[];
}

export function HeroSlider({ section, slides }: HeroSliderProps) {
  const settings = section.settings;

  const autoplay = settingBoolean(settings, 'autoplay', true);
  const interval = settingNumber(settings, 'interval', 6000, 2000, 30000);
  const showArrows = settingBoolean(settings, 'show_arrows', true);
  const showDots = settingBoolean(settings, 'show_dots', true);
  const height = settingString(settings, 'height', 'large');

  const [current, setCurrent] = useState(0);
  // Set once the visitor interacts, and never cleared — see the class docblock.
  const [userTookControl, setUserTookControl] = useState(false);
  const [isPaused, setIsPaused] = useState(false);

  const prefersReducedMotion = usePrefersReducedMotion();

  const labelId = useId();
  const containerRef = useRef<HTMLDivElement>(null);

  const total = slides.length;

  const goTo = useCallback(
    (index: number) => {
      if (total === 0) return;

      // Modulo with a positive correction so stepping back from slide 0 wraps
      // to the last slide rather than producing a negative index.
      setCurrent(((index % total) + total) % total);
    },
    [total],
  );

  const next = useCallback(() => goTo(current + 1), [current, goTo]);
  const previous = useCallback(() => goTo(current - 1), [current, goTo]);

  const shouldAutoplay =
    autoplay && !userTookControl && !isPaused && !prefersReducedMotion && total > 1;

  useEffect(() => {
    if (!shouldAutoplay) return;

    const timer = window.setInterval(() => {
      setCurrent((index) => (index + 1) % total);
    }, interval);

    return () => window.clearInterval(timer);
  }, [shouldAutoplay, interval, total]);

  function handleControl(action: () => void) {
    setUserTookControl(true);
    action();
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'ArrowRight') {
      event.preventDefault();
      handleControl(next);
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault();
      handleControl(previous);
    }
  }

  if (total === 0) return null;

  return (
    <section
      aria-roledescription="carousel"
      aria-label={section.heading ?? section.name}
      className="relative"
    >
      <div
        ref={containerRef}
        role="group"
        aria-labelledby={labelId}
        tabIndex={-1}
        onKeyDown={handleKeyDown}
        onMouseEnter={() => setIsPaused(true)}
        onMouseLeave={() => setIsPaused(false)}
        // Focus anywhere inside pauses rotation, so a keyboard user tabbing
        // through a slide's link is not carried to the next slide mid-tab.
        onFocusCapture={() => setIsPaused(true)}
        onBlurCapture={() => setIsPaused(false)}
        className={cn('relative w-full overflow-hidden bg-muted', heroHeightClass(height))}
      >
        <span id={labelId} className="sr-only">
          {section.heading ?? section.name}: slide {current + 1} of {total}
        </span>

        {slides.map((slide, index) => (
          <HeroSlide
            key={slide.id}
            slide={slide}
            isActive={index === current}
            // Only the first slide is eager: it is the largest contentful paint
            // on most homepages, while the rest are below the fold in practice.
            priority={index === 0}
          />
        ))}

        {showArrows && total > 1 ? (
          <>
            <SliderButton
              direction="previous"
              onClick={() => handleControl(previous)}
              className="left-3"
            />
            <SliderButton
              direction="next"
              onClick={() => handleControl(next)}
              className="right-3"
            />
          </>
        ) : null}

        {showDots && total > 1 ? (
          <div className="absolute inset-x-0 bottom-4 flex justify-center gap-2">
            {slides.map((slide, index) => (
              <button
                key={slide.id}
                type="button"
                onClick={() => handleControl(() => goTo(index))}
                aria-label={`Go to slide ${index + 1}`}
                aria-current={index === current}
                className={cn(
                  'h-2 rounded-full transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-white',
                  index === current ? 'w-6 bg-white' : 'w-2 bg-white/50 hover:bg-white/75',
                )}
              />
            ))}
          </div>
        ) : null}
      </div>

      {/*
        Announces slide changes to screen readers without moving focus.
        `polite` so it waits for a pause rather than interrupting.
      */}
      <div aria-live="polite" aria-atomic="true" className="sr-only">
        Slide {current + 1} of {total}: {slides[current]?.title}
      </div>
    </section>
  );
}

/**
 * Whether the visitor has asked the system for reduced motion.
 *
 * `useSyncExternalStore` rather than an effect: a media query *is* an external
 * store, and this is the API built for exactly that. It also gets the server
 * snapshot right — `false` during SSR, so the markup React produces on the
 * server matches the first client render and no hydration mismatch occurs.
 */
function usePrefersReducedMotion(): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const query = window.matchMedia('(prefers-reduced-motion: reduce)');

      query.addEventListener('change', onChange);

      return () => query.removeEventListener('change', onChange);
    },
    () => window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    // Server snapshot. Assuming "no preference" here is the safe default: the
    // autoplay effect only runs on the client, so a visitor who does prefer
    // reduced motion never sees a frame of movement regardless.
    () => false,
  );
}

function HeroSlide({
  slide,
  isActive,
  priority,
}: {
  slide: Banner;
  isActive: boolean;
  priority: boolean;
}) {
  const href = safeUrl(slide.link_url);

  const content = (
    <>
      {slide.image ? (
        <picture>
          {/*
            Art direction, not just resolution: an operator can upload a
            portrait crop for phones where the desktop banner's subject would
            be cropped out entirely. The API falls back to the desktop image
            when no mobile one exists, so this source is always valid.
          */}
          {slide.mobile_image ? (
            <source media="(max-width: 640px)" srcSet={slide.mobile_image} />
          ) : null}
          <Image
            src={slide.image}
            alt={slide.alt_text || slide.title}
            fill
            sizes="100vw"
            priority={priority}
            // Below-the-fold slides are decoded off the main thread so they do
            // not compete with the first paint.
            loading={priority ? 'eager' : 'lazy'}
            className="object-cover"
          />
        </picture>
      ) : null}

      {(slide.title || slide.subtitle || slide.link_label) ? (
        <div className="absolute inset-0 flex items-center bg-gradient-to-r from-black/60 via-black/25 to-transparent">
          <div className="mx-auto w-full max-w-7xl px-6 sm:px-10">
            <div className="max-w-xl text-white">
              <h2 className="text-3xl font-semibold tracking-tight drop-shadow-sm sm:text-4xl lg:text-5xl">
                {slide.title}
              </h2>

              {slide.subtitle ? (
                <p className="mt-3 text-base text-white/90 sm:text-lg">{slide.subtitle}</p>
              ) : null}

              {slide.link_label && href ? (
                <span className="mt-6 inline-flex items-center rounded-md bg-white px-5 py-2.5 text-sm font-semibold text-black shadow-sm transition-colors hover:bg-white/90">
                  {slide.link_label}
                </span>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </>
  );

  return (
    <div
      // Hidden slides stay mounted but are removed from the accessibility tree
      // and from tab order — `inert` is what stops a keyboard user landing on
      // the link of a slide they cannot see.
      aria-hidden={!isActive}
      inert={!isActive}
      className={cn(
        'absolute inset-0 transition-opacity duration-700 motion-reduce:transition-none',
        isActive ? 'opacity-100' : 'opacity-0',
      )}
    >
      {href ? (
        <Link
          href={href}
          target={slide.link_external ? '_blank' : undefined}
          rel={slide.link_external ? 'noopener noreferrer' : undefined}
          className="block h-full w-full focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-white"
        >
          {content}
        </Link>
      ) : (
        content
      )}
    </div>
  );
}

function SliderButton({
  direction,
  onClick,
  className,
}: {
  direction: 'previous' | 'next';
  onClick: () => void;
  className?: string;
}) {
  const Icon = direction === 'previous' ? ChevronLeft : ChevronRight;

  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={direction === 'previous' ? 'Previous slide' : 'Next slide'}
      className={cn(
        'absolute top-1/2 z-10 -translate-y-1/2 rounded-full bg-white/85 p-2 text-black shadow-md transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
        className,
      )}
    >
      <Icon className="size-5" aria-hidden="true" />
    </button>
  );
}
