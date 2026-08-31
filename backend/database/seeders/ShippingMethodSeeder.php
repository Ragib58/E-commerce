<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Default delivery methods.
 *
 * These are ordinary editable rows, not fixtures the code depends on. Nothing
 * looks a method up by code — checkout reads whatever is active — so an
 * operator may rename, reprice, deactivate, or delete every one of them and
 * add their own. The seeder exists so a fresh install can complete a checkout
 * without first having to define a delivery service.
 *
 * Idempotent, keyed on `code`, and it **updates only rows it created and that
 * nobody has edited since**: re-running the seeder must never overwrite a
 * price an operator has set. That is the difference between a seeder that is
 * safe to run in production and one that quietly resets the store's shipping
 * rates on every deploy.
 */
final class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->methods() as $method) {
            $existing = ShippingMethod::query()->where('code', $method['code'])->first();

            /*
             * An existing row is left alone entirely.
             *
             * `updateOrCreate` would be the obvious call and the wrong one: it
             * would reset a rate the operator has changed every time anyone
             * runs `db:seed`, which is on every deploy in most setups.
             */
            if ($existing !== null) {
                continue;
            }

            ShippingMethod::query()->create(array_merge($method, [
                'uuid' => (string) Str::uuid(),
            ]));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function methods(): array
    {
        return [
            [
                'name' => 'Standard delivery',
                'code' => 'standard',
                'description' => 'Delivered by post within a few working days.',
                // Minor units, like every other money column in the schema.
                'rate' => 500,
                /*
                 * Free over 50.00. A threshold rather than a flat free option,
                 * because free shipping on a small order is usually sold at a
                 * loss — and per method rather than a single store setting,
                 * since the figure that makes sense for post rarely makes sense
                 * for express.
                 */
                'free_above' => 5_000,
                'min_days' => 3,
                'max_days' => 5,
                'is_active' => true,
                'requires_address' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Express delivery',
                'code' => 'express',
                'description' => 'Next working day for orders placed before 2pm.',
                'rate' => 1_500,
                // Never free: the carrier charges the same regardless of what
                // is in the box.
                'free_above' => null,
                'min_days' => 1,
                'max_days' => 2,
                'is_active' => true,
                'requires_address' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Collect in store',
                'code' => 'collection',
                'description' => 'Collect from our counter. We will email when it is ready.',
                'rate' => 0,
                'free_above' => null,
                'min_days' => 1,
                'max_days' => 2,
                'is_active' => true,
                /*
                 * The customer comes to the goods, so there is nowhere to ship.
                 * Demanding a postcode for a collection is the kind of friction
                 * that reads as a broken form.
                 */
                'requires_address' => false,
                'sort_order' => 30,
            ],
            [
                'name' => 'Digital delivery',
                'code' => 'digital',
                'description' => 'Sent to your email address immediately after purchase.',
                'rate' => 0,
                'free_above' => null,
                'min_days' => null,
                'max_days' => null,
                'is_active' => true,
                'requires_address' => false,
                'sort_order' => 40,
            ],
        ];
    }
}
