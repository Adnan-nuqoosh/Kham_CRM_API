<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Success', string $code = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'meta' => (object) $meta,
            'errors' => (object) [],
        ], $status);
    }

    public static function error(string $message, string $code = 'ERROR', int $status = 400, array $errors = [], mixed $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'meta' => (object) [],
            'errors' => $errors ?: (object) [],
        ], $status);
    }
}
