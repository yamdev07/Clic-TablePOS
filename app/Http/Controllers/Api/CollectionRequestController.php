<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectionRequest;
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

class CollectionRequestController extends Controller
{
    private const CREATE_ROLES = ['admin', 'manager', 'waiter'];

    private const CANCEL_ROLES = ['admin', 'manager', 'waiter'];

    private const INDEX_ROLES = ['admin', 'manager', 'cashier', 'waiter'];

    private const COLLECT_ROLES = ['admin', 'manager', 'cashier'];

    private function ensureBelongsToRestaurant(Request $request, CollectionRequest $collectionRequest): void
    {
        abort_unless(
            (string) $collectionRequest->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }

    private function refuse(string $message, array $extra = []): never
    {
        throw new HttpResponseException(
            response()->json(array_merge(['message' => $message], $extra), 422)
        );
    }

    public function index(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::INDEX_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à consulter les demandes d'encaissement."
        );

        $query = CollectionRequest::query()
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->with(['orders', 'table:id,number', 'requester:id,name'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $request->validate([
                'status' => ['required', Rule::in(CollectionRequest::STATUSES)],
            ]);
            $query->where('status', $request->input('status'));
        }

        $items = $query->get()->map(fn (CollectionRequest $cr) => $this->serialize($cr));

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, CollectionRequest $collectionRequest)
    {
        $this->ensureBelongsToRestaurant($request, $collectionRequest);

        abort_unless(
            in_array($request->user()->role, self::INDEX_ROLES, true),
            403
        );

        $collectionRequest->load(['orders', 'table:id,number', 'requester:id,name']);

        return response()->json($this->serialize($collectionRequest));
    }

    public function store(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::CREATE_ROLES, true),
            403,
            "Seuls le serveur, le gérant et l'administrateur peuvent créer une demande d'encaissement."
        );

        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'required|uuid|distinct',
            'method' => ['required', Rule::in(CollectionRequest::METHODS)],
            'operator' => ['required_if:method,mobile_money', 'nullable', Rule::in(PaymentNumber::OPERATORS)],
        ], [
            'order_ids.required' => 'Sélectionnez au moins une commande.',
            'order_ids.min' => 'Sélectionnez au moins une commande.',
            'operator.required_if' => "L'opérateur est obligatoire pour un paiement Mobile Money.",
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $isMobileMoney = $validated['method'] === 'mobile_money';

        $collectionRequest = DB::transaction(function () use ($request, $validated, $restaurantId, $isMobileMoney) {
            // 1) Charger les commandes verrouillées, restreintes au restaurant
            $orders = Order::whereIn('id', $validated['order_ids'])
                ->where('restaurant_id', $restaurantId)
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count($validated['order_ids'])) {
                abort(404, 'Une ou plusieurs commandes sont introuvables.');
            }

            // 2) Toutes servies, non payées, sans demande pending, d'une même table
            foreach ($orders as $order) {
                if ($order->status !== 'served') {
                    $this->refuse("La commande {$order->order_number} n'est pas servie : elle ne peut pas être demandée à l'encaissement.");
                }
                if ($order->due_amount <= 0) {
                    $this->refuse("La commande {$order->order_number} est déjà entièrement payée.");
                }
                if ($order->pending_collection_request_id !== null) {
                    $this->refuse("La commande {$order->order_number} est déjà dans une demande d'encaissement en attente.");
                }
            }

            $tableIds = $orders->pluck('table_id')->unique();
            if ($tableIds->count() > 1) {
                $this->refuse('Toutes les commandes doivent appartenir à la même table.');
            }
            $tableId = $tableIds->first();

            // 3) Pour Mobile Money : trouver un numéro actif pour cet opérateur
            $phone = null;
            $beneficiary = null;
            $operator = null;

            if ($isMobileMoney) {
                $operator = $validated['operator'];
                $number = PaymentNumber::where('restaurant_id', $restaurantId)
                    ->where('operator', $operator)
                    ->where('is_active', true)
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->first();

                if (! $number) {
                    $this->refuse("Aucun numéro Mobile Money actif n'est configuré pour cet opérateur.");
                }

                $phone = $number->phone_number;
                $beneficiary = $number->beneficiary_name;
            }

            // 4) Création de la demande
            $collectionRequest = CollectionRequest::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurantId,
                'table_id' => $tableId,
                'requested_by' => $request->user()->id,
                'method' => $validated['method'],
                'operator' => $operator,
                'phone_number' => $phone,
                'beneficiary_name' => $beneficiary,
                'status' => 'pending',
            ]);

            // 5) Liaison pivot + pointeur pending sur chaque commande
            $collectionRequest->orders()->attach($orders->pluck('id')->all());
            Order::whereIn('id', $orders->pluck('id')->all())
                ->update(['pending_collection_request_id' => $collectionRequest->id]);

            return $collectionRequest;
        });

        $collectionRequest->load(['orders', 'table:id,number', 'requester:id,name']);

        LogService::log($request, 'collection_request.created',
            "Demande d'encaissement créée — table #{$collectionRequest->table_id}",
            'collection_request', $collectionRequest->id,
            [], ['method' => $collectionRequest->method, 'operator' => $collectionRequest->operator]);

        return response()->json($this->serialize($collectionRequest), 201);
    }

    public function cancel(Request $request, CollectionRequest $collectionRequest)
    {
        $this->ensureBelongsToRestaurant($request, $collectionRequest);

        abort_unless(
            in_array($request->user()->role, self::CANCEL_ROLES, true),
            403,
            "Vous n'êtes pas autorisé à annuler cette demande d'encaissement."
        );

        $error = DB::transaction(function () use ($collectionRequest) {
            $locked = CollectionRequest::whereKey($collectionRequest->id)->lockForUpdate()->first();

            if ($locked->status === 'cancelled') {
                return 'Cette demande est déjà annulée.';
            }
            if ($locked->status !== 'pending') {
                return 'Seules les demandes en attente peuvent être annulées.';
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            Order::whereIn('id', $locked->orders()->pluck('orders.id')->all())
                ->where('pending_collection_request_id', $locked->id)
                ->update(['pending_collection_request_id' => null]);

            return null;
        });

        if ($error) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $collectionRequest->refresh()->load(['orders', 'table:id,number', 'requester:id,name']);

        LogService::log($request, 'collection_request.cancelled',
            "Demande d'encaissement annulée — table #{$collectionRequest->table_id}",
            'collection_request', $collectionRequest->id);

        return response()->json($this->serialize($collectionRequest));
    }

    public function validateCollection(Request $request, CollectionRequest $collectionRequest)
    {
        $this->ensureBelongsToRestaurant($request, $collectionRequest);

        abort_unless(
            in_array($request->user()->role, self::COLLECT_ROLES, true),
            403,
            "Seuls le caissier, le gérant et l'administrateur peuvent valider une demande d'encaissement."
        );

        $validated = $request->validate([
            'receipt_confirmed' => 'accepted',
            'reference' => ['nullable', 'string', 'max:255'],
        ], [
            'receipt_confirmed.accepted' => 'Vous devez confirmer avoir reçu le paiement.',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $isMobileMoney = $collectionRequest->method === 'mobile_money';

        if ($isMobileMoney) {
            $request->validate([
                'reference' => ['required', 'string', 'max:255'],
            ], [
                'reference.required' => 'La référence de la transaction est obligatoire pour un paiement Mobile Money.',
            ]);
        }

        $error = null;

        DB::transaction(function () use ($request, $collectionRequest, $validated, $restaurantId, $isMobileMoney, &$error) {
            $locked = CollectionRequest::whereKey($collectionRequest->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                $error = 'Seule une demande en attente peut être validée.';

                return;
            }

            // Verrouiller toutes les commandes (ordre par id pour éviter les deadlocks)
            $orders = Order::whereIn(
                'id',
                $locked->orders()->pluck('orders.id')->all()
            )->orderBy('id')->lockForUpdate()->get();

            // Refuser si une commande n'est plus servie ou a déjà été partiellement payée
            foreach ($orders as $order) {
                if ($order->status !== 'served') {
                    $error = "La commande {$order->order_number} n'est plus servie : la validation est annulée.";

                    return;
                }

                $hasPartial = Payment::where('order_id', $order->id)
                    ->whereIn('status', ['pending', 'completed'])
                    ->exists();

                if ($hasPartial) {
                    $error = 'Une commande de cette demande possède déjà un paiement partiel. Veuillez la régler séparément.';

                    return;
                }

                if ($order->due_amount <= 0) {
                    $error = "La commande {$order->order_number} est déjà entièrement payée.";

                    return;
                }
            }

            // Vérifier l'unicité de la référence MM (à l'échelle du groupe)
            if ($isMobileMoney) {
                $alreadyUsed = Payment::referenceAlreadyUsed(
                    $restaurantId,
                    $locked->operator,
                    $validated['reference'],
                    null,
                );

                if ($alreadyUsed) {
                    throw ValidationException::withMessages([
                        'reference' => 'Cette référence de transaction a déjà été utilisée pour cet opérateur.',
                    ]);
                }
            }

            // Création des paiements (un par commande)
            $groupId = (string) Str::uuid();

            foreach ($orders as $order) {
                Payment::create([
                    'id' => (string) Str::uuid(),
                    'order_id' => $order->id,
                    'user_id' => $request->user()->id,
                    'payment_group_id' => $groupId,
                    'amount' => $order->due_amount,
                    'method' => $locked->method,
                    'operator' => $isMobileMoney ? $locked->operator : null,
                    'reference' => $isMobileMoney ? $validated['reference'] : null,
                    'transaction_data' => [
                        'receipt_confirmed_at' => now()->toIso8601String(),
                        'collection_request_id' => $locked->id,
                    ],
                    'status' => 'completed',
                ]);
            }

            // Mise à jour de chaque commande
            foreach ($orders as $order) {
                $order->update(['pending_collection_request_id' => null]);
                $order->recalculate();
            }

            $locked->update([
                'status' => 'validated',
                'validated_by' => $request->user()->id,
                'validated_at' => now(),
            ]);
        });

        if ($error !== null) {
            return response()->json(['success' => false, 'message' => $error], 422);
        }

        $collectionRequest->refresh()->load(['orders', 'table:id,number', 'requester:id,name']);

        LogService::log($request, 'collection_request.validated',
            "Demande d'encaissement validée — table #{$collectionRequest->table_id}",
            'collection_request', $collectionRequest->id,
            [], ['method' => $collectionRequest->method, 'operator' => $collectionRequest->operator]);

        return response()->json($this->serialize($collectionRequest));
    }

    /** Représentation JSON commune (liste + détail + réponse de création). */
    private function serialize(CollectionRequest $collectionRequest): array
    {
        return [
            'id' => $collectionRequest->id,
            'restaurant_id' => $collectionRequest->restaurant_id,
            'table' => $collectionRequest->table
                ? ['id' => $collectionRequest->table->id, 'number' => $collectionRequest->table->number]
                : null,
            'requested_by' => $collectionRequest->requested_by,
            'requester' => $collectionRequest->requester
                ? ['id' => $collectionRequest->requester->id, 'name' => $collectionRequest->requester->name]
                : null,
            'method' => $collectionRequest->method,
            'operator' => $collectionRequest->operator,
            'phone_number' => $collectionRequest->phone_number,
            'beneficiary_name' => $collectionRequest->beneficiary_name,
            'status' => $collectionRequest->status,
            'validated_by' => $collectionRequest->validated_by,
            'validated_at' => $collectionRequest->validated_at?->toIso8601String(),
            'cancelled_at' => $collectionRequest->cancelled_at?->toIso8601String(),
            'total_amount' => $collectionRequest->totalAmount(),
            'orders' => $collectionRequest->orders->map(fn ($order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'due_amount' => $order->due_amount,
            ])->all(),
            'created_at' => $collectionRequest->created_at?->toIso8601String(),
        ];
    }
}
