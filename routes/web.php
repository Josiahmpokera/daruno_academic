<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'Daruno API',
        'status' => 'ok',
        'timestamp' => now(),
    ]);
});
