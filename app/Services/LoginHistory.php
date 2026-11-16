<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\UserAgent;

/** A user's recent login attempts (successful, failed, logout, lockout): last 50, paginated. */
class LoginHistory
{
    public const ACTIONS = [
        'auth.login' => ['success', 'موفق'],
        'auth.login_failed' => ['failed', 'ناموفق'],
        'auth.logout' => ['logout', 'خروج'],
        'auth.account_locked' => ['locked', 'قفل حساب'],
    ];

    public static function forUser(User $user, int $perPage): array
    {
        $latestIds = AuditLog::query()
            ->where('user_id', $user->id)
            ->whereIn('action', array_keys(self::ACTIONS))
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id');

        $logs = AuditLog::query()
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->paginate($perPage);

        $lastSuccess = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('action', 'auth.login')
            ->orderByDesc('id')
            ->first();

        return [
            'data' => collect($logs->items())->map(fn (AuditLog $l) => self::item($l)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
            ],
            'last_successful_login' => $lastSuccess ? self::item($lastSuccess) : null,
        ];
    }

    public static function item(AuditLog $l): array
    {
        [$result, $label] = self::ACTIONS[$l->action] ?? ['other', $l->action];
        $ua = UserAgent::parse($l->user_agent);

        return [
            'id' => $l->id,
            'action' => $l->action,
            'result' => $result,
            'result_label' => $label,
            'occurred_at' => $l->created_at?->toIso8601String(),
            'ip' => $l->ip,
            'browser' => $ua['browser'],
            'os' => $ua['os'],
        ];
    }
}
