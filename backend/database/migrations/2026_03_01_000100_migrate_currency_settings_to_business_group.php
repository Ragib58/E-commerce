<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relocates currency settings from the `general` group to `business`.
 *
 * Currency is a business rule alongside tax and invoice numbering, not a
 * site-wide general default, and keeping both copies would let the two drift
 * apart with no defined winner.
 *
 * The administrator's configured value is carried across rather than dropped:
 * an install that had changed the currency to EUR keeps EUR. The seeder runs
 * after this and fills in any key that is still absent.
 */
return new class extends Migration
{
    /** @var array<string, string> old key => new key */
    private const MOVES = [
        'general.currency' => 'business.currency',
        'general.currency_symbol' => 'business.currency_symbol',
    ];

    public function up(): void
    {
        foreach (self::MOVES as $from => $to) {
            $old = DB::table('settings')->where('key', $from)->first();

            if ($old === null) {
                continue;
            }

            $exists = DB::table('settings')->where('key', $to)->exists();

            if ($exists) {
                // The destination was already seeded. The administrator's
                // value in the old row still wins — it is the one that was
                // in use — unless it is empty.
                if ($old->value !== null && $old->value !== '') {
                    DB::table('settings')
                        ->where('key', $to)
                        ->update(['value' => $old->value, 'updated_at' => now()]);
                }
            } else {
                DB::table('settings')->where('key', $from)->update([
                    'key' => $to,
                    'group' => 'business',
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('settings')->where('key', $from)->delete();
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $from => $to) {
            DB::table('settings')
                ->where('key', $to)
                ->update(['key' => $from, 'group' => 'general', 'updated_at' => now()]);
        }
    }
};
