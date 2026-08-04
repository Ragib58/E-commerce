import { z } from 'zod';

import { apiClient } from '@/lib/api/client';
import { CACHE_TAGS, REVALIDATE_SECONDS } from '@/config/cache';
import {
  bannerSchema,
  cmsPageSchema,
  sectionSchema,
  type Banner,
  type CmsPage,
  type Section,
} from '../types';

/**
 * Data access for storefront content — the dynamic homepage, banners, and CMS
 * pages.
 *
 * Every function here degrades rather than throws. A homepage that cannot reach
 * the API should render as an empty (but working) page with the header, footer,
 * and navigation intact; throwing would replace the whole storefront with an
 * error screen because one section's data was unavailable.
 *
 * Responses are cached by tag. Laravel purges `content` when an operator saves,
 * so the ISR window is a backstop for a missed webhook — and, for scheduled
 * sections, the only thing that eventually reflects a start or end date that
 * arrived with no admin action behind it.
 */

export interface Homepage {
  sections: Section[];
  /**
   * Distinguishes "no sections configured" from "the request failed".
   *
   * The two produce an identical empty array but call for very different UI:
   * one is a store that has not been set up, the other is an outage.
   */
  isConfigured: boolean;
  isFallback: boolean;
}

const EMPTY_HOMEPAGE: Homepage = { sections: [], isConfigured: false, isFallback: true };

/**
 * The whole homepage in one request, sections already resolved to content.
 */
export async function fetchHomepage(): Promise<Homepage> {
  try {
    const result = await apiClient.get<unknown>('/homepage', {
      next: {
        revalidate: REVALIDATE_SECONDS.content,
        tags: [CACHE_TAGS.content],
      },
    });

    const parsed = z.array(sectionSchema).safeParse(result.data);

    if (!parsed.success) {
      console.error('[content] Homepage failed validation.', parsed.error.flatten());

      return EMPTY_HOMEPAGE;
    }

    return {
      // Sorted client-side as well as server-side. The API returns them in
      // order, but a cached payload merged across a reorder would otherwise
      // render out of sequence, and sorting a dozen items costs nothing.
      sections: [...parsed.data].sort((a, b) => a.sort_order - b.sort_order),
      isConfigured: parsed.data.length > 0,
      isFallback: false,
    };
  } catch (error) {
    console.error('[content] Failed to load the homepage.', error);

    return EMPTY_HOMEPAGE;
  }
}

/**
 * Live banners for one placement.
 *
 * For surfaces outside the homepage — a category header, a checkout strip —
 * which fetch their own rather than receiving them in a page payload.
 */
export async function fetchBanners(placement?: string, limit?: number): Promise<Banner[]> {
  try {
    const result = await apiClient.get<unknown>('/banners', {
      params: { placement, limit },
      next: {
        revalidate: REVALIDATE_SECONDS.content,
        tags: [CACHE_TAGS.content],
      },
    });

    return z.array(bannerSchema).catch([]).parse(result.data);
  } catch (error) {
    console.error('[content] Failed to load banners.', error);

    return [];
  }
}

/**
 * Published pages as titles and slugs, for footer navigation.
 */
export async function fetchPages(): Promise<CmsPage[]> {
  try {
    const result = await apiClient.get<unknown>('/pages', {
      next: {
        revalidate: REVALIDATE_SECONDS.content,
        tags: [CACHE_TAGS.content],
      },
    });

    return z.array(cmsPageSchema).catch([]).parse(result.data);
  } catch (error) {
    console.error('[content] Failed to load pages.', error);

    return [];
  }
}

/**
 * A single published page.
 *
 * Returns null for a missing or unpublished page so the caller can render a
 * 404 — the API deliberately does not distinguish the two, so neither does this.
 */
export async function fetchPage(slug: string): Promise<CmsPage | null> {
  try {
    const result = await apiClient.get<unknown>(`/pages/${encodeURIComponent(slug)}`, {
      next: {
        revalidate: REVALIDATE_SECONDS.content,
        tags: [CACHE_TAGS.content],
      },
    });

    const parsed = cmsPageSchema.safeParse(result.data);

    if (!parsed.success) {
      console.error('[content] Page failed validation.', parsed.error.flatten());

      return null;
    }

    return parsed.data;
  } catch {
    // A 404 is the expected path for an unknown slug, so this is not logged as
    // an error — the caller renders notFound().
    return null;
  }
}
