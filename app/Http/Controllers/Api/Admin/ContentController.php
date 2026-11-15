<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityTip;
use App\Models\Slide;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentController extends Controller
{
    // ---- Slides ----
    public function slides(): JsonResponse
    {
        return response()->json(['slides' => Slide::orderBy('sort_order')->orderBy('id')->get()->map(fn ($s) => $this->slideJson($s))]);
    }

    public function storeSlide(Request $request): JsonResponse
    {
        $data = $this->validateSlide($request, true);
        $slide = new Slide($data);
        $this->storeImage($request, $slide);
        $slide->save();
        AuditLogger::log('content.slide_created', $slide);

        return response()->json(['slide' => $this->slideJson($slide)], 201);
    }

    public function updateSlide(Request $request, Slide $slide): JsonResponse
    {
        $data = $this->validateSlide($request, false);
        $slide->fill($data);
        $this->storeImage($request, $slide);
        $slide->save();
        AuditLogger::log('content.slide_updated', $slide);

        return response()->json(['slide' => $this->slideJson($slide)]);
    }

    public function destroySlide(Slide $slide): JsonResponse
    {
        if ($slide->image_path) {
            Storage::disk('local')->delete($slide->image_path);
        }
        $slide->delete();
        AuditLogger::log('content.slide_deleted', $slide);

        return response()->json(['message' => 'حذف شد.']);
    }

    private function validateSlide(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            // only same-site relative links or http(s) URLs
            'link_url' => ['nullable', 'string', 'max:255', 'regex:/^(\/(?!\/)[^\s]*|https?:\/\/[^\s]+)$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'image' => [$creating ? 'required' : 'nullable', 'file', 'max:2048', 'extensions:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp'],
        ]);
    }

    private function storeImage(Request $request, Slide $slide): void
    {
        $file = $request->file('image');
        if (! $file) {
            return;
        }
        $info = @getimagesize($file->getRealPath());
        $mime = (string) $file->getMimeType();
        if ($info === false || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $info['mime'] !== $mime) {
            throw ValidationException::withMessages(['image' => 'فایل تصویر معتبر نیست.']);
        }
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
        $path = 'slides/'.Str::random(40).'.'.$ext;
        Storage::disk('local')->putFileAs('slides', $file, basename($path));
        if ($slide->image_path) {
            Storage::disk('local')->delete($slide->image_path);
        }
        $slide->image_path = $path;
        $slide->image_mime = $mime;
    }

    private function slideJson(Slide $s): array
    {
        return [
            'id' => $s->id, 'title' => $s->title, 'subtitle' => $s->subtitle, 'link_url' => $s->link_url,
            'sort_order' => $s->sort_order, 'is_active' => $s->is_active,
            'image_url' => $s->image_path ? '/api/admin/content/slides/'.$s->id.'/image?v='.$s->updated_at?->timestamp : null,
        ];
    }

    public function slideImage(Slide $slide)
    {
        abort_unless($slide->image_path && Storage::disk('local')->exists($slide->image_path), 404);

        return Storage::disk('local')->response($slide->image_path, null, ['Content-Type' => $slide->image_mime, 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    // ---- Security tips ----
    public function tips(): JsonResponse
    {
        return response()->json(['tips' => SecurityTip::orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function storeTip(Request $request): JsonResponse
    {
        $tip = SecurityTip::create($this->validateTip($request));
        AuditLogger::log('content.tip_created', $tip);

        return response()->json(['tip' => $tip], 201);
    }

    public function updateTip(Request $request, SecurityTip $tip): JsonResponse
    {
        $tip->update($this->validateTip($request));
        AuditLogger::log('content.tip_updated', $tip);

        return response()->json(['tip' => $tip]);
    }

    public function destroyTip(SecurityTip $tip): JsonResponse
    {
        $tip->delete();
        AuditLogger::log('content.tip_deleted', $tip);

        return response()->json(['message' => 'حذف شد.']);
    }

    private function validateTip(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9\-]+:[a-z0-9\-]+$/'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
