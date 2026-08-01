'use client';

import { useSettings } from '@/components/providers/settings-provider';

/**
 * Site footer.
 *
 * Contact details and social links are rendered from settings and each block
 * is omitted entirely when unconfigured — an administrator who has not set an
 * Instagram URL gets no empty icon, not a dead link.
 */

const SOCIAL_LABELS: Record<string, string> = {
  facebook: 'Facebook',
  instagram: 'Instagram',
  x: 'X',
  linkedin: 'LinkedIn',
  youtube: 'YouTube',
};

export function SiteFooter() {
  const { settings } = useSettings();

  const companyName = settings.general?.company_name ?? 'Store';
  const contact = settings.contact ?? {};
  const social = settings.social ?? {};

  const socialLinks = Object.entries(social).filter(
    (entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1].length > 0,
  );

  const year = new Date().getFullYear();

  return (
    <footer className="border-t border-border bg-muted/30">
      <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-3">
        <div>
          <h2 className="text-sm font-semibold">{companyName}</h2>
          {settings.general?.description ? (
            <p className="mt-2 max-w-xs text-sm text-muted-foreground">
              {settings.general.description}
            </p>
          ) : null}
        </div>

        {(contact.email || contact.phone || contact.address || contact.support_hours) && (
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
                  <a href={`tel:${contact.phone.replace(/\s/g, '')}`} className="hover:text-foreground">
                    {contact.phone}
                  </a>
                </li>
              ) : null}
              {contact.address ? <li>{contact.address}</li> : null}
              {contact.support_hours ? <li>{contact.support_hours}</li> : null}
            </ul>
          </div>
        )}

        {socialLinks.length > 0 && (
          <div>
            <h2 className="text-sm font-semibold">Follow</h2>
            <ul className="mt-2 space-y-1.5 text-sm text-muted-foreground">
              {socialLinks.map(([platform, url]) => (
                <li key={platform}>
                  <a
                    href={url}
                    target="_blank"
                    // noreferrer prevents the destination reading document.referrer;
                    // noopener stops it accessing window.opener.
                    rel="noopener noreferrer"
                    className="hover:text-foreground"
                  >
                    {SOCIAL_LABELS[platform] ?? platform}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>

      <div className="border-t border-border">
        <div className="mx-auto max-w-6xl px-4 py-5 text-xs text-muted-foreground sm:px-6">
          &copy; {year} {companyName}. All rights reserved.
        </div>
      </div>
    </footer>
  );
}
