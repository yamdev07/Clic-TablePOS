<?php
// app/Http/Controllers/Api/PaymentController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Order $order)
    {
        return response()->json($order->payments);
    }

    public function store(Request $request, Order $order)
    {
        $request->validate([
            'amount' => 'required|integer|min:1',
            'method' => 'required|in:cash,card,wave,orange_money'
        ]);

        // Vérifier si la commande est déjà payée
        if ($order->due_amount <= 0) {
            return response()->json([
                'message' => 'Cette commande est déjà entièrement payée'
            ], 422);
        }

        // Empêcher de payer plus que le montant dû
        if ($request->amount > $order->due_amount) {
            return response()->json([
                'message' => 'Le montant dépasse le montant dû',
                'due_amount' => $order->due_amount
            ], 422);
        }

        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'user_id' => $request->user()->id,
            'amount' => $request->amount,
            'method' => $request->method,
            'status' => 'completed'
        ]);

        $order->recalculate();

        return response()->json($payment, 201);
    }
}