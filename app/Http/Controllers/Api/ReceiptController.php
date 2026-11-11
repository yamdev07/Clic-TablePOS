<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\LogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceiptController extends Controller
{
    private const READ_ROLES = ['admin', 'manager', 'cashier', 'waiter'];

    private const TZ = 'Africa/Porto-Novo';

    public function show(Request $request, Order $order)
    {
        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à consulter une facture."
        );

        abort_unless(
            (string) $order->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );

        $preview = (bool) $request->query('preview', false);

        if (! $preview && $order->status !== 'paid') {
            return response()->json([
                'message' => 'La facture est disponible uniquement pour une commande payée. Utilisez ?preview=1 pour un aperçu.',
            ], 422);
        }

        // Si preview → pas de facture figée, calcul en direct
        if ($preview) {
            return response()->json($this->buildReceipt($order, null, true));
        }

        // Sinon, vérifier si une facture existe déjà
        $existing = Invoice::where('order_id', $order->id)->first();
        if ($existing) {
            return response()->json($existing->snapshot);
        }

        // Créer la facture (dans une transaction, verrou sur la commande)
        $invoice = DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            // Double-check (deux requêtes simultanées)
            $existing = Invoice::where('order_id', $locked->id)->first();
            if ($existing) {
                return $existing;
            }

            $now = Carbon::now(self::TZ);
            $date = $now->toDateString();
            $dateCode = $now->format('Ymd');

            $sequence = (Invoice::where('restaurant_id', $locked->restaurant_id)
                ->where('date', $date)
                ->lockForUpdate()
                ->max('sequence_number') ?? 0) + 1;

            $receiptNumber = 'FA-'.$dateCode.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $snapshot = $this->buildReceipt($locked, $receiptNumber, false);

            return Invoice::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $locked->restaurant_id,
                'order_id' => $locked->id,
                'receipt_number' => $receiptNumber,
                'date' => $date,
                'sequence_number' => $sequence,
                'snapshot' => $snapshot,
                'issued_at' => now(),
            ]);
        });

        LogService::log($request, 'invoice.issued',
            "Facture {$invoice->receipt_number} émise — commande #{$order->order_number}",
            'invoice', $invoice->id);

        return response()->json($invoice->snapshot);
    }

    private function buildReceipt(Order $order, ?string $receiptNumber, bool $preview): array
    {
        $restaurant = $order->restaurant;
        $order->loadMissing([
            'items.options',
            'items.menuItem',
            'table',
            'user',
            'payments' => fn ($q) => $q->where('status', 'completed')->with('user'),
        ]);

        $items = $order->items
            ->filter(fn ($item) => $item->out_of_stock_at === null)
            ->map(fn (OrderItem $item) => [
                'name' => $item->item_name,
                'quantity' => $item->quantity,
                'unit_price' => (int) $item->unit_price,
                'options' => $item->options->map(fn ($opt) => [
                    'name' => $opt->name,
                    'price_delta' => (int) $opt->price_delta,
                ])->values()->all(),
                'line_total' => (int) $item->total_price,
            ])->values()->all();

        $payments = $order->payments
            ->map(fn (Payment $p) => [
                'method' => $p->method,
                'operator' => $p->operator,
                'reference' => $p->reference,
                'amount' => (int) $p->amount,
                'paid_at' => $p->created_at?->setTimezone(self::TZ)->toIso8601String(),
                'cashier' => $p->user ? ['name' => $p->user->name] : null,
            ])->values()->all();

        $cashier = $order->payments->last()?->user;

        return [
            'receipt_number' => $receiptNumber,
            'issued_at' => Carbon::now(self::TZ)->toIso8601String(),
            'preview' => $preview,
            'restaurant' => [
                'name' => $restaurant->name,
                'ifu' => $restaurant->ifu,
                'rccm' => $restaurant->rccm,
                'phone' => $restaurant->phone,
                'address' => $restaurant->address,
            ],
            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'type' => $order->type ?? 'dine_in',
                'table' => $order->table ? ['number' => $order->table->number] : null,
                'guest_count' => $order->guest_count,
                'opened_at' => $order->created_at?->setTimezone(self::TZ)->toIso8601String(),
                'completed_at' => $order->completed_at?->setTimezone(self::TZ)->toIso8601String(),
                'waiter' => $order->user ? ['name' => $order->user->name] : null,
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => (int) $order->subtotal,
                'tax' => (int) $order->tax,
                'service_charge' => (int) $order->service_charge,
                'total' => (int) $order->total,
                'paid_amount' => (int) $order->paid_amount,
            ],
            'payments' => $payments,
            'cashier' => $cashier ? ['name' => $cashier->name] : null,
        ];
    }
}
