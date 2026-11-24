<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_frontend_preflight_allows_admin_methods_and_bearer_tokens(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        $response = $this->call('OPTIONS', '/api/admin/documents/66f0000000000000000000a1', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent();
        $this->assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('DELETE', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('PATCH', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsStringIgnoringCase('authorization', $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_download_filename_header_is_exposed_to_the_frontend(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        $this->getJson('/api/documents', ['Origin' => 'http://localhost:3000'])
            ->assertHeader('Access-Control-Expose-Headers');
        $this->assertStringContainsString('Content-Disposition', $this->getJson('/api/documents', ['Origin' => 'http://localhost:3000'])->headers->get('Access-Control-Expose-Headers'));
    }
}
