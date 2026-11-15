<?php

namespace Tests\Feature;

use App\Services\CaptchaService;
use Tests\TestCase;

class CaptchaTest extends TestCase
{
    private function fixedCaptcha(string $code): CaptchaService
    {
        $svc = new class($code) extends CaptchaService
        {
            public function __construct(private string $fixed) {}

            protected function generateCode(): string
            {
                return $this->fixed;
            }
        };
        $this->app->instance(CaptchaService::class, $svc);

        return $svc;
    }

    public function test_endpoint_returns_png_and_never_the_answer(): void
    {
        $this->fixedCaptcha('AB7KQ');
        $res = $this->getJson('/api/public/captcha')->assertOk()->assertJsonStructure(['id', 'image', 'expires_in']);
        $this->assertStringStartsWith('data:image/png;base64,', $res->json('image'));
        $png = base64_decode(substr($res->json('image'), 22));
        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertStringNotContainsString('AB7KQ', $res->getContent());
    }

    public function test_verify_is_case_insensitive_and_single_use(): void
    {
        $svc = $this->fixedCaptcha('AB7KQ');
        $c = $svc->create();
        $this->assertFalse($svc->verify($c['id'], 'WRONG'));
        $this->assertFalse($svc->verify($c['id'], 'AB7KQ')); // consumed by the wrong attempt
        $c2 = $svc->create();
        $this->assertTrue($svc->verify($c2['id'], 'ab7kq'));
        $this->assertFalse($svc->verify($c2['id'], 'ab7kq')); // single use
        $this->assertFalse($svc->verify('../../etc', 'x'));
        $this->assertFalse($svc->verify(null, null));
    }

    public function test_captcha_expires(): void
    {
        $svc = $this->fixedCaptcha('AB7KQ');
        $c = $svc->create();
        $this->travel(301)->seconds();
        $this->assertFalse($svc->verify($c['id'], 'AB7KQ'));
    }

    public function test_report_submission_requires_valid_captcha(): void
    {
        $this->fixedCaptcha('AB7KQ');
        $payload = ['title' => 'گزارش آزمایشی', 'description' => str_repeat('توضیح کامل ماجرا ', 3), 'category' => 'other'];
        $this->postJson('/api/public/reports', $payload)->assertStatus(422)->assertJsonValidationErrors('captcha');
        $c = app(CaptchaService::class)->create();
        $this->postJson('/api/public/reports', $payload + ['captcha_id' => $c['id'], 'captcha' => 'XXXXX'])->assertStatus(422)->assertJsonValidationErrors('captcha');
        $this->travel(61)->seconds();
        $c = app(CaptchaService::class)->create();
        $this->postJson('/api/public/reports', $payload + ['captcha_id' => $c['id'], 'captcha' => 'AB7KQ'])->assertCreated();
    }

    public function test_captcha_endpoint_is_rate_limited(): void
    {
        $codes = [];
        for ($i = 0; $i < 32; $i++) {
            $codes[] = $this->getJson('/api/public/captcha')->status();
        }
        $this->assertSame(429, end($codes));
    }
}
