<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Keys that must never be persisted in audit metadata. */
    private const REDACT = ['password', 'password_confirmation', 'current_password', 'national_code', 'phone', 'captcha', 'token', 'contact'];

    public static function log(string $action, ?Model $subject = null, array $meta = [], ?int $userId = null, bool $anonymous = false): void
    {
        $request = request();
        foreach (self::REDACT as $key) {
            unset($meta[$key]);
        }

        $log = new AuditLog();
        $log->user_id = $userId ?? auth()->id();
        $log->action = $action;
        $log->subject_type = $subject ? class_basename($subject) : null;
        $log->subject_id = $subject?->getKey();
        $log->ip = $anonymous ? null : $request?->ip();
        $log->user_agent = ($anonymous || ! $request) ? null : Str::limit((string) $request->userAgent(), 250, '');
        $log->meta = $meta ?: null;
        $log->save();
    }
}
