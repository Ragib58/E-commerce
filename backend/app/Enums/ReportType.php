<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The tabular reports the admin panel can run and export.
 *
 * ## Why the column list lives on the enum
 *
 * A report is rendered as a table in the panel, streamed as CSV, written into a
 * spreadsheet, and laid out in a PDF. Four surfaces, one set of columns — and
 * four independently-maintained column lists is how an export ends up with a
 * header row that no longer matches the data beneath it.
 *
 * {@see columns()} is the single declaration all four read. Adding a column to
 * a report is one edit here plus one in the query that produces it, and every
 * export picks it up.
 *
 * The `key` of each column is also the array key the report rows use, so the
 * exporters need no per-report mapping.
 */
enum ReportType: string
{
    case Sales = 'sales';
    case Orders = 'orders';
    case ProductSales = 'product_sales';
    case Customers = 'customers';
    case Payments = 'payments';
    case Tax = 'tax';
    case Inventory = 'inventory';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales report',
            self::Orders => 'Order report',
            self::ProductSales => 'Product sales report',
            self::Customers => 'Customer report',
            self::Payments => 'Payment report',
            self::Tax => 'Tax report',
            self::Inventory => 'Inventory report',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sales => 'Revenue, refunds, tax, and shipping totalled per period.',
            self::Orders => 'Every order placed, with status, customer, and totals.',
            self::ProductSales => 'Units and revenue per product over the period.',
            self::Customers => 'Customers with their order counts and lifetime value.',
            self::Payments => 'Individual payment attempts, by gateway and outcome.',
            self::Tax => 'Tax collected per period, at the rate each order was charged.',
            self::Inventory => 'Current stock levels, valuation, and reorder status.',
        };
    }

    /**
     * Whether this report is bounded by the selected date range.
     *
     * Inventory is not: stock is a present-tense fact, and "stock levels
     * between March and June" is not a question the warehouse is asking. The
     * date filter is hidden for it rather than accepted and quietly ignored.
     */
    public function isDateScoped(): bool
    {
        return $this !== self::Inventory;
    }

    /**
     * Whether this report supports a free-text search, and over what.
     *
     * A per-period aggregate has nothing to search: its rows are dates. Saying
     * so here lets the panel hide the search box rather than offering one that
     * silently does nothing.
     */
    public function isSearchable(): bool
    {
        return match ($this) {
            self::Sales, self::Tax => false,
            default => true,
        };
    }

    /**
     * The report's columns, in display order.
     *
     * `type` drives formatting at every output surface: `money` values are
     * integer minor units that become a decimal string, `date` values are ISO
     * strings that become a locale date, `percent` gets a suffix.
     *
     * @return array<int, array{key: string, label: string, type: string}>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Sales => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'string'],
                ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ['key' => 'gross', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'discounts', 'label' => 'Discounts', 'type' => 'money'],
                ['key' => 'refunds', 'label' => 'Refunds', 'type' => 'money'],
                ['key' => 'tax', 'label' => 'Tax', 'type' => 'money'],
                ['key' => 'shipping', 'label' => 'Shipping', 'type' => 'money'],
                ['key' => 'net', 'label' => 'Net revenue', 'type' => 'money'],
            ],

            self::Orders => [
                ['key' => 'order_number', 'label' => 'Order', 'type' => 'string'],
                ['key' => 'placed_at', 'label' => 'Placed', 'type' => 'date'],
                ['key' => 'customer_name', 'label' => 'Customer', 'type' => 'string'],
                ['key' => 'customer_email', 'label' => 'Email', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'payment_status', 'label' => 'Payment', 'type' => 'string'],
                ['key' => 'payment_method', 'label' => 'Method', 'type' => 'string'],
                ['key' => 'items', 'label' => 'Items', 'type' => 'number'],
                ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'money'],
                ['key' => 'discount_total', 'label' => 'Discount', 'type' => 'money'],
                ['key' => 'tax_total', 'label' => 'Tax', 'type' => 'money'],
                ['key' => 'shipping_total', 'label' => 'Shipping', 'type' => 'money'],
                ['key' => 'grand_total', 'label' => 'Total', 'type' => 'money'],
                ['key' => 'refunded_total', 'label' => 'Refunded', 'type' => 'money'],
            ],

            self::ProductSales => [
                ['key' => 'name', 'label' => 'Product', 'type' => 'string'],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ['key' => 'units', 'label' => 'Units sold', 'type' => 'number'],
                ['key' => 'gross', 'label' => 'Gross', 'type' => 'money'],
                ['key' => 'discounts', 'label' => 'Discounts', 'type' => 'money'],
                ['key' => 'revenue', 'label' => 'Revenue', 'type' => 'money'],
            ],

            self::Customers => [
                ['key' => 'name', 'label' => 'Customer', 'type' => 'string'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'string'],
                ['key' => 'registered_at', 'label' => 'Registered', 'type' => 'date'],
                ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ['key' => 'units', 'label' => 'Items bought', 'type' => 'number'],
                ['key' => 'lifetime_value', 'label' => 'Lifetime value', 'type' => 'money'],
                ['key' => 'average_order_value', 'label' => 'Average order', 'type' => 'money'],
                ['key' => 'last_order_at', 'label' => 'Last order', 'type' => 'date'],
            ],

            self::Payments => [
                ['key' => 'created_at', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'order_number', 'label' => 'Order', 'type' => 'string'],
                ['key' => 'method', 'label' => 'Method', 'type' => 'string'],
                ['key' => 'gateway', 'label' => 'Gateway', 'type' => 'string'],
                ['key' => 'transaction_reference', 'label' => 'Reference', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ],

            self::Tax => [
                ['key' => 'period', 'label' => 'Period', 'type' => 'string'],
                ['key' => 'orders', 'label' => 'Orders', 'type' => 'number'],
                ['key' => 'taxable_base', 'label' => 'Taxable base', 'type' => 'money'],
                ['key' => 'tax_collected', 'label' => 'Tax collected', 'type' => 'money'],
                ['key' => 'effective_rate', 'label' => 'Effective rate', 'type' => 'percent'],
            ],

            self::Inventory => [
                ['key' => 'name', 'label' => 'Product', 'type' => 'string'],
                ['key' => 'sku', 'label' => 'SKU', 'type' => 'string'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'string'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['key' => 'stock', 'label' => 'In stock', 'type' => 'number'],
                ['key' => 'threshold', 'label' => 'Reorder at', 'type' => 'number'],
                ['key' => 'stock_state', 'label' => 'Stock state', 'type' => 'string'],
                ['key' => 'unit_cost', 'label' => 'Unit price', 'type' => 'money'],
                ['key' => 'stock_value', 'label' => 'Stock value', 'type' => 'money'],
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public function columnKeys(): array
    {
        return array_column($this->columns(), 'key');
    }

    /**
     * A filename stem for an export of this report.
     */
    public function filenameStem(): string
    {
        return str_replace('_', '-', $this->value).'-report';
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'date_scoped' => $case->isDateScoped(),
                'searchable' => $case->isSearchable(),
                'columns' => $case->columns(),
            ],
            self::cases(),
        );
    }
}
