<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\PermissionType;
use App\Events\StockLevelLow;
use App\Models\Admin;
use App\Notifications\AdminLowStockNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Alerts admins holding `view_products` when a stockable crosses into its low
 * band.
 *
 * {@see StockLevelLow} already fires only on the transition into the band —
 * not on every subsequent sale while it stays there, see that event's own
 * docblock — so this listener does not need to re-derive that guarantee. It
 * only has to turn one event into notifications for the right audience.
 */
final class SendLowStockNotification implements ShouldQueue
{
    public function handle(StockLevelLow $event): void
    {
        $notification = new AdminLowStockNotification($event->stockable, $event->remaining, $event->label());

        foreach ($this->adminsToNotify() as $admin) {
            $admin->notify($notification);
        }
    }

    /**
     * @return array<int, Admin>
     */
    private function adminsToNotify(): array
    {
        return Admin::query()
            ->active()
            ->get()
            ->filter(fn (Admin $admin): bool => $admin->hasPermission(PermissionType::ViewProducts))
            ->values()
            ->all();
    }
}
