<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\LogService;
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
            'method' => 'required|in:cash,card,wave,orange_money',
        ]);

        if ($order->due_amount <= 0) {
            return response()->json(['message' => 'Cette commande est déjà entièrement payée'], 422);
        }

        if ($request->amount > $order->due_amount) {
            return response()->json(['message' => 'Le montant dépasse le montant dû', 'due_amount' => $order->due_amount], 422);
        }

        $payment = Payment::create([
            'id'       => (string) Str::uuid(),
            'order_id' => $order->id,
            'user_id'  => $request->user()->id,
            'amount'   => $request->amount,
            'method'   => $request->method,
            'status'   => 'completed',
        ]);

        $order->recalculate();

        $methodLabels = ['cash' => 'Espèces', 'card' => 'Carte', 'wave' => 'Wave', 'orange_money' => 'Orange Money'];
        $label = $methodLabels[$request->method] ?? $request->method;

        LogService::log($request, 'payment.processed',
            "Paiement de {$request->amount} FCFA ({$label}) — commande #{$order->order_number}",
            'payment', $payment->id,
            [], ['amount' => $request->amount, 'method' => $request->method]);

        return response()->json($payment, 201);
    }
}
