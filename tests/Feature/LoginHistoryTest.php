<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Tests\TestCase;

class LoginHistoryTest extends TestCase
{
    private function login(string $username, string $password, array $headers = [])
    {
        return $this->withHeaders($this->spa + $headers)->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function test_user_sees_own_successful_and_failed_logins_with_client_info(): void
    {
        $u = $this->makeUser(['user']);
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $this->login($u->personnel_code, 'wrong-password', ['User-Agent' => $ua])->assertStatus(422);
        $this->login($u->personnel_code, 'Str0ng!Passw0rd', ['User-Agent' => $ua])->assertOk();

        $res = $this->withHeaders($this->spa + ['User-Agent' => $ua])
            ->actingAs($u)
            ->getJson('/api/profile/login-history')
            ->assertOk();

        $actions = collect($res->json('data'))->pluck('action')->all();
        $this->assertContains('auth.login', $actions);
        $this->assertContains('auth.login_failed', $actions);

        $success = collect($res->json('data'))->firstWhere('action', 'auth.login');
        $this->assertSame('success', $success['result']);
        $this->assertSame('موفق', $success['result_label']);
        $this->assertSame('Chrome 120', $success['browser']);
        $this->assertSame('Windows', $success['os']);
        $this->assertNotEmpty($success['ip']);
        $this->assertNotEmpty($success['occurred_at']);

        $failed = collect($res->json('data'))->firstWhere('action', 'auth.login_failed');
        $this->assertSame('failed', $failed['result']);
        $this->assertSame('ناموفق', $failed['result_label']);

        $last = $res->json('last_successful_login');
        $this->assertNotNull($last);
        $this->assertSame('auth.login', $last['action']);
        $this->assertSame('Chrome 120', $last['browser']);
    }

    public function test_user_cannot_see_another_users_login_history(): void
    {
        $a = $this->makeUser(['user']);
        $b = $this->makeUser(['user']);

        AuditLogger::log('auth.login', $b, [], $b->id);
        // Distinct IP recorded against B only
        AuditLog::query()->where('user_id', $b->id)->where('action', 'auth.login')->update(['ip' => '203.0.113.9']);

        $this->login($a->personnel_code, 'Str0ng!Passw0rd')->assertOk();

        $res = $this->actingAs($a)->getJson('/api/profile/login-history')->assertOk();
        $ips = collect($res->json('data'))->pluck('ip')->all();
        $this->assertNotContains('203.0.113.9', $ips);
        $this->assertSame($res->json('data.0.action') ? true : true, true);
        foreach ($res->json('data') as $row) {
            $this->assertNotSame('203.0.113.9', $row['ip']);
        }
        $this->assertNotSame('203.0.113.9', $res->json('last_successful_login.ip'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $b->id, 'ip' => '203.0.113.9']);
    }

    public function test_admin_profile_login_history_is_still_only_their_own(): void
    {
        $admin = $this->makeUser(['admin']);
        $user = $this->makeUser(['user']);
        AuditLog::query()->delete();
        $this->writeLog($admin->id, 'auth.login', '198.51.100.2');
        $this->writeLog($user->id, 'auth.login', '203.0.113.10');
        $this->writeLog($user->id, 'auth.login_failed', '203.0.113.11');

        $res = $this->actingAs($admin)->getJson('/api/profile/login-history')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('198.51.100.2', $res->json('data.0.ip'));
        $ips = collect($res->json('data'))->pluck('ip')->all();
        $this->assertNotContains('203.0.113.10', $ips);
        $this->assertNotContains('203.0.113.11', $ips);
    }

    public function test_login_history_is_capped_at_fifty_and_paginated(): void
    {
        $u = $this->makeUser(['user']);
        for ($i = 0; $i < 60; $i++) {
            $this->writeLog($u->id, $i % 2 === 0 ? 'auth.login' : 'auth.login_failed', '127.0.0.1');
        }

        $page1 = $this->actingAs($u)->getJson('/api/profile/login-history?per_page=10')->assertOk();
        $this->assertSame(50, $page1->json('meta.total'));
        $this->assertSame(5, $page1->json('meta.last_page'));
        $this->assertCount(10, $page1->json('data'));

        $page5 = $this->actingAs($u)->getJson('/api/profile/login-history?per_page=10&page=5')->assertOk();
        $this->assertCount(10, $page5->json('data'));
        $this->assertSame(5, $page5->json('meta.current_page'));
    }

    public function test_guest_cannot_read_login_history(): void
    {
        $this->getJson('/api/profile/login-history')->assertStatus(401);
    }

    private function writeLog(int $userId, string $action, string $ip): void
    {
        $log = new AuditLog;
        $log->user_id = $userId;
        $log->action = $action;
        $log->ip = $ip;
        $log->user_agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/121.0';
        $log->save();
    }
}
