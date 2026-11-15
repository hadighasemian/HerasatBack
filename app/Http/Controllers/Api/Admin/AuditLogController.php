<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $logs = AuditLog::with('user:id,personnel_code,first_name,last_name')
            ->when($data['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_\\').'%'))
            ->when($data['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 30);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'action' => $l->action,
                'user' => $l->user ? ['id' => $l->user->id, 'personnel_code' => $l->user->personnel_code, 'name' => $l->user->full_name] : null,
                'subject_type' => $l->subject_type,
                'subject_id' => $l->subject_id,
                'ip' => $l->ip,
                'meta' => $l->meta,
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
