<?php

namespace App\Http\Controllers\Idempotency;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Order;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $key = $request->header('Idempotency-Key');

        $validator = validator(
            [
                'idempotency_key' => $key,
                'user_id' => $request->user()->id ?? null,
            ],
            [
                'idempotency_key' => 'required|string|uuid',
                'user_id' => 'required|integer',
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

        if (!$key) {
            return response()->json([
                'error' => 'idempotency-key header is required'
            ], 400);
        }

        $userId = $request->user_id;

        // Combine user + key to avoid collisions
        $compositeKey = $userId . ':' . $key;

        // Check if key already exists
        $existing = IdempotencyKey::find($compositeKey);

        if ($existing) {
            // If already completed, return stored response
            if ($existing->status === 'completed') {
                return response()->json($existing->response, 200);
            }

            // If still processing, prevent duplicate execution
            return response()->json([
                'message' => 'Request is already being processed'
            ], 409);
        }

        try {
            $response = null;

            DB::transaction(function () use ($compositeKey, $userId, $request, &$response) {

                // Create idempotency record FIRST (locks uniqueness)
                IdempotencyKey::create([
                    'key' => $compositeKey,
                    'user_id' => $userId,
                    'status' => 'processing',
                ]);

                // Your main logic
                $order = Order::create([
                    'user_id' => $userId,
                    'amount' => $request->amount,
                    'status' => 'pending',
                ]);

                $response = [
                    'success' => true,
                    'order_id' => $order->id,
                    'amount' => $order->amount,
                ];

                // Save final response
                IdempotencyKey::where('key', $compositeKey)
                    ->update([
                        'status' => 'completed',
                        'response' => $response,
                    ]);
            });

            return response()->json($response, 201);
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle race condition (duplicate key insert)
            $existing = IdempotencyKey::find($compositeKey);

            if ($existing && $existing->status === 'completed') {
                return response()->json($existing->response, 200);
            }

            return response()->json([
                'error' => 'Request conflict, please retry'
            ], 409);
        }
    }
}
