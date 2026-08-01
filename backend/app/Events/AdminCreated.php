<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AdminCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Admin $admin,
        public readonly Admin $createdBy,
    ) {
    }
}
