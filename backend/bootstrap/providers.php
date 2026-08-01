<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Application Service Providers
|--------------------------------------------------------------------------
|
| Laravel merges this list with its own core providers. Listing application
| providers here — rather than under a `providers` key in config/app.php —
| is what keeps the framework's own providers registered; a `providers` key
| in the config replaces the merged list instead of extending it.
|
| Order matters: AuthServiceProvider registers policies and the Super Admin
| gate bypass, and RouteServiceProvider defines the named rate limiters that
| route middleware references.
|
*/

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\DomainServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
];
