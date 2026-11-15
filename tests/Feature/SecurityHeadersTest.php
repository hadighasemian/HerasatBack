<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_security_headers(): void
    {
        $res = $this->getJson('/api/public/report-categories')->assertOk();
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertNull($res->headers->get('X-Powered-By'));
    }

    public function test_cors_only_allows_configured_origin(): void
    {
        $ok = $this->withHeaders(['Origin' => 'http://localhost:3000'])->getJson('/api/public/report-categories');
        $this->assertSame('http://localhost:3000', $ok->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $ok->headers->get('Access-Control-Allow-Credentials'));

        $evil = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/public/report-categories');
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_no_debug_information_leaks_on_errors(): void
    {
        config(['app.debug' => false]);
        $res = $this->getJson('/api/reports/999999')->assertStatus(401);
        $this->assertStringNotContainsString('vendor', $res->getContent());
        $this->getJson('/api/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }
}
