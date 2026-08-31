<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AddressType;
use App\Models\Order;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Invoices and packing slips.
 *
 * ## One document, two renderings
 *
 * Each document is a Blade view rendered to HTML, and the PDF is that same HTML
 * run through Dompdf. Not two templates: an invoice whose printed copy and PDF
 * can differ is an invoice that will eventually differ, and the discrepancy
 * surfaces in a tax audit rather than in review.
 *
 * ## Everything is read from the order's snapshot
 *
 * Nothing here joins the live catalog. Product names, skus, variant labels, and
 * prices come from `order_items`, and addresses from `order_addresses` — the
 * copies captured at placement. A product renamed or archived since must not
 * change what an existing invoice says, and reading through the relations would
 * silently rewrite history the moment anyone edits the catalog.
 *
 * ## Invoice and packing slip carry different data on purpose
 *
 * The invoice is a financial document: prices, tax, totals, the billing
 * address. The packing slip is a warehouse instruction: quantities, skus, and
 * the shipping address, with **no prices at all**. That is not a stylistic
 * choice — a packing slip goes in the box, and a gift order that arrives with
 * the price printed on the note inside is a real complaint.
 *
 * Internal notes appear on neither. See OrderNote's migration.
 */
final class InvoiceService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OrderNumberGenerator $numbers,
    ) {}

    /**
     * The invoice as HTML, ready to print.
     */
    public function invoiceHtml(Order $order): string
    {
        return View::make('documents.invoice', $this->invoiceData($order))->render();
    }

    /**
     * The invoice as a PDF.
     *
     * @return string Raw PDF bytes.
     */
    public function invoicePdf(Order $order): string
    {
        return $this->toPdf($this->invoiceHtml($order));
    }

    /**
     * The packing slip as HTML.
     */
    public function packingSlipHtml(Order $order): string
    {
        return View::make('documents.packing-slip', $this->packingSlipData($order))->render();
    }

    /**
     * The packing slip as a PDF.
     *
     * @return string Raw PDF bytes.
     */
    public function packingSlipPdf(Order $order): string
    {
        return $this->toPdf($this->packingSlipHtml($order));
    }

    /**
     * The filename a download should be saved as.
     *
     * Built from the order number, which is already constrained to safe
     * characters by OrderNumberGenerator — so an admin-configured prefix cannot
     * produce a filename containing a slash or a quote.
     */
    public function filename(Order $order, string $document = 'invoice'): string
    {
        return sprintf('%s-%s.pdf', $document, strtolower($order->order_number));
    }

    /*
    |--------------------------------------------------------------------------
    | Document data
    |--------------------------------------------------------------------------
    */

    /**
     * Everything the invoice template renders.
     *
     * Assembled here rather than in the view so the template stays a layout and
     * the decisions — which address, whether to show a discount column — are
     * testable without rendering HTML.
     *
     * @return array<string, mixed>
     */
    public function invoiceData(Order $order): array
    {
        $order->loadMissing(['items', 'addresses', 'payments', 'refunds']);

        $billing = $order->addresses->firstWhere('type', AddressType::Billing);
        $shipping = $order->addresses->firstWhere('type', AddressType::Shipping);

        return [
            'order' => $order,
            'items' => $order->items,
            'invoice_number' => $this->numbers->invoiceNumber($order),

            'billing' => $billing,
            'shipping' => $shipping,
            /*
             * Whether the two blocks are worth printing separately. Compared on
             * the postal fields only, so a different recipient name at the same
             * address does not waste half a page on a duplicate block.
             */
            'addresses_match' => $billing !== null && $billing->matches($shipping),

            'store' => $this->storeDetails(),
            'currency' => $order->currency,
            'symbol' => (string) $this->settings->get('business.currency_symbol', '$'),

            // Only rendered when something was actually discounted; an
            // all-zero column on every invoice is noise.
            'shows_discount' => (int) $order->discount_total > 0
                || $order->items->contains(fn ($item): bool => (int) $item->discount_total > 0),

            'shows_tax' => (int) $order->tax_total > 0,
            'refunded' => (int) $order->refunded_total,
            'generated_at' => now(),
        ];
    }

    /**
     * Everything the packing slip renders.
     *
     * Note what is absent: prices, totals, tax, the billing address, and the
     * internal note. See the class docblock.
     *
     * @return array<string, mixed>
     */
    public function packingSlipData(Order $order): array
    {
        $order->loadMissing(['items', 'addresses']);

        return [
            'order' => $order,
            'items' => $order->items,
            'shipping' => $order->addresses->firstWhere('type', AddressType::Shipping),
            'store' => $this->storeDetails(),

            /*
             * The customer's own note travels with the parcel — "leave with the
             * neighbour" is exactly what the person carrying the box needs.
             * `admin_note` deliberately does not.
             */
            'customer_note' => $order->customer_note,

            'generated_at' => now(),
        ];
    }

    /**
     * The store's own details, from settings.
     *
     * Read at render time rather than snapshotted onto the order: unlike the
     * customer's address, the store's own is not part of what was agreed, and a
     * business that moves premises wants its current address on a reprint.
     *
     * @return array<string, mixed>
     */
    private function storeDetails(): array
    {
        return [
            'name' => (string) $this->settings->get('company.name', 'Store'),
            'logo' => $this->settings->get('company.logo'),
            'email' => $this->settings->get('contact.email'),
            'phone' => $this->settings->get('contact.phone'),
            'address' => $this->settings->get('contact.address'),
            'vat_number' => $this->settings->get('business.vat_number'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    */

    /**
     * Render HTML to PDF bytes.
     *
     * Dompdf is resolved by class name rather than imported, so this file still
     * parses and the HTML paths still work when the package is absent — a store
     * that never downloads a PDF should not fail to boot over it. The failure,
     * when it comes, names the missing package and the command that installs it
     * rather than surfacing a class-not-found from inside a controller.
     *
     * @throws RuntimeException when the PDF library is not installed.
     */
    private function toPdf(string $html): string
    {
        if (! class_exists(Dompdf::class)) {
            throw new RuntimeException(
                'PDF generation requires the dompdf/dompdf package. Run: composer require dompdf/dompdf',
            );
        }

        $options = new Options;

        /*
         * Remote resources stay off.
         *
         * The template renders an order containing customer-supplied strings.
         * With remote loading enabled, a crafted value that reaches an `img`
         * src or a CSS url() would make the PDF renderer issue HTTP requests
         * from inside the network — server-side request forgery via an invoice.
         * The logo is inlined as a data URI instead.
         */
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        if ($output === null) {
            throw new RuntimeException('The PDF could not be generated.');
        }

        return $output;
    }

    /**
     * Whether PDF rendering is available in this installation.
     *
     * Read by the controller so the API can answer with a clear 503 rather than
     * a 500 when the optional package is missing.
     */
    public function supportsPdf(): bool
    {
        return class_exists(Dompdf::class);
    }
}
