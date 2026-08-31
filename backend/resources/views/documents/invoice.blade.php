@php
    use App\Support\Money;

    /**
     * The invoice — a financial document.
     *
     * Every value rendered here comes from the order's own snapshot columns,
     * never from the live catalog. See InvoiceService: a product renamed since
     * placement must not change what this invoice says.
     */
    $money = fn (int $amount): string => Money::format($amount, $symbol);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice_number }}</title>
    @include('documents.partials.styles')
</head>
<body>
<div class="document">

    {{-- Header: store identity on the left, document identity on the right. --}}
    <table class="layout">
        <tbody>
        <tr>
            <td style="width: 55%;">
                @if (! empty($store['logo']))
                    <img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}" class="logo">
                @else
                    <h1>{{ $store['name'] }}</h1>
                @endif

                <div class="small muted" style="margin-top: 6px;">
                    @if (! empty($store['address']))
                        <div>{{ $store['address'] }}</div>
                    @endif
                    @if (! empty($store['email']))
                        <div>{{ $store['email'] }}</div>
                    @endif
                    @if (! empty($store['phone']))
                        <div>{{ $store['phone'] }}</div>
                    @endif
                    @if (! empty($store['vat_number']))
                        <div>VAT {{ $store['vat_number'] }}</div>
                    @endif
                </div>
            </td>

            <td class="doc-title">
                <h1>Invoice</h1>

                <dl class="doc-meta small">
                    <div><dt>Invoice</dt><dd>{{ $invoice_number }}</dd></div>
                    <div><dt>Order</dt><dd>{{ $order->order_number }}</dd></div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ optional($order->placed_at ?? $order->created_at)->format('j M Y') }}</dd>
                    </div>
                    <div>
                        <dt>Payment</dt>
                        <dd>{{ $order->payment_status->label() }}</dd>
                    </div>
                </dl>

                <div style="margin-top: 8px;">
                    <span class="badge">{{ $order->status->label() }}</span>
                </div>
            </td>
        </tr>
        </tbody>
    </table>

    <hr class="rule">

    {{-- Addresses. The billing block is the one that matters on an invoice;
         the shipping block is printed beside it only when it differs, so a
         single-address order does not waste half a page on a duplicate. --}}
    <table class="layout">
        <tbody>
        <tr>
            <td class="address-block">
                <div class="address-label">Billed to</div>
                @if ($billing !== null)
                    @foreach ($billing->lines() as $line)
                        <p class="address-line">{{ $line }}</p>
                    @endforeach
                    @if ($billing->phone)
                        <p class="address-line muted small">{{ $billing->phone }}</p>
                    @endif
                @else
                    <p class="address-line">{{ $order->customer_name }}</p>
                @endif
                <p class="address-line muted small">{{ $order->customer_email }}</p>
            </td>

            <td class="address-block">
                @if ($shipping !== null && ! $addresses_match)
                    <div class="address-label">Shipped to</div>
                    @foreach ($shipping->lines() as $line)
                        <p class="address-line">{{ $line }}</p>
                    @endforeach
                    @if ($shipping->phone)
                        <p class="address-line muted small">{{ $shipping->phone }}</p>
                    @endif
                @elseif ($addresses_match)
                    <div class="address-label">Shipped to</div>
                    <p class="address-line muted">Same as billing address.</p>
                @endif

                @if ($order->shipping_method_name)
                    <p class="address-line small muted" style="margin-top: 8px;">
                        Delivery: {{ $order->shipping_method_name }}
                    </p>
                @endif
                @if ($order->tracking_number)
                    <p class="address-line small muted">
                        Tracking: {{ $order->tracking_number }}
                    </p>
                @endif
            </td>
        </tr>
        </tbody>
    </table>

    {{-- Line items. --}}
    <table class="items">
        <thead>
        <tr>
            <th style="width: 46%;">Item</th>
            <th style="width: 14%;">SKU</th>
            <th class="num" style="width: 8%;">Qty</th>
            <th class="num" style="width: 14%;">Unit</th>
            @if ($shows_discount)
                <th class="num" style="width: 10%;">Discount</th>
            @endif
            <th class="num" style="width: 14%;">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>
                    <div class="item-name">{{ $item->product_name }}</div>

                    @if ($item->variant_name)
                        <div class="item-meta">{{ $item->variant_name }}</div>
                    @endif

                    {{-- Personalisation captured at checkout — an engraving, a
                         gift message. Printed because the customer paid for it
                         and should see it itemised. --}}
                    @if (! empty($item->options))
                        @foreach ($item->options as $key => $value)
                            <div class="item-meta">{{ $key }}: {{ $value }}</div>
                        @endforeach
                    @endif

                    @if ($item->refunded_quantity > 0)
                        <div class="item-meta" style="color: #b42318;">
                            {{ $item->refunded_quantity }} refunded
                        </div>
                    @endif
                </td>

                <td class="small muted">{{ $item->product_sku ?? '—' }}</td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">
                    {{ $money((int) $item->unit_price) }}
                    @if ($item->list_price && $item->list_price > $item->unit_price)
                        <div class="item-meta" style="text-decoration: line-through;">
                            {{ $money((int) $item->list_price) }}
                        </div>
                    @endif
                </td>
                @if ($shows_discount)
                    <td class="num">
                        {{ $item->discount_total > 0 ? '−' . $money((int) $item->discount_total) : '—' }}
                    </td>
                @endif
                <td class="num">{{ $money((int) $item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    {{-- Totals. The identity asserted by Order::totalsReconcile():
         subtotal + tax + shipping = grand total. --}}
    <table class="totals">
        <tbody>
        <tr>
            <td class="label">Subtotal</td>
            <td class="num">{{ $money((int) $order->subtotal) }}</td>
        </tr>

        @if ($shows_discount && $order->discount_total > 0)
            <tr>
                <td class="label">Discount</td>
                <td class="num">−{{ $money((int) $order->discount_total) }}</td>
            </tr>
        @endif

        @if ($shows_tax)
            <tr>
                <td class="label">
                    Tax
                    @if ($order->tax_rate > 0)
                        <span class="muted small">({{ rtrim(rtrim(number_format((float) $order->tax_rate, 2), '0'), '.') }}%)</span>
                    @endif
                </td>
                <td class="num">{{ $money((int) $order->tax_total) }}</td>
            </tr>
        @endif

        <tr>
            <td class="label">Shipping</td>
            <td class="num">
                {{ (int) $order->shipping_total === 0 ? 'Free' : $money((int) $order->shipping_total) }}
            </td>
        </tr>

        <tr class="grand">
            <td>Total</td>
            <td class="num">{{ $money((int) $order->grand_total) }}</td>
        </tr>

        @if ($refunded > 0)
            <tr class="refund">
                <td class="label">Refunded</td>
                <td class="num">−{{ $money($refunded) }}</td>
            </tr>
            <tr>
                <td class="label">Net paid</td>
                <td class="num">{{ $money((int) $order->grand_total - $refunded) }}</td>
            </tr>
        @endif
        </tbody>
    </table>

    {{-- The customer's own note. `admin_note` is deliberately absent — an
         internal comment must never reach a document the customer receives. --}}
    @if ($order->customer_note)
        <div class="note">
            <div class="note-label">Order note</div>
            <div>{{ $order->customer_note }}</div>
        </div>
    @endif

    <footer class="doc-footer">
        <div>{{ $store['name'] }} — {{ $order->payment_method->label() }}</div>
        <div>Generated {{ $generated_at->format('j M Y, H:i') }}</div>
    </footer>

</div>
</body>
</html>
