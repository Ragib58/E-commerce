<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiter counters live in the cache, and the `array` store used
        // in tests is per-application-instance — but a test that exhausts a
        // limiter and one that expects a fresh budget can still collide when
        // they share a key (email+IP, and every test uses 127.0.0.1).
        // Flushing here guarantees each test starts with its full allowance,
        // so a throttling assertion measures this test's requests only.
        $this->app->make('cache')->flush();
    }

    /**
     * Forget resolved guards between requests within a single test.
     *
     * Laravel's testing harness reuses one application instance for every
     * request a test makes, and the AuthManager caches the user it resolved on
     * the first one. A second request therefore reuses that cached principal
     * even when the token backing it has since been deleted — so a test
     * asserting "a revoked token no longer authenticates" would wrongly pass
     * the request through.
     *
     * This is purely a harness artifact: in production every request boots a
     * fresh container, so the guard has nothing to cache. Clearing it here
     * makes the test environment match that behaviour, which is what lets
     * these assertions actually mean something:
     *
     *   - a revoked token stops working after logout
     *   - a deactivated admin is blocked on their very next request
     *   - a role change takes effect immediately
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call(
        $method,
        $uri,
        $parameters = [],
        $cookies = [],
        $files = [],
        $server = [],
        $content = null,
    ): TestResponse {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
