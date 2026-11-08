<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentNumber;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentNumberController extends Controller
{
    private const READ_ROLES = ['admin', 'manager', 'cashier', 'waiter'];

    private const WRITE_ROLES = ['admin', 'manager'];

    private function ensureNumberBelongsToRestaurant(Request $request, PaymentNumber $paymentNumber): void
    {
        abort_unless(
            (string) $paymentNumber->restaurant_id === (string) $request->user()->restaurant_id,
            404
        );
    }

    private function ensureRole(Request $request, array $roles): void
    {
        abort_unless(
            in_array($request->user()->role, $roles, true),
            403,
            "Vous n'avez pas le droit d'effectuer cette action."
        );
    }

    public function index(Request $request)
    {
        $this->ensureRole($request, self::READ_ROLES);

        $query = PaymentNumber::where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('operator')
            ->orderBy('created_at');

        if ($request->filled('operator')) {
            $query->where('operator', $request->input('operator'));
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->ensureRole($request, self::WRITE_ROLES);

        // Les espaces sont retirés avant la validation : "+229 97 00 11 22" devient "+22997001122"
        $request->merge([
            'phone_number' => preg_replace('/\s+/', '', (string) $request->input('phone_number')),
        ]);

        $restaurantId = $request->user()->restaurant_id;

        $validated = $request->validate([
            'operator' => ['required', Rule::in(PaymentNumber::OPERATORS)],
            'phone_number' => [
                'required',
                'regex:/^\+229\d{8,10}$/',
                Rule::unique('payment_numbers', 'phone_number')
                    ->where('restaurant_id', $restaurantId)
                    ->where('operator', $request->input('operator')),
            ],
            'beneficiary_name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $number = PaymentNumber::create([
            'restaurant_id' => $restaurantId,
            'operator' => $validated['operator'],
            'phone_number' => $validated['phone_number'],
            'beneficiary_name' => $validated['beneficiary_name'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        LogService::log($request, 'payment_number.created',
            "Numéro {$number->operator} {$number->phone_number} ajouté ({$number->beneficiary_name})",
            'payment_number', $number->id);

        return response()->json($number, 201);
    }

    public function update(Request $request, PaymentNumber $paymentNumber)
    {
        $this->ensureNumberBelongsToRestaurant($request, $paymentNumber);
        $this->ensureRole($request, self::WRITE_ROLES);

        // Seuls le bénéficiaire et l'état sont modifiables : pour changer de numéro, on le supprime et on en ajoute un autre
        $validated = $request->validate([
            'beneficiary_name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $paymentNumber->only(['beneficiary_name', 'is_active']);
        $paymentNumber->update($validated);

        LogService::log($request, 'payment_number.updated',
            "Numéro {$paymentNumber->operator} {$paymentNumber->phone_number} modifié",
            'payment_number', $paymentNumber->id,
            $old, $paymentNumber->only(['beneficiary_name', 'is_active']));

        return response()->json($paymentNumber);
    }

    public function destroy(Request $request, PaymentNumber $paymentNumber)
    {
        $this->ensureNumberBelongsToRestaurant($request, $paymentNumber);
        $this->ensureRole($request, self::WRITE_ROLES);

        $paymentNumber->delete();

        // Le journal est écrit après la suppression réussie, pas avant
        LogService::log($request, 'payment_number.deleted',
            "Numéro {$paymentNumber->operator} {$paymentNumber->phone_number} supprimé",
            'payment_number', $paymentNumber->id);

        return response()->json(null, 204);
    }
}
