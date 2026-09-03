<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Services\SettingsService;
use App\Support\Money;

/**
 * The store name and currency symbol every customer-facing email needs.
 *
 * Read from settings rather than `config('app.name')` alone, because company
 * name is admin-editable data in this project — see the settings phase — and
 * an email signed by a name the operator changed last week would be a visible
 * inconsistency with the storefront the customer is looking at.
 */
trait UsesStoreBranding
{
    protected function companyName(): string
    {
        $name = app(SettingsService::class)->get('general.company_name');

        return is_string($name) && $name !== '' ? $name : (string) config('app.name');
    }

    /**
     * Minor units, formatted with the store's own currency symbol — never
     * computed here, only displayed. See App\Support\Money for why formatting
     * happens exclusively at this kind of boundary.
     */
    protected function money(int $minorUnits): string
    {
        $symbol = app(SettingsService::class)->get('business.currency_symbol', '$');

        return Money::format($minorUnits, is_string($symbol) ? $symbol : '$');
    }
}
