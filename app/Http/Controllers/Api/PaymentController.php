<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentNumber;
use App\Services\LogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private const COLLECT_ROLES = ['admin', 'manager', 'cashier'];

    private const METHODS = ['cash', 'mobile_money'];

    private const OPERATOR_LABELS = [
        'mtn_momo' => 'MTN MoMo',
        'moov_money' => 'Moov Money',
        'celtiis_cash' => 'Celtiis Cash',
    ];

    /** Refus métier : réponse 422 au format habituel, et la transaction en cours est annulée. */
    private function refuse(string $message, array $extra = []): never
    {
        throw new HttpResponseException(
            response()->json(array_merge(['message' => $message], $extra), 422)
        );
    }

    public function index(Order $order)
    {
        return response()->json($order->payments);
    }

    public function store(Request $request, Order $order)
    {
        abort_unless(
            in_array($request->user()->role, self::COLLECT_ROLES, true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent encaisser."
        );

        $validated = $request->validate([
            'amount' => 'required|integer|min:1',
            'method' => ['required', Rule::in(self::METHODS)],
            'operator' => ['required_if:method,mobile_money', 'nullable', Rule::in(PaymentNumber::OPERATORS)],
            'reference' => ['required_if:method,mobile_money', 'nullable', 'string', 'max:255'],
            'cash_given' => 'nullable|integer|min:1',
            'receipt_confirmed' => 'accepted',
        ], [
            'receipt_confirmed.accepted' => 'Vous devez confirmer avoir reçu le paiement.',
            'operator.required_if' => "L'opérateur est obligatoire pour un paiement Mobile Money.",
            'reference.required_if' => 'La référence de la transaction est obligatoire pour un paiement Mobile Money.',
        ]);

        $isMobileMoney = $validated['method'] === 'mobile_money';
        $restaurantId = $request->user()->restaurant_id;

        $payment = DB::transaction(function () use ($request, $order, $validated, $isMobileMoney, $restaurantId) {
            // La commande est verrouillée : deux encaissements simultanés ne peuvent pas se croiser
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                $this->refuse('Cette commande est annulée : elle ne peut pas être encaissée.');
            }

            if ($locked->due_amount <= 0 || $locked->status === 'paid') {
                $this->refuse('Cette commande est déjà entièrement payée');
            }

            if ($locked->status !== 'served') {
                $this->refuse('Une commande doit être servie avant de pouvoir être encaissée.');
            }

            // Espèces : le client peut donner plus que le dû (la monnaie est rendue). Mobile Money : jamais plus que le dû.
            $amountToRecord = $validated['amount'];
            if ($isMobileMoney && $amountToRecord > $locked->due_amount) {
                $this->refuse('Le montant dépasse le montant dû', ['due_amount' => $locked->due_amount]);
            }
            if ($amountToRecord > $locked->due_amount) {
                $amountToRecord = $locked->due_amount;
            }

            if ($isMobileMoney) {
                $alreadyUsed = Payment::where('operator', $validated['operator'])
                    ->where('reference', $validated['reference'])
                    ->whereIn('status', ['pending', 'completed'])
                    ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
                    ->exists();

                if ($alreadyUsed) {
                    throw ValidationException::withMessages([
                        'reference' => 'Cette référence de transaction a déjà été enregistrée pour cet opérateur.',
                    ]);
                }
            }

            $payment = Payment::create([
                'id' => (string) Str::uuid(),
                'order_id' => $locked->id,
                'user_id' => $request->user()->id,
                'amount' => $amountToRecord,
                'method' => $validated['method'],
                'operator' => $isMobileMoney ? $validated['operator'] : null,
                'reference' => $validated['reference'] ?? null,
                'transaction_data' => ['receipt_confirmed_at' => now()->toIso8601String()],
                'status' => 'completed',
            ]);

            $locked->recalculate();

            return $payment;
        });

        $label = $isMobileMoney
            ? 'Mobile Money ('.self::OPERATOR_LABELS[$payment->operator].')'
            : 'Espèces';

        LogService::log($request, 'payment.processed',
            "Paiement de {$payment->amount} FCFA ({$label}) — commande #{$order->order_number}",
            'payment', $payment->id,
            [], ['amount' => $payment->amount, 'method' => $payment->method, 'operator' => $payment->operator]);

        return response()->json($payment, 201);
    }
}
