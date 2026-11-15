<?php

namespace Tests;

use App\Models\User;
use App\Services\CaptchaService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Headers that make Sanctum treat the request as coming from the SPA (stateful, session cookie auth). */
    protected array $spa = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/', 'Accept' => 'application/json'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeUser(array $roles = ['user'], array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($roles);

        return $user;
    }

    /** Captcha whose answer is always "OK" (the real implementation is tested in CaptchaTest). */
    protected function fakeCaptcha(): void
    {
        $this->app->instance(CaptchaService::class, new class extends CaptchaService
        {
            public function verify(?string $id, ?string $answer): bool
            {
                return $answer === 'OK';
            }
        });
    }

    protected function captchaFields(): array
    {
        return ['captcha_id' => str_repeat('a', 40), 'captcha' => 'OK'];
    }
}
