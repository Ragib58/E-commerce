<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CustomerRegistered;
use App\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sends the welcome email when a customer account is created.
 *
 * {@see CustomerRegistered}'s own docblock names this listener's job
 * explicitly: "the hook for welcome sequences". Kept separate from Laravel's
 * built-in `Registered` event, which triggers email verification through the
 * framework's own listener — a customer receives both, for two different
 * reasons, and conflating them into one email would mean either burying the
 * verification link under welcome copy or delaying it until this listener
 * runs.
 */
final class SendWelcomeNotification implements ShouldQueue
{
    public function handle(CustomerRegistered $event): void
    {
        $event->user->notify(new WelcomeNotification);
    }
}
