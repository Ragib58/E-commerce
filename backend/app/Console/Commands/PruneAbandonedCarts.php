<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes guest carts nobody has touched in a long time.
 *
 * Every anonymous visitor who adds an item creates a row, and its only key is a
 * token held in one browser. Once that browser forgets the cookie the row is
 * unreachable by anyone, forever — so without this the table grows without
 * bound and carries `cart_items` rows referencing live products with it.
 *
 * **Only guest carts are eligible.** A signed-in customer's cart is theirs to
 * return to months later, and deleting it would be destroying data they can
 * still reach. That distinction is enforced by the model's `abandonedGuest`
 * scope rather than by a condition here, so no caller can widen it by accident.
 */
final class PruneAbandonedCarts extends Command
{
    /**
     * The default retention is generous on purpose. A cart abandoned for a
     * fortnight is almost certainly gone, but the cost of keeping it another
     * week is a handful of rows against the cost of destroying a basket
     * somebody was going to come back to.
     */
    protected $signature = 'carts:prune
                            {--days=30 : Delete guest carts untouched for this many days}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete abandoned guest carts and their items.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);

        $query = Cart::query()->abandonedGuest($cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No abandoned guest carts to prune.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d guest cart(s) untouched since %s would be deleted.',
                $count,
                $cutoff->toDateTimeString(),
            ));

            return self::SUCCESS;
        }

        /*
         * Deleted in chunks rather than one statement.
         *
         * A single DELETE over months of accumulated rows holds locks on the
         * carts table for as long as it takes, and `cart_items` cascades
         * beneath it — on a busy store that is a stall on the hot path for
         * every shopper. Chunking keeps each transaction short.
         */
        $deleted = 0;

        $query->select('id')->chunkById(500, function ($carts) use (&$deleted): void {
            $ids = $carts->pluck('id')->all();

            // Items go with the cart via the FK's cascade, so deleting the
            // parent is sufficient and leaves no orphans.
            $deleted += Cart::query()->whereIn('id', $ids)->delete();
        });

        $this->info(sprintf('Pruned %d abandoned guest cart(s).', $deleted));

        return self::SUCCESS;
    }
}
