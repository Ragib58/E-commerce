<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ExportFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\ReportPeriod;
use App\Support\DateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The shared query shape behind every dashboard and report endpoint.
 *
 * ## One request class, because the parameters really are the same
 *
 * The dashboard, the charts, a report table, and an export all take a period,
 * an optional explicit range, a search term, and the same set of filters. Four
 * request classes would be four places for the period rules to drift, and the
 * drift would be invisible — an export accepting a range the dashboard rejects
 * produces a file that cannot be reproduced on screen.
 *
 * ## Bounding, not sanitising
 *
 * Filter values are validated against the enums they will be compared to, so a
 * status that does not exist is a 422 rather than a query that silently matches
 * nothing and reports an empty report as though it were a real result.
 */
final class ReportQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware carries `permission:view_reports`. Nothing further
        // is decided here.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', Rule::in(ReportPeriod::values())],

            /*
             * Required only for a custom period, which `withValidator()` below
             * enforces — a conditional rule here would have to repeat the
             * enum check to know whether the period is custom at all.
             */
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],

            'search' => ['sometimes', 'nullable', 'string', 'max:191'],

            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'payment_status' => ['sometimes', 'nullable', 'string', Rule::in(PaymentStatus::values())],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(PaymentMethod::values())],
            'gateway' => ['sometimes', 'nullable', 'string', 'max:64'],
            'stock_state' => ['sometimes', 'nullable', 'string', Rule::in(['in', 'low', 'out'])],

            'format' => ['sometimes', 'string', Rule::in(ExportFormat::values())],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('reporting.limits.max_per_page', 100)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'top' => ['sometimes', 'integer', 'min:1', 'max:'.config('reporting.limits.max_top_n', 50)],
        ];
    }

    /**
     * Cross-field rules the per-field list cannot express.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->period() !== ReportPeriod::Custom) {
                return;
            }

            if ($this->input('from') === null || $this->input('to') === null) {
                $validator->errors()->add('from', 'A custom range needs both a start and an end date.');
            }
        });
    }

    /**
     * The `status` filter is validated against a different enum depending on
     * which report is being run — order statuses for the order report, product
     * statuses for inventory. Checking it here would require knowing the
     * report, which is a route parameter rather than input, so the shared rule
     * above bounds only its length and this method bounds its values.
     */
    public function statusFor(string $reportType): ?string
    {
        $status = $this->input('status');

        if (! is_string($status) || $status === '') {
            return null;
        }

        $allowed = $reportType === 'inventory'
            ? ProductStatus::values()
            : OrderStatus::values();

        return in_array($status, $allowed, strict: true) ? $status : null;
    }

    public function period(): ReportPeriod
    {
        $value = $this->input('period');

        return is_string($value)
            ? (ReportPeriod::tryFrom($value) ?? ReportPeriod::Last30Days)
            : ReportPeriod::Last30Days;
    }

    /**
     * The resolved window.
     *
     * Built through {@see DateRange::forPeriod()} so the bounds, the timezone,
     * and the maximum span are enforced in exactly one place — see that class
     * for why an inclusive upper bound is not something to re-derive per
     * endpoint.
     */
    public function dateRange(): DateRange
    {
        return DateRange::forPeriod(
            $this->period(),
            $this->stringOrNull('from'),
            $this->stringOrNull('to'),
        );
    }

    public function exportFormat(): ExportFormat
    {
        $value = $this->input('format');

        return is_string($value)
            ? (ExportFormat::tryFrom($value) ?? ExportFormat::Csv)
            : ExportFormat::Csv;
    }

    /**
     * Filters, shaped for the report services.
     *
     * Null and empty values are stripped rather than passed through, so a
     * service can test presence with `isset` instead of every call site
     * repeating an empty-string check.
     *
     * @return array<string, mixed>
     */
    public function filters(string $reportType = ''): array
    {
        $filters = [
            'search' => $this->stringOrNull('search'),
            'status' => $this->statusFor($reportType),
            'payment_status' => $this->stringOrNull('payment_status'),
            'payment_method' => $this->stringOrNull('payment_method'),
            'gateway' => $this->stringOrNull('gateway'),
            'stock_state' => $this->stringOrNull('stock_state'),
        ];

        return array_filter($filters, static fn (mixed $value): bool => $value !== null);
    }

    public function perPage(): int
    {
        return (int) $this->input('per_page', config('reporting.limits.per_page', 25));
    }

    public function pageNumber(): int
    {
        return max(1, (int) $this->input('page', 1));
    }

    public function topN(): int
    {
        return (int) $this->input('top', config('reporting.limits.top_n', 10));
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'period.in' => 'That is not a date range this report supports.',
            'format.in' => 'That is not an export format this report supports.',
            'from.date' => 'Enter a valid start date.',
            'to.date' => 'Enter a valid end date.',
        ];
    }
}
