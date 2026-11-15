<?php

use Illuminate\Support\Facades\Route;

// The Laravel app is an API only; the UI is served by Next.js.
Route::get('/', fn () => response()->json(['name' => 'Herasat API']));
