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
            'amount'       => 'required|integer|min:1',
            'method'       => 'required|in:cash,card,wave,orange_money',
            'cash_given'   => 'nullable|integer|min:1',
            'reference'    => 'nullable|string|max:255',
        ]);

        if ($order->due_amount <= 0) {
            return response()->json(['message' => 'Cette commande est déjà entièrement payée'], 422);
        }

        // For cash payments allow sending more than due (the change is handled client-side)
        // For other methods, enforce exact amount
        $amountToRecord = $request->amount;
        if ($request->method !== 'cash' && $amountToRecord > $order->due_amount) {
            return response()->json(['message' => 'Le montant dépasse le montant dû', 'due_amount' => $order->due_amount], 422);
        }
        // Cap at due_amount regardless (we record what we receive, change is returned to customer)
        if ($amountToRecord > $order->due_amount) {
            $amountToRecord = $order->due_amount;
        }

        $payment = Payment::create([
            'id'        => (string) Str::uuid(),
            'order_id'  => $order->id,
            'user_id'   => $request->user()->id,
            'amount'    => $amountToRecord,
            'method'    => $request->method,
            'reference' => $request->reference,
            'status'    => 'completed',
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
