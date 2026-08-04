import { Flame } from 'lucide-react';

import { ProductCard } from '@/features/catalog/components/product-card';
import type { Product } from '@/features/catalog/types';
import type { StoreConfig } from '@/features/settings/lib/store-config';
import { cn } from '@/lib/utils/cn';
import type { Section } from '../../types';
import { SectionShell } from '../section-shell';
import { Countdown } from './countdown';
import { gridColumnsClass, settingBoolean, settingNumber } from '../../lib/settings';

/**
 * A time-boxed offer.
 *
 * Structurally a product rail with a countdown, but kept separate because the
 * countdown is the point: it is what makes the section urgent, and it is driven
 * by the section's own `ends_at` — the same field the backend uses to stop
 * serving the section at all.
 *
 * That shared field is what keeps the two honest. When the clock reaches zero,
 * the section is already outside its window, so the next request drops it
 * entirely rather than showing an expired sale with a zeroed timer.
 */

interface FlashSaleProps {
  section: Section;
  products: Product[];
  config: StoreConfig;
}

export function FlashSale({ section, products, config }: FlashSaleProps) {
  const columns = settingNumber(section.settings, 'columns', 4, 1, 6);
  const showCountdown = settingBoolean(section.settings, 'show_countdown', true);

  if (products.length === 0) return null;

  return (
    <SectionShell
      section={section}
      action={
        showCountdown && section.ends_at ? (
          <div className="flex flex-col items-start gap-1">
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-destructive">
              <Flame className="size-3.5" aria-hidden="true" />
              Ends in
            </span>
            <Countdown endsAt={section.ends_at} />
            {/* The accessible equivalent of the ticking digits, announced once
                rather than every second. */}
            <span className="sr-only">
              This offer ends on {new Date(section.ends_at).toLocaleString()}.
            </span>
          </div>
        ) : null
      }
    >
      <div className={cn('grid gap-4', gridColumnsClass(columns))}>
        {products.map((product) => (
          <ProductCard key={product.id} product={product} config={config} />
        ))}
      </div>
    </SectionShell>
  );
}
