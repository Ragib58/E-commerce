<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The shape of a product, which decides what else about it is meaningful.
 *
 * This is not a cosmetic label: the type determines whether variants are
 * required, whether stock is tracked, and whether shipping fields apply. Those
 * rules are asked of the enum rather than re-derived at each call site, so a
 * new type is added here once instead of in every validator and service.
 */
enum ProductType: string
{
    /** One SKU, one price, one stock figure. */
    case Simple = 'simple';

    /** Sells through variants; the parent carries no sellable stock of its own. */
    case Variable = 'variable';

    /** Downloadable or licensed. No weight, no dimensions, no shipping. */
    case Digital = 'digital';

    /** Configured by the buyer at purchase time (engraving, made-to-order). */
    case Customizable = 'customizable';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple',
            self::Variable => 'Variable',
            self::Digital => 'Digital',
            self::Customizable => 'Customizable',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Simple => 'A single SKU with one price and one stock level.',
            self::Variable => 'Sold as variants — size, colour, and so on — each with its own SKU and stock.',
            self::Digital => 'Delivered electronically. Not shipped, and not weighed.',
            self::Customizable => 'Built to a buyer-supplied specification at order time.',
        };
    }

    /**
     * Whether stock lives on the variants rather than on the product row.
     *
     * A variable product's own `stock` column is a derived roll-up, never the
     * authoritative figure — writing to it directly would drift from the sum of
     * its variants the moment one of them sells.
     */
    public function usesVariantStock(): bool
    {
        return $this === self::Variable;
    }

    /**
     * Whether the product must have at least one variant to be sellable.
     */
    public function requiresVariants(): bool
    {
        return $this === self::Variable;
    }

    /**
     * Whether the product moves through a warehouse.
     *
     * Digital goods are excluded from weight/dimension validation and from
     * shipping calculations; a digital product with a shipping weight is a data
     * entry error, not a special case to accommodate downstream.
     */
    public function isShippable(): bool
    {
        return $this !== self::Digital;
    }

    /**
     * Whether stock is finite.
     *
     * Digital inventory is unlimited by default — a licence key generator does
     * not run out — so these products are never flagged out of stock.
     */
    public function tracksInventory(): bool
    {
        return $this !== self::Digital;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
            ],
            self::cases(),
        );
    }
}
