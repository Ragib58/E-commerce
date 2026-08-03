'use client';

import { useMemo } from 'react';
import { cn } from '@/lib/utils/cn';
import type { ProductVariant } from '../types';

/**
 * The option picker for a variable product.
 *
 * Renders one control per attribute, driven entirely by the variants the API
 * returned — the component has no notion of "size" or "colour". Adding
 * "Material" in the admin panel makes it appear here with no frontend change,
 * and `display_type` decides whether it renders as swatches or buttons.
 *
 * Options that cannot combine with the current selection are disabled rather
 * than hidden. Hiding them makes the control jump as a shopper explores, and
 * removes the information that the combination exists but is unavailable.
 */

export interface AttributeGroup {
  slug: string;
  name: string;
  displayType: string;
  values: Array<{ slug: string; value: string; colourCode?: string | null }>;
}

interface VariantSelectorProps {
  variants: ProductVariant[];
  /** Current selection, keyed by attribute slug. */
  selection: Record<string, string>;
  onSelect: (attributeSlug: string, valueSlug: string) => void;
}

/**
 * Derive the attribute groups from the variants themselves.
 *
 * Built from the data rather than fetched separately: the only options worth
 * offering are those some variant actually has, and a separate fetch could
 * present a colour the product does not come in.
 */
export function deriveAttributeGroups(variants: ProductVariant[]): AttributeGroup[] {
  const groups = new Map<string, AttributeGroup>();

  for (const variant of variants) {
    for (const option of variant.options) {
      if (!option.attribute) continue;

      const existing = groups.get(option.attribute);

      if (!existing) {
        groups.set(option.attribute, {
          slug: option.attribute,
          name: option.attribute_name ?? option.attribute,
          displayType: option.display_type,
          values: [{ slug: option.slug, value: option.value, colourCode: option.colour_code }],
        });

        continue;
      }

      if (!existing.values.some((value) => value.slug === option.slug)) {
        existing.values.push({
          slug: option.slug,
          value: option.value,
          colourCode: option.colour_code,
        });
      }
    }
  }

  return [...groups.values()];
}

/**
 * Find the variant matching a complete selection.
 *
 * Returns null for a partial selection, which is what keeps the page from
 * showing a price and an add-to-cart button before the shopper has chosen every
 * option.
 */
export function findMatchingVariant(
  variants: ProductVariant[],
  selection: Record<string, string>,
  groups: AttributeGroup[],
): ProductVariant | null {
  if (groups.some((group) => !selection[group.slug])) {
    return null;
  }

  return (
    variants.find((variant) =>
      variant.options.every(
        (option) => option.attribute && selection[option.attribute] === option.slug,
      ),
    ) ?? null
  );
}

export function VariantSelector({ variants, selection, onSelect }: VariantSelectorProps) {
  const groups = useMemo(() => deriveAttributeGroups(variants), [variants]);

  /**
   * Which values remain reachable, given everything else already chosen.
   *
   * For each attribute, the selection of *that* attribute is ignored while
   * testing — otherwise a shopper who picked "Red" would find every other
   * colour disabled and be unable to change their mind without resetting.
   */
  const availability = useMemo(() => {
    const map: Record<string, Set<string>> = {};

    for (const group of groups) {
      const reachable = new Set<string>();

      for (const variant of variants) {
        const matchesOthers = variant.options.every((option) => {
          if (!option.attribute || option.attribute === group.slug) return true;

          const chosen = selection[option.attribute];

          return !chosen || chosen === option.slug;
        });

        if (!matchesOthers) continue;

        const own = variant.options.find((option) => option.attribute === group.slug);

        // Only offer combinations that can actually be bought.
        if (own && variant.inventory.in_stock) {
          reachable.add(own.slug);
        }
      }

      map[group.slug] = reachable;
    }

    return map;
  }, [groups, variants, selection]);

  if (groups.length === 0) return null;

  return (
    <div className="flex flex-col gap-5">
      {groups.map((group) => (
        <fieldset key={group.slug}>
          <legend className="mb-2 text-sm font-medium">
            {group.name}
            {selection[group.slug] ? (
              <span className="ml-2 font-normal text-muted-foreground">
                {group.values.find((value) => value.slug === selection[group.slug])?.value}
              </span>
            ) : null}
          </legend>

          <div className="flex flex-wrap gap-2">
            {group.values.map((value) => {
              const isSelected = selection[group.slug] === value.slug;
              const isAvailable = availability[group.slug]?.has(value.slug) ?? false;

              // Swatches for colour-like attributes, buttons for the rest.
              if (group.displayType === 'swatch' && value.colourCode) {
                return (
                  <button
                    key={value.slug}
                    type="button"
                    onClick={() => onSelect(group.slug, value.slug)}
                    disabled={!isAvailable}
                    // The colour alone does not name the option for a screen
                    // reader, nor for anyone who cannot distinguish it.
                    aria-label={`${group.name}: ${value.value}${isAvailable ? '' : ' (unavailable)'}`}
                    aria-pressed={isSelected}
                    title={value.value}
                    className={cn(
                      'relative h-9 w-9 rounded-full border-2 transition-all',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
                      isSelected ? 'border-foreground scale-110' : 'border-border',
                      !isAvailable && 'cursor-not-allowed opacity-40',
                    )}
                    style={{ backgroundColor: value.colourCode }}
                  >
                    {!isAvailable ? (
                      <span className="absolute inset-0 flex items-center justify-center text-xs">
                        ✕
                      </span>
                    ) : null}
                  </button>
                );
              }

              return (
                <button
                  key={value.slug}
                  type="button"
                  onClick={() => onSelect(group.slug, value.slug)}
                  disabled={!isAvailable}
                  aria-pressed={isSelected}
                  aria-label={`${group.name}: ${value.value}${isAvailable ? '' : ' (unavailable)'}`}
                  className={cn(
                    'min-w-[3rem] rounded-md border px-3 py-2 text-sm font-medium transition-colors',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    isSelected
                      ? 'border-primary bg-primary text-primary-foreground'
                      : 'border-border hover:border-foreground',
                    !isAvailable && 'cursor-not-allowed text-muted-foreground line-through opacity-50',
                  )}
                >
                  {value.value}
                </button>
              );
            })}
          </div>
        </fieldset>
      ))}
    </div>
  );
}
