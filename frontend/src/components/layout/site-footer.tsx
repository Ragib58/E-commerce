'use client';

import Link from 'next/link';

import { useStoreConfig } from '@/components/providers/store-config-provider';
import type { SocialPlatform } from '@/features/settings/lib/store-config';

/**
 * Site footer.
 *
 * Contact details and social links are rendered from the store configuration
 * and each block is omitted entirely when unconfigured — an administrator who
 * has not set an Instagram URL gets no empty icon, not a dead link.
 *
 * CMS page links are passed in as a prop rather than fetched here: this is a
 * client component (it reads the config from context), and fetching the page
 * list from the browser would put a request on every navigation for data the
 * server already has. The root layout resolves them once, server-side.
 */

export interface FooterLink {
  title: string;
  slug: string;
}

const SOCIAL_LABELS: Record<SocialPlatform, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  x: 'X',
  linkedin: 'LinkedIn',
  youtube: 'YouTube',
  tiktok: 'TikTok',
};

export function SiteFooter({ pages = [] }: { pages?: FooterLink[] }) {
  const { companyName, brandDescription, contact, social } = useStoreConfig();

  const hasContact =
    contact.email || contact.phone || contact.address || contact.supportHours || contact.googleMapsUrl;

  const year = new Date().getFullYear();

  return (
    <footer className="border-t border-border bg-muted/30">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-2 lg:grid-cols-4">
        <div>
          <h2 className="text-sm font-semibold">{companyName}</h2>
          {brandDescription ? (
            <p className="mt-2 max-w-xs text-sm text-muted-foreground">{brandDescription}</p>
          ) : null}
        </div>

        {hasContact ? (
          <div>
            <h2 className="text-sm font-semibold">Contact</h2>
            <ul className="mt-2 space-y-1.5 text-sm text-muted-foreground">
              {contact.email ? (
                <li>
                  <a href={`mailto:${contact.email}`} className="hover:text-foreground">
                    {contact.email}
                  </a>
                </li>
              ) : null}
              {contact.phone ? (
                <li>
                  <a
                    href={`tel:${contact.phone.replace(/\s/g, '')}`}
                    className="hover:text-foreground"
                  >
                    {contact.phone}
                  </a>
                </li>
              ) : null}
              {contact.address ? <li>{contact.address}</li> : null}
              {contact.googleMapsUrl ? (
                <li>
                  <a
                    href={contact.googleMapsUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="hover:text-foreground"
                  >
                    View on map
                  </a>
                </li>
              ) : null}
              {contact.supportHours ? <li>{contact.supportHours}</li> : null}
            </ul>
          </div>
        ) : null}

        {/*
          Admin-managed pages. Nothing here is hardcoded — the six seeded
          policy pages and any the operator adds arrive through the same list,
          and a page taken offline disappears from the footer with it.
        */}
        {pages.length > 0 ? (
          <div>
            <h2 className="text-sm font-semibold">Information</h2>
            <ul className="mt-2 space-y-1.5 text-sm text-muted-foreground">
              {pages.map((page) => (
                <li key={page.slug}>
                  <Link href={`/p/${page.slug}`} className="hover:text-foreground">
                    {page.title}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        ) : null}

        {social.length > 0 ? (
          <div>
            <h2 className="text-sm font-semibold">Follow</h2>
            <ul className="mt-2 space-y-1.5 text-sm text-muted-foreground">
              {social.map(({ platform, url }) => (
                <li key={platform}>
                  <a
                    href={url}
                    target="_blank"
                    // noreferrer prevents the destination reading document.referrer;
                    // noopener stops it accessing window.opener.
                    rel="noopener noreferrer"
                    className="hover:text-foreground"
                  >
                    {SOCIAL_LABELS[platform]}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>

      <div className="border-t border-border">
        <div className="mx-auto max-w-6xl px-4 py-5 text-xs text-muted-foreground sm:px-6">
          &copy; {year} {companyName}. All rights reserved.
        </div>
      </div>
    </footer>
  );
}
