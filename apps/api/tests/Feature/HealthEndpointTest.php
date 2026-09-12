<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_the_api_is_available(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'application' => 'MEPA CRM API',
            ]);
    }
}

