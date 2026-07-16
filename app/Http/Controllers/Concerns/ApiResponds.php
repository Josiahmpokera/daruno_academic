<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait ApiResponds
{
    protected function success(mixed $data = [], string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'error' => '',
            'data' => $data === [] ? (object) [] : $data,
            'message' => $message,
        ], $status);
    }

    protected function error(string $error, int $status = 400, mixed $data = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $error,
            'data' => $data === [] ? (object) [] : $data,
        ], $status);
    }
}
