import Script from 'next/script';

import type { StoreConfig } from '../lib/store-config';

/**
 * Injects the measurement tags an administrator has configured.
 *
 * The IDs come from the settings API, not from environment variables: they are
 * marketing configuration that changes without a deploy, and different
 * environments legitimately point at different properties.
 *
 * Nothing renders when no ID is configured. Loading a tracker the operator did
 * not ask for is a privacy problem, not a missing feature — which is why there
 * is no default ID anywhere in this file.
 *
 * `afterInteractive` rather than `beforeInteractive`: analytics must never sit
 * on the critical path to first paint.
 */

/** GA4 measurement IDs look like `G-XXXXXXXXXX`; older properties use UA-/AW-. */
const GA_ID_PATTERN = /^(G|UA|AW|GT)-[A-Z0-9-]{4,20}$/i;

/** Pixel IDs are numeric, typically 15-16 digits. */
const PIXEL_ID_PATTERN = /^\d{6,20}$/;

interface AnalyticsScriptsProps {
  config: StoreConfig;
}

export function AnalyticsScripts({ config }: AnalyticsScriptsProps) {
  const { googleAnalyticsId, facebookPixelId } = config.analytics;

  // Both IDs are interpolated into inline script bodies, so each is validated
  // against a strict pattern first. An admin-supplied value that reached the
  // script verbatim would be a script-injection vector.
  const gaId = googleAnalyticsId && GA_ID_PATTERN.test(googleAnalyticsId) ? googleAnalyticsId : null;
  const pixelId =
    facebookPixelId && PIXEL_ID_PATTERN.test(facebookPixelId) ? facebookPixelId : null;

  if (!gaId && !pixelId) {
    return null;
  }

  return (
    <>
      {gaId ? (
        <>
          <Script
            src={`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(gaId)}`}
            strategy="afterInteractive"
          />
          <Script id="ga-init" strategy="afterInteractive">
            {`
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag('js', new Date());
              gtag('config', '${gaId}');
            `}
          </Script>
        </>
      ) : null}

      {pixelId ? (
        <>
          <Script id="fb-pixel" strategy="afterInteractive">
            {`
              !function(f,b,e,v,n,t,s)
              {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
              n.callMethod.apply(n,arguments):n.queue.push(arguments)};
              if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
              n.queue=[];t=b.createElement(e);t.async=!0;
              t.src=v;s=b.getElementsByTagName(e)[0];
              s.parentNode.insertBefore(t,s)}(window,document,'script',
              'https://connect.facebook.net/en_US/fbevents.js');
              fbq('init', '${pixelId}');
              fbq('track', 'PageView');
            `}
          </Script>
          <noscript>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              height="1"
              width="1"
              style={{ display: 'none' }}
              alt=""
              src={`https://www.facebook.com/tr?id=${encodeURIComponent(pixelId)}&ev=PageView&noscript=1`}
            />
          </noscript>
        </>
      ) : null}
    </>
  );
}
