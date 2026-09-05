@php
    use App\Support\Money;

    /**
     * A report, laid out for print.
     *
     * Deliberately not sharing `documents.partials.styles` with the invoice:
     * that stylesheet is built for a portrait single-order document, and a
     * landscape table of up to fourteen columns needs its own type scale and
     * column handling. Sharing it would mean every future invoice tweak
     * silently re-laying-out every report.
     *
     * Rendering is a straight loop over already-normalised rows — the shaping,
     * casting, and derivation all happened in ReportService. This file decides
     * only how a value looks.
     */
    $money = fn (mixed $amount): string => Money::format((int) ($amount ?? 0), $currencySymbol);

    $render = function (mixed $value, string $type) use ($money): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => $money($value),
            'number' => number_format((int) $value),
            'percent' => number_format((float) $value, 2) . '%',
            'date' => \Illuminate\Support\Carbon::parse((string) $value)->format('j M Y'),
            default => (string) $value,
        };
    };

    $alignment = fn (string $type): string => in_array($type, ['money', 'number', 'percent'], true)
        ? 'right'
        : 'left';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $type->label() }}</title>
    <style>
        @page { margin: 14mm 12mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8.5pt;
            color: #0f172a;
            margin: 0;
        }

        h1 { font-size: 15pt; margin: 0 0 2px; }

        .muted { color: #64748b; }
        .small { font-size: 7.5pt; }

        .header {
            border-bottom: 1.5px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 12px;
            width: 100%;
        }

        .header td { vertical-align: bottom; padding: 0; }

        table.data {
            width: 100%;
            border-collapse: collapse;
        }

        table.data th {
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            padding: 5px 6px;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #334155;
        }

        table.data td {
            border-bottom: 1px solid #e2e8f0;
            padding: 4px 6px;
        }

        /*
         * Repeat the header on every page. Dompdf honours thead on a table
         * that breaks across pages, which is what keeps page four of a sales
         * report readable rather than an unlabelled grid of numbers.
         */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        tfoot td {
            border-top: 1.5px solid #0f172a;
            border-bottom: none;
            padding: 6px;
            font-weight: bold;
            background: #f8fafc;
        }

        .align-right { text-align: right; }
        .align-left { text-align: left; }

        .empty {
            padding: 24px;
            text-align: center;
            color: #64748b;
        }
    </style>
</head>
<body>

<table class="header">
    <tbody>
    <tr>
        <td>
            <h1>{{ $type->label() }}</h1>
            <div class="small muted">{{ $type->description() }}</div>
        </td>
        <td style="text-align: right;">
            <div><strong>{{ $storeName }}</strong></div>
            <div class="small muted">
                @if ($range !== null)
                    {{ $range->from->format('j M Y') }} – {{ $range->to->format('j M Y') }}
                @else
                    As at {{ $generatedAt->format('j M Y') }}
                @endif
            </div>
            <div class="small muted">Generated {{ $generatedAt->format('j M Y, H:i') }}</div>
        </td>
    </tr>
    </tbody>
</table>

<table class="data">
    <thead>
    <tr>
        @foreach ($columns as $column)
            <th class="align-{{ $alignment($column['type']) }}">{{ $column['label'] }}</th>
        @endforeach
    </tr>
    </thead>

    <tbody>
    @forelse ($rows as $row)
        <tr>
            @foreach ($columns as $column)
                <td class="align-{{ $alignment($column['type']) }}">
                    {{ $render($row[$column['key']] ?? null, $column['type']) }}
                </td>
            @endforeach
        </tr>
    @empty
        <tr>
            <td class="empty" colspan="{{ count($columns) }}">
                No records matched this report's filters.
            </td>
        </tr>
    @endforelse
    </tbody>

    @if (! empty($totals))
        <tfoot>
        <tr>
            @foreach ($columns as $index => $column)
                <td class="align-{{ $alignment($column['type']) }}">
                    @if ($index === 0)
                        Total
                    @elseif (array_key_exists($column['key'], $totals))
                        {{ $render($totals[$column['key']], $column['type']) }}
                    @endif
                </td>
            @endforeach
        </tr>
        </tfoot>
    @endif
</table>

</body>
</html>
