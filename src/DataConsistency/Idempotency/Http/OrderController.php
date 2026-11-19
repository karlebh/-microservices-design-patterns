<?php

namespace Patterns\DataConsistency\Idempotency\Http;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Patterns\DataConsistency\Idempotency\IdempotencyStatus;
use Patterns\DataConsistency\Idempotency\Models\IdempotencyKey;
use Patterns\DataConsistency\Idempotency\Models\Order;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $key = $request->header('Idempotency-Key');
        $userId = $request->user()?->id ?? $request->input('user_id');

        $validator = validator(
            [
                'idempotency_key' => $key,
                'user_id' => $userId,
                'amount' => $request->input('amount'),
            ],
            [
                'idempotency_key' => 'required|string|uuid',
                'user_id' => 'required|integer',
                'amount' => 'required|numeric|min:0.01',
            ],
            [
                'idempotency_key.required' => 'Idempotency key is required.',
                'idempotency_key.uuid' => 'Idempotency key must be a valid UUID.',
                'user_id.required' => 'User ID is required.',
                'user_id.integer' => 'User ID must be a number.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = (int) $userId;

        // Fingerprint of WHAT is being asked, so the key can't be reused for something else.
        $fingerprint = hash('sha256', json_encode([
            $request->method(),
            $request->path(),
            Arr::sortRecursive($request->all()),
        ]));

        // Check if key already exists
        $existing = IdempotencyKey::find($key);

        if ($existing) {
            return $this->replay($existing, $userId, $fingerprint);
        }

        try {
            $response = DB::transaction(function () use ($key, $userId, $fingerprint, $request) {

                // Create idempotency record FIRST (locks uniqueness).
                // Of N concurrent requests with this key, exactly one INSERT succeeds;
                // the others block on the primary key and then fail.
                $record = IdempotencyKey::create([
                    'id' => $key,
                    'user_id' => $userId,
                    'status' => IdempotencyStatus::PROCESSING,
                    'request_hash' => $fingerprint,
                ]);

                // Your main logic
                $order = Order::create([
                    'user_id' => $userId,
                    'amount' => $request->input('amount'),
                    'status' => 'pending',
                ]);

                $response = [
                    'success' => true,
                    'order_id' => $order->id,
                    'amount' => $order->amount,
                ];

                // Save final response, in the same transaction as the order.
                $record->update([
                    'status' => IdempotencyStatus::COMPLETED,
                    'response_code' => 201,
                    'response' => $response,
                ]);

                return $response;
            });

            return response()->json($response, 201);
        } catch (UniqueConstraintViolationException) {
            // Handle race condition (duplicate key insert): another request with
            // the same key committed between our lookup and our insert.
            $existing = IdempotencyKey::find($key);

            if ($existing) {
                return $this->replay($existing, $userId, $fingerprint);
            }

            return response()->json([
                'error' => 'Request conflict, please retry'
            ], 409);
        }
    }

    /**
     * Answer a request whose key we have seen before, without redoing the work.
     */
    private function replay(IdempotencyKey $existing, int $userId, string $fingerprint): JsonResponse
    {
        // A key belongs to the caller who created it. Never hand one caller
        // the stored response of another.
        if ((int) $existing->user_id !== $userId) {
            return response()->json([
                'error' => 'Idempotency key is already in use'
            ], 409);
        }

        // Same key, different request: a client bug. Refuse rather than guess.
        if ($existing->request_hash !== null && ! hash_equals($existing->request_hash, $fingerprint)) {
            return response()->json([
                'error' => 'Idempotency key was already used with a different request'
            ], 422);
        }

        // If already completed, return stored response
        if ($existing->status === IdempotencyStatus::COMPLETED) {
            return response()
                ->json($existing->response, $existing->response_code ?? 200)
                ->header('Idempotent-Replayed', 'true');
        }

        // If still processing, prevent duplicate execution
        return response()->json([
            'message' => 'Request is already being processed'
        ], 409);
    }
}
