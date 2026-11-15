<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\CaptchaService;
use Illuminate\Http\JsonResponse;

class CaptchaController extends Controller
{
    public function __invoke(CaptchaService $captcha): JsonResponse
    {
        return response()->json($captcha->create());
    }
}
