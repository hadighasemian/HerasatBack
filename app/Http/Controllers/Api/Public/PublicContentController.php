<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\SecurityTip;
use App\Models\Slide;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PublicContentController extends Controller
{
    public function slides(): JsonResponse
    {
        $slides = Slide::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Slide $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'subtitle' => $s->subtitle,
                'link_url' => $s->link_url,
                'image_url' => $s->image_path ? '/api/public/slides/'.$s->id.'/image?v='.$s->updated_at?->timestamp : null,
            ]);

        return response()->json(['slides' => $slides]);
    }

    public function slideImage(Slide $slide): Response
    {
        abort_unless($slide->is_active && $slide->image_path && Storage::disk('local')->exists($slide->image_path), 404);

        return Storage::disk('local')->response($slide->image_path, null, [
            'Content-Type' => $slide->image_mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    public function tips(): JsonResponse
    {
        $tips = SecurityTip::where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'title', 'body', 'icon']);

        return response()->json(['tips' => $tips]);
    }
}
