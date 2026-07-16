<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'Shengena API',
        'status' => 'ok',
        'timestamp' => now(),
    ]);
});
