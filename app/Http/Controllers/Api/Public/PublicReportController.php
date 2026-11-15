<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Rules\Captcha;
use App\Services\AuditLogger;
use App\Services\ReportAttachmentStorage;
use App\Support\Persian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PublicReportController extends Controller
{
    private const STATUS_LABELS = [
        Report::STATUS_SUBMITTED => 'ثبت شده - در انتظار بررسی',
        Report::STATUS_APPROVED => 'بررسی و تأیید شده - در حال پیگیری',
        Report::STATUS_REJECTED => 'بررسی شده - رد شده',
    ];

    public function categories(): JsonResponse
    {
        $items = collect(config('herasat.reports.categories'))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();

        return response()->json([
            'categories' => $items,
            'limits' => [
                'max_attachments' => config('herasat.reports.max_attachments'),
                'max_attachment_kb' => config('herasat.reports.max_attachment_kb'),
                'allowed_extensions' => array_keys(config('herasat.reports.allowed_mimes')),
            ],
        ]);
    }

    public function store(Request $request, ReportAttachmentStorage $storage): JsonResponse
    {
        $cfg = config('herasat.reports');
        $request->merge([
            'title' => Persian::normalizeText((string) $request->input('title')),
            'contact' => Persian::normalizeText((string) $request->input('contact')) ?: null,
        ]);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'category' => ['required', 'string', Rule::in(array_keys($cfg['categories']))],
            'contact' => ['nullable', 'string', 'max:200'],
            'attachments' => ['nullable', 'array', 'max:'.$cfg['max_attachments']],
            'attachments.*' => ['file', 'max:'.$cfg['max_attachment_kb'], 'extensions:'.implode(',', array_keys($cfg['allowed_mimes'])), 'mimetypes:'.implode(',', array_unique(array_merge(...array_values($cfg['allowed_mimes']))))],
            'captcha_id' => ['required', 'string', 'max:64'],
            'captcha' => ['required', 'string', 'max:10', new Captcha()],
        ]);

        $files = $request->file('attachments', []);
        $mimes = [];
        foreach ($files as $i => $file) {
            $mimes[$i] = $storage->assertSafe($file, "attachments.$i");
        }

        $code = $this->generateTrackingCode();
        $stored = [];
        try {
            $report = DB::transaction(function () use ($data, $code, $files, $mimes, $storage, &$stored) {
                $report = new Report([
                    'title' => $data['title'],
                    'description' => trim($data['description']),
                    'category' => $data['category'],
                    'contact' => $data['contact'] ?? null,
                ]);
                $report->tracking_hash = Report::hashTrackingCode($code);
                $report->tracking_hint = substr(str_replace('-', '', $code), -4);
                $report->status = Report::STATUS_SUBMITTED;
                $report->save();

                foreach ($files as $i => $file) {
                    $stored[] = $storage->store($report, $file, $mimes[$i])->stored_path;
                }

                $h = new ReportStatusHistory();
                $h->report_id = $report->id;
                $h->from_status = null;
                $h->to_status = Report::STATUS_SUBMITTED;
                $h->save();

                return $report;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk(config('herasat.reports.disk', 'local'))->delete($path);
            }
            throw $e;
        }

        // Reporter anonymity: no IP / user agent is recorded for public submissions.
        AuditLogger::log('report.submitted', $report, ['attachments' => count($files)], null, anonymous: true);

        return response()->json([
            'message' => 'گزارش شما با موفقیت ثبت شد. کد پیگیری را نزد خود نگه دارید؛ این کد دوباره نمایش داده نمی‌شود.',
            'tracking_code' => $code,
        ], 201);
    }

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:40'],
            'captcha_id' => ['required', 'string', 'max:64'],
            'captcha' => ['required', 'string', 'max:10', new Captcha()],
        ]);
        $report = Report::where('tracking_hash', Report::hashTrackingCode(Persian::toEnglishDigits($data['tracking_code'])))->first();
        if (! $report) {
            return response()->json(['message' => 'گزارشی با این کد پیگیری یافت نشد.'], 404);
        }

        return response()->json([
            'status' => $report->status,
            'status_label' => self::STATUS_LABELS[$report->status] ?? $report->status,
            'submitted_at' => $report->created_at?->toIso8601String(),
            'updated_at' => ($report->reviewed_at ?? $report->created_at)?->toIso8601String(),
        ]);
    }

    private function generateTrackingCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
        do {
            $raw = '';
            for ($i = 0; $i < 16; $i++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = implode('-', str_split($raw, 4));
        } while (Report::where('tracking_hash', Report::hashTrackingCode($code))->exists());

        return $code;
    }
}
