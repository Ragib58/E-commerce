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
