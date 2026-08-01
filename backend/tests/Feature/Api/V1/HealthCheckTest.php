<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function liveness_endpoint_returns_the_success_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['status', 'timestamp'],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');
    }

    #[Test]
    public function readiness_endpoint_probes_every_dependency(): void
    {
        $response = $this->getJson('/api/v1/health/ready');

        $response
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'status',
                    'checks' => [
                        'database' => ['status', 'critical', 'latency_ms'],
                        'cache' => ['status', 'critical', 'latency_ms'],
                    ],
                ],
                'meta' => ['application', 'environment', 'php_version', 'duration_ms'],
            ]);

        // The database and cache are backed by SQLite and the array store in
        // the test environment, so both must genuinely pass.
        $response->assertJsonPath('data.checks.database.status', 'ok');
        $response->assertJsonPath('data.checks.cache.status', 'ok');
    }

    #[Test]
    public function every_api_response_carries_version_and_request_id_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertHeader('X-API-Version', 'v1')
            ->assertHeader('X-API-Supported-Versions', 'v1');

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    #[Test]
    public function a_client_supplied_request_id_is_echoed_back(): void
    {
        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => 'trace-abc-123']);

        $response->assertHeader('X-Request-Id', 'trace-abc-123');
    }

    #[Test]
    public function an_unknown_api_path_returns_the_error_envelope_not_html(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['success', 'message', 'code']);
    }

    #[Test]
    public function an_api_request_without_a_json_accept_header_still_receives_json(): void
    {
        // Simulates a browser address bar hitting the API: without the
        // ForceJsonResponse middleware this would render an HTML error page.
        $response = $this->get('/api/v1/does-not-exist', ['Accept' => 'text/html']);

        $response->assertHeader('content-type', 'application/json');
        $response->assertJsonPath('success', false);
    }
}
