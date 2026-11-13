<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestaurantSettingsController extends Controller
{
    private const READ_ROLES = ['admin', 'manager'];

    private const WRITE_ROLES = ['admin'];

    public function show(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::READ_ROLES, true),
            403,
            "Seuls l'administrateur et le gérant peuvent consulter les paramètres fiscaux."
        );

        $restaurant = $request->user()->restaurant;

        return response()->json([
            'name' => $restaurant->name,
            'ifu' => $restaurant->ifu,
            'rccm' => $restaurant->rccm,
            'phone' => $restaurant->phone,
            'address' => $restaurant->address,
            'settings' => [
                'tax_enabled' => $restaurant->taxEnabled(),
                'service_charge_enabled' => $restaurant->serviceChargeEnabled(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, self::WRITE_ROLES, true),
            403,
            "Seul l'administrateur peut modifier les paramètres fiscaux."
        );

        $validated = $request->validate([
            'ifu' => ['nullable', 'string', 'max:30'],
            'rccm' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_enabled' => ['nullable', 'boolean'],
            'service_charge_enabled' => ['nullable', 'boolean'],
        ]);

        $restaurant = $request->user()->restaurant;

        DB::transaction(function () use ($validated, $restaurant) {
            $direct = array_filter(
                $validated,
                fn ($key) => in_array($key, ['ifu', 'rccm', 'phone', 'address'], true),
                ARRAY_FILTER_USE_KEY
            );

            if (! empty($direct)) {
                $restaurant->update($direct);
            }

            $settings = is_array($restaurant->settings) ? $restaurant->settings : [];

            if (array_key_exists('tax_enabled', $validated) && $validated['tax_enabled'] !== null) {
                $settings['tax_enabled'] = (bool) $validated['tax_enabled'];
            }
            if (array_key_exists('service_charge_enabled', $validated) && $validated['service_charge_enabled'] !== null) {
                $settings['service_charge_enabled'] = (bool) $validated['service_charge_enabled'];
            }

            $restaurant->update(['settings' => $settings]);
        });

        LogService::log(
            $request,
            'restaurant.fiscal_settings_updated',
            'Paramètres fiscaux mis à jour',
            'restaurant',
            $restaurant->id
        );

        $fresh = $restaurant->fresh();

        return response()->json([
            'name' => $fresh->name,
            'ifu' => $fresh->ifu,
            'rccm' => $fresh->rccm,
            'phone' => $fresh->phone,
            'address' => $fresh->address,
            'settings' => [
                'tax_enabled' => $fresh->taxEnabled(),
                'service_charge_enabled' => $fresh->serviceChargeEnabled(),
            ],
        ]);
    }
}
