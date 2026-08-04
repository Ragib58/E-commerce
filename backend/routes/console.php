<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
|
| Driven by a single `schedule:work` process in the `scheduler` container.
| `withoutOverlapping` guards against a slow run colliding with the next tick;
| `onOneServer` keeps a task single-fire when the app is scaled horizontally
| (requires a shared cache lock, which Redis provides).
|
*/

// Expired Sanctum tokens accumulate indefinitely otherwise.
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

// Bounded failed-job retention keeps the table from growing without limit.
Schedule::command('queue:prune-failed --hours=336')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('queue:prune-batches --hours=336')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

/*
 * Abandoned guest carts.
 *
 * Every anonymous visitor who adds an item creates a row whose only key lives
 * in one browser's cookie; once that is gone the row is unreachable by anyone.
 * Signed-in customers' carts are never touched — see the command.
 *
 * Scheduled off-peak: the deletion cascades into cart_items, and doing that
 * during trading hours competes with live shoppers for the same tables.
 */
Schedule::command('carts:prune')
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping();
