<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportAttachment;
use App\Models\ReportStatusHistory;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Report::class);
        $user = $request->user();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(Report::STATUSES)],
            'category' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $seeAll = $user->can('reports.review') || $user->can('reports.view_all');

        $reports = Report::query()->withCount('attachments')
            ->when(! $seeAll, fn ($q) => $q->where('status', Report::STATUS_APPROVED))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('tracking_hint', 'like', $like));
            })
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($reports->items())->map(fn (Report $r) => $this->listJson($r)),
            'meta' => ['current_page' => $reports->currentPage(), 'last_page' => $reports->lastPage(), 'total' => $reports->total(), 'per_page' => $reports->perPage()],
            'can_review' => $user->can('reports.review'),
        ]);
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->authorize('view', $report);
        $report->load(['attachments', 'histories.user', 'reviewer']);
        AuditLogger::log('report.viewed', $report);

        return response()->json([
            'report' => $this->listJson($report) + [
                'description' => $report->description,
                'contact' => $report->contact,
                'review_note' => $report->review_note,
                'reviewer' => $report->reviewer?->full_name,
                'reviewed_at' => $report->reviewed_at?->toIso8601String(),
                'attachments' => $report->attachments->map(fn (ReportAttachment $a) => [
                    'id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size,
                ]),
                'history' => $report->histories->map(fn (ReportStatusHistory $h) => [
                    'from' => $h->from_status, 'to' => $h->to_status, 'note' => $h->note,
                    'by' => $h->user?->full_name, 'at' => $h->created_at?->toIso8601String(),
                ]),
            ],
            'can_review' => $request->user()->can('review', $report) && $report->status === Report::STATUS_SUBMITTED,
        ]);
    }

    public function review(Request $request, Report $report): JsonResponse
    {
        $this->authorize('review', $report);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'required_if:decision,reject', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($report, $data, $request) {
            $locked = Report::whereKey($report->id)->lockForUpdate()->first();
            if ($locked->status !== Report::STATUS_SUBMITTED) {
                throw ValidationException::withMessages(['decision' => 'این گزارش قبلاً بررسی شده است.']);
            }
            $to = $data['decision'] === 'approve' ? Report::STATUS_APPROVED : Report::STATUS_REJECTED;
            $from = $locked->status;
            $locked->status = $to;
            $locked->reviewed_by = $request->user()->id;
            $locked->reviewed_at = now();
            $locked->review_note = $data['note'] ?? null;
            $locked->save();

            $h = new ReportStatusHistory();
            $h->report_id = $locked->id;
            $h->from_status = $from;
            $h->to_status = $to;
            $h->user_id = $request->user()->id;
            $h->note = $data['note'] ?? null;
            $h->save();

            AuditLogger::log('report.'.($to === Report::STATUS_APPROVED ? 'approved' : 'rejected'), $locked);
        });

        return $this->show($request, $report->fresh());
    }

    public function attachment(Report $report, ReportAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $report);
        abort_unless($attachment->report_id === $report->id, 404);
        $disk = Storage::disk(config('herasat.reports.disk', 'local'));
        abort_unless($disk->exists($attachment->stored_path), 404);
        AuditLogger::log('report.attachment_downloaded', $report, ['attachment_id' => $attachment->id]);

        return $disk->download($attachment->stored_path, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function listJson(Report $r): array
    {
        return [
            'id' => $r->id,
            'tracking_hint' => $r->tracking_hint,
            'title' => $r->title,
            'category' => $r->category,
            'category_label' => config('herasat.reports.categories.'.$r->category, $r->category),
            'status' => $r->status,
            'attachments_count' => $r->attachments_count ?? $r->attachments()->count(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
