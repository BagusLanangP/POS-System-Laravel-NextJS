<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        ?Paginator $paginator = null,
        int $status = 200,
    ): JsonResponse {
        $meta = $paginator ? [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ] : null;

        return response()->json([
            'data' => $data,
            'meta' => $meta,
            'message' => $message,
        ], $status);
    }
}
