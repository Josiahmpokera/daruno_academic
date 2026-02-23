<?php

use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => 'Daruno API',
        'status' => 'ok',
        'timestamp' => now(),
    ]);
});

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
        Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    });

    Route::middleware(['auth:api', 'role:super_admin'])->group(function () {
        Route::post('schools', [SchoolController::class, 'store']);
    });
});
