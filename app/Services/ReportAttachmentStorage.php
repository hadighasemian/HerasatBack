<?php

namespace App\Services;

use App\Models\Report;
use App\Models\ReportAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores report attachments on the private disk (storage/app/private, outside the web root) under
 * random names. File type is decided from content (finfo) and must match the extension whitelist.
 */
class ReportAttachmentStorage
{
    public function assertSafe(UploadedFile $file, string $field): string
    {
        $allowed = config('herasat.reports.allowed_mimes');
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType(); // content sniffing via fileinfo
        if (! isset($allowed[$ext]) || ! in_array($mime, $allowed[$ext], true)) {
            throw ValidationException::withMessages([$field => 'نوع فایل مجاز نیست. فقط JPG، PNG و PDF پذیرفته می‌شود.']);
        }
        $path = $file->getRealPath();
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $ok = match ($mime) {
            'image/jpeg' => str_starts_with($head, "\xFF\xD8\xFF") && @getimagesize($path) !== false,
            'image/png' => str_starts_with($head, "\x89PNG\r\n\x1A\n") && @getimagesize($path) !== false,
            'application/pdf' => str_starts_with($head, '%PDF-'),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages([$field => 'محتوای فایل با نوع آن مطابقت ندارد.']);
        }

        return $mime;
    }

    public function store(Report $report, UploadedFile $file, string $mime): ReportAttachment
    {
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'pdf',
        };
        $dir = 'reports/'.now()->format('Y/m');
        $name = Str::random(40).'.'.$ext;
        $disk = config('herasat.reports.disk', 'local');
        Storage::disk($disk)->putFileAs($dir, $file, $name);

        $attachment = new ReportAttachment();
        $attachment->report_id = $report->id;
        $attachment->original_name = $this->safeName($file->getClientOriginalName(), $ext);
        $attachment->stored_path = $dir.'/'.$name;
        $attachment->mime = $mime;
        $attachment->size = (int) $file->getSize();
        $attachment->sha256 = hash_file('sha256', $file->getRealPath());
        $attachment->save();

        return $attachment;
    }

    private function safeName(string $name, string $ext): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^\p{L}\p{N}_\- ]+/u', '', $base) ?? '';
        $base = trim(mb_substr($base, 0, 100));

        return ($base !== '' ? $base : 'attachment').'.'.$ext;
    }
}
