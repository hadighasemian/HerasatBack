<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function login(string $username, string $password, array $extra = [])
    {
        return $this->withHeaders($this->spa)->postJson('/api/auth/login', ['username' => $username, 'password' => $password] + $extra);
    }

    public function test_there_is_no_registration_or_password_reset_endpoint(): void
    {
        foreach (['/api/register', '/api/auth/register', '/api/auth/forgot-password', '/register'] as $url) {
            $this->postJson($url, ['username' => 'x', 'password' => 'y'])->assertStatus(404);
        }
    }

    public function test_user_logs_in_with_personnel_code_and_national_code_by_default(): void
    {
        $nc = UserFactory::validNationalCode();
        $user = User::factory()->mustChangePassword()->create(['personnel_code' => '123456']);
        $user->setNationalCode($nc);
        $user->password = $nc;
        $user->save();
        $user->assignRole('user');

        $res = $this->login('123456', $nc)->assertOk()
            ->assertJsonPath('user.personnel_code', '123456')
            ->assertJsonPath('user.must_change_password', true);
        $this->assertArrayNotHasKey('national_code', $res->json('user'));
        $this->assertArrayNotHasKey('password', $res->json('user'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertNotSame($nc, $user->fresh()->getRawOriginal('password'));
        $this->assertTrue(Hash::check($nc, $user->fresh()->password));
    }

    public function test_login_accepts_persian_digits(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->login('۱۲۳۴۵۶', 'Str0ng!Passw0rd')->assertOk();
    }

    public function test_login_requires_spa_session(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->postJson('/api/auth/login', ['username' => '123456', 'password' => 'Str0ng!Passw0rd'])
            ->assertStatus(400)->assertJsonPath('code', 'stateful_required');
    }

    public function test_wrong_password_and_unknown_user_return_the_same_generic_error(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $a = $this->login('123456', 'wrong-password')->assertStatus(422);
        $b = $this->login('999999', 'wrong-password')->assertStatus(422);
        $this->assertSame($a->json('message'), $b->json('message'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $u = $this->makeUser(['user'], ['personnel_code' => '123456']);
        $u->is_active = false;
        $u->save();
        $this->login('123456', 'Str0ng!Passw0rd')->assertStatus(422);
    }

    public function test_captcha_is_required_after_repeated_failures(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->login('123456', 'bad1')->assertStatus(422);
        $this->login('123456', 'bad2')->assertStatus(422)->assertJsonPath('captcha_required', true);
        // even the correct password is refused without a captcha now
        $this->login('123456', 'Str0ng!Passw0rd')->assertStatus(422)->assertJsonValidationErrors('captcha');
        // with a valid captcha it works
        $this->fakeCaptcha();
        $this->login('123456', 'Str0ng!Passw0rd', $this->captchaFields())->assertOk();
    }

    public function test_account_is_locked_after_max_failed_attempts(): void
    {
        $this->fakeCaptcha();
        $u = $this->makeUser(['user'], ['personnel_code' => '123456']);
        for ($i = 0; $i < 5; $i++) {
            $this->travel(13)->seconds(); // stay under the per-minute throttle
            $this->login('123456', 'bad-'.$i, $this->captchaFields())->assertStatus(422);
        }
        $this->assertTrue($u->fresh()->isLocked());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.account_locked', 'user_id' => $u->id]);

        $this->travel(61)->seconds();
        $this->login('123456', 'Str0ng!Passw0rd', $this->captchaFields())->assertStatus(422);

        $this->travel(16)->minutes();
        $this->login('123456', 'Str0ng!Passw0rd', $this->captchaFields())->assertOk();
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        $this->fakeCaptcha();
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $codes = [];
        for ($i = 0; $i < 7; $i++) {
            $codes[] = $this->login('123456', 'bad', $this->captchaFields())->status();
        }
        $this->assertContains(429, $codes);
        $this->assertSame(429, end($codes));
    }

    public function test_must_change_password_blocks_everything_but_me_logout_and_change_password(): void
    {
        $u = $this->makeUser(['admin'], ['must_change_password' => true]);
        $this->actingAs($u)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);
        $this->actingAs($u)->getJson('/api/profile')->assertStatus(403)->assertJsonPath('code', 'password_change_required');
        $this->actingAs($u)->getJson('/api/admin/users')->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    public function test_forced_password_change_validates_strength_and_clears_flag(): void
    {
        $nc = UserFactory::validNationalCode();
        $u = User::factory()->mustChangePassword()->create(['personnel_code' => '123456']);
        $u->setNationalCode($nc);
        $u->password = $nc;
        $u->save();
        $u->assignRole('user');

        $weak = ['short', 'alllowercase1!', 'NoDigitsHere!!', 'NoSymbols12345', 'Abc!'.$nc, 'Abcdefgh!123 x'];
        foreach ($weak as $pw) {
            $this->travel(13)->seconds(); // stay under the change-password throttle
            $this->actingAs($u)->postJson('/api/auth/change-password', [
                'current_password' => $nc, 'password' => $pw, 'password_confirmation' => $pw,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }
        // wrong current password
        $this->travel(61)->seconds();
        $this->actingAs($u)->postJson('/api/auth/change-password', [
            'current_password' => 'nope', 'password' => 'N3w!Secure#Pass', 'password_confirmation' => 'N3w!Secure#Pass',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->actingAs($u)->postJson('/api/auth/change-password', [
            'current_password' => $nc, 'password' => 'N3w!Secure#Pass', 'password_confirmation' => 'N3w!Secure#Pass',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $u->refresh();
        $this->assertFalse($u->must_change_password);
        $this->assertTrue(Hash::check('N3w!Secure#Pass', $u->password));
        $this->actingAs($u)->getJson('/api/profile')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $u->id]);
    }

    public function test_logout(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->login('123456', 'Str0ng!Passw0rd')->assertOk();
        $this->withHeaders($this->spa)->getJson('/api/auth/me')->assertOk();
        $this->withHeaders($this->spa)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->spa)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_guest_gets_401_json_not_redirect(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->get('/api/admin/users')->assertStatus(401);
    }

    public function test_absolute_session_timeout_and_deactivation_force_logout(): void
    {
        $u = $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->login('123456', 'Str0ng!Passw0rd')->assertOk();
        $u->is_active = false;
        $u->save();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->spa)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('code', 'session_expired');
    }

    public function test_session_has_absolute_lifetime(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->login('123456', 'Str0ng!Passw0rd')->assertOk();
        $this->travel(481)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->spa)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('code', 'session_expired');
    }

    public function test_audit_log_never_contains_passwords(): void
    {
        $this->makeUser(['user'], ['personnel_code' => '123456']);
        $this->login('123456', 'TopSecret!123')->assertStatus(422);
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('TopSecret', json_encode($log->toArray()));
        }
    }
}
