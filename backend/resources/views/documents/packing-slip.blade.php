{{--
    The packing slip — a warehouse instruction, not a financial document.

    **There are no prices on this page, and there must not be.** A packing slip
    goes in the box, and a gift order that arrives with the price printed on the
    note inside is a real complaint. The template simply has no access to a
    money value: InvoiceService::packingSlipData does not pass one, so a price
    cannot be added here by accident.

    Also absent: the billing address (irrelevant to whoever packs the box) and
    `admin_note` (internal). The customer's own note *is* printed — "leave with
    the neighbour" is exactly what the person carrying the parcel needs.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Packing slip {{ $order->order_number }}</title>
    @include('documents.partials.styles')
</head>
<body>
<div class="document">

    <table class="layout">
        <tbody>
        <tr>
            <td style="width: 55%;">
                @if (! empty($store['logo']))
                    <img src="{{ $store['logo'] }}" alt="{{ $store['name'] }}" class="logo">
                @else
                    <h1>{{ $store['name'] }}</h1>
                @endif
            </td>

            <td class="doc-title">
                <h1>Packing slip</h1>

                <dl class="doc-meta small">
                    <div><dt>Order</dt><dd>{{ $order->order_number }}</dd></div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ optional($order->placed_at ?? $order->created_at)->format('j M Y') }}</dd>
                    </div>
                    @if ($order->shipping_method_name)
                        <div><dt>Delivery</dt><dd>{{ $order->shipping_method_name }}</dd></div>
                    @endif
                </dl>
            </td>
        </tr>
        </tbody>
    </table>

    <hr class="rule">

    <table class="layout">
        <tbody>
        <tr>
            <td class="address-block">
                <div class="address-label">Deliver to</div>
                @if ($shipping !== null)
                    @foreach ($shipping->lines() as $line)
                        <p class="address-line">{{ $line }}</p>
                    @endforeach
                    @if ($shipping->phone)
                        <p class="address-line muted small">{{ $shipping->phone }}</p>
                    @endif
                @else
                    {{-- A digital-only order has nowhere to ship. Saying so is
                         clearer than an empty block that reads as missing data
                         to whoever picks it up. --}}
                    <p class="address-line muted">No delivery address — digital order.</p>
                @endif
            </td>

            <td class="address-block">
                @if ($shipping !== null && $shipping->delivery_instructions)
                    <div class="address-label">Delivery instructions</div>
                    <p class="address-line">{{ $shipping->delivery_instructions }}</p>
                @endif
            </td>
        </tr>
        </tbody>
    </table>

    {{-- Items to pick. Quantity and SKU are the load-bearing columns; the tick
         column is for the picker to mark off by hand, which is what keeps a
         part-picked order recoverable if they are interrupted. --}}
    <table class="items">
        <thead>
        <tr>
            <th class="centre" style="width: 6%;">✓</th>
            <th style="width: 52%;">Item</th>
            <th style="width: 24%;">SKU</th>
            <th class="num" style="width: 18%;">Quantity</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td class="centre"><span class="tickbox"></span></td>
                <td>
                    <div class="item-name">{{ $item->product_name }}</div>

                    @if ($item->variant_name)
                        <div class="item-meta">{{ $item->variant_name }}</div>
                    @endif

                    {{-- Personalisation is a picking instruction here: an
                         engraving or a gift message is work someone has to do
                         before the box is sealed. --}}
                    @if (! empty($item->options))
                        @foreach ($item->options as $key => $value)
                            <div class="item-meta"><strong>{{ $key }}:</strong> {{ $value }}</div>
                        @endforeach
                    @endif
                </td>
                <td class="small">{{ $item->product_sku ?? '—' }}</td>
                <td class="num" style="font-size: 14px; font-weight: 700;">{{ $item->quantity }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tbody>
        <tr class="grand">
            <td>Total units</td>
            <td class="num">{{ $items->sum('quantity') }}</td>
        </tr>
        </tbody>
    </table>

    @if ($customer_note)
        <div class="note">
            <div class="note-label">Note from the customer</div>
            <div>{{ $customer_note }}</div>
        </div>
    @endif

    <footer class="doc-footer">
        <div>{{ $store['name'] }}</div>
        <div>Packed from order {{ $order->order_number }} — {{ $generated_at->format('j M Y, H:i') }}</div>
    </footer>

</div>
</body>
</html>
