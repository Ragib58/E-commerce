{{--
    Shared print styles for the invoice and packing slip.

    Deliberately plain CSS in a <style> block rather than the admin panel's
    stylesheet. Two reasons:

      - Dompdf supports a narrow subset of CSS. Flexbox and grid are not in it,
        so the layouts below use tables, which is what actually renders the same
        on screen and on paper.
      - A document must not depend on an external stylesheet. `isRemoteEnabled`
        is off in InvoiceService (see its docblock), so a linked stylesheet would
        silently produce an unstyled PDF.

    @media print rules hide the browser's own furniture when the HTML version is
    sent to a printer, so "Print invoice" from the admin panel produces the same
    page as the download.
--}}
<style>
    @page {
        margin: 18mm 14mm;
    }

    body {
        font-family: "DejaVu Sans", sans-serif;
        font-size: 11px;
        line-height: 1.5;
        color: #1a1a1a;
        margin: 0;
        padding: 0;
    }

    .document {
        max-width: 190mm;
        margin: 0 auto;
    }

    h1 {
        font-size: 20px;
        margin: 0 0 2px;
        font-weight: 700;
    }

    .muted {
        color: #666;
    }

    .small {
        font-size: 10px;
    }

    /* Header: store on the left, document meta on the right. A table because
       Dompdf has no flexbox. */
    table.layout {
        width: 100%;
        border-collapse: collapse;
    }

    table.layout > tbody > tr > td {
        vertical-align: top;
        padding: 0;
    }

    .logo {
        max-height: 48px;
        max-width: 180px;
    }

    .doc-title {
        text-align: right;
    }

    .doc-meta {
        text-align: right;
        margin-top: 6px;
    }

    .doc-meta dt {
        display: inline;
        color: #666;
    }

    .doc-meta dd {
        display: inline;
        margin: 0 0 0 4px;
        font-weight: 600;
    }

    .rule {
        border: 0;
        border-top: 1px solid #ddd;
        margin: 14px 0;
    }

    /* Address blocks, side by side. */
    .address-block {
        width: 50%;
        padding-right: 12px;
    }

    .address-label {
        font-size: 9px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #666;
        margin-bottom: 4px;
        font-weight: 700;
    }

    .address-line {
        margin: 0;
    }

    /* Line items. */
    table.items {
        width: 100%;
        border-collapse: collapse;
        margin-top: 6px;
    }

    table.items th {
        text-align: left;
        font-size: 9px;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #666;
        border-bottom: 1.5px solid #333;
        padding: 6px 6px 6px 0;
        font-weight: 700;
    }

    table.items td {
        padding: 8px 6px 8px 0;
        border-bottom: 1px solid #eee;
        vertical-align: top;
    }

    .num {
        text-align: right;
        white-space: nowrap;
    }

    .centre {
        text-align: center;
    }

    .item-name {
        font-weight: 600;
    }

    .item-meta {
        color: #666;
        font-size: 10px;
    }

    /* Totals, right-aligned under the items. */
    table.totals {
        margin-left: auto;
        margin-top: 10px;
        border-collapse: collapse;
        min-width: 240px;
    }

    table.totals td {
        padding: 4px 0 4px 18px;
    }

    table.totals td.label {
        color: #555;
    }

    table.totals tr.grand td {
        border-top: 1.5px solid #333;
        padding-top: 8px;
        font-size: 14px;
        font-weight: 700;
    }

    table.totals tr.refund td {
        color: #b42318;
    }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border: 1px solid #999;
        border-radius: 10px;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
    }

    .note {
        margin-top: 14px;
        padding: 10px 12px;
        background: #f7f7f7;
        border-left: 3px solid #999;
    }

    .note-label {
        font-size: 9px;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #666;
        font-weight: 700;
        margin-bottom: 2px;
    }

    footer.doc-footer {
        margin-top: 22px;
        padding-top: 10px;
        border-top: 1px solid #ddd;
        color: #777;
        font-size: 9px;
        text-align: center;
    }

    /* Tick boxes for the picker to mark off. Printed empty on purpose. */
    .tickbox {
        display: inline-block;
        width: 12px;
        height: 12px;
        border: 1.5px solid #333;
    }

    @media print {
        .no-print {
            display: none !important;
        }

        body {
            margin: 0;
        }
    }
</style>
