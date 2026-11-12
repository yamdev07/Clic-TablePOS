<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TableController extends Controller
{
    private function ensureTableBelongsToRestaurant(
        Request $request,
        Table $table
    ): void {
        abort_unless(
            (string) $table->restaurant_id ===
            (string) $request->user()->restaurant_id,
            404
        );
    }

    public function index(Request $request)
    {
        return response()->json(
            Table::with(['currentOrder.items:id,order_id,item_name,quantity,kitchen_status,special_instructions,out_of_stock_at'])->where('restaurant_id', $request->user()->restaurant_id)
                ->orderBy('number')
                ->get()
        );
    }

    public function show(Request $request, Table $table)
    {
        $this->ensureTableBelongsToRestaurant($request, $table);

        return response()->json($table->load(['currentOrder.items']));
    }

    public function store(Request $request)
    {
        $request->validate([
            'number' => 'required|string',
            'capacity' => 'integer|min:1|max:20',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $currentCount = Table::where('restaurant_id', $restaurantId)->count();

        if ($currentCount >= 50) {
            return response()->json([
                'message' => 'Limite atteinte : maximum 50 tables par restaurant.',
            ], 422);
        }

        $table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'number' => $request->number,
            'capacity' => $request->capacity ?? 4,
            'status' => 'free',
            'qr_code' => 'https://clicettable.com/t/'.Str::random(8),
        ]);

        LogService::log($request, 'table.created',
            "Table {$table->number} créée (capacité : {$table->capacity})",
            'table', $table->id);

        return response()->json($table, 201);
    }

    public function update(Request $request, Table $table)
    {
        $this->ensureTableBelongsToRestaurant($request, $table);
        $old = $table->only(['number', 'capacity']);
        $table->update($request->only(['number', 'capacity', 'x_position', 'y_position']));

        LogService::log($request, 'table.updated',
            "Table {$table->number} modifiée",
            'table', $table->id, $old, $table->only(['number', 'capacity']));

        return response()->json($table);
    }

    public function destroy(Table $table, Request $request)
    {
        $this->ensureTableBelongsToRestaurant($request, $table);

        // Empêcher la suppression si la table possède des commandes
        if ($table->orders()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une table associée à des commandes. Conservez-la pour préserver l’historique.',
            ], 422);
        }

        $tableNumber = $table->number;
        $tableId = $table->id;

        // Supprimer la table
        $table->delete();

        // Enregistrer le journal uniquement après la suppression réussie
        LogService::log(
            $request,
            'table.deleted',
            "Table {$tableNumber} supprimée",
            'table',
            $tableId
        );

        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Table $table)
    {
        $this->ensureTableBelongsToRestaurant($request, $table);
        $request->validate([
            'status' => 'required|in:free,occupied,reserved,dirty',
        ]);

        $old = $table->status;
        $table->update(['status' => $request->status]);

        LogService::log($request, 'table.status_changed',
            "Table {$table->number} : {$old} → {$request->status}",
            'table', $table->id,
            ['status' => $old], ['status' => $request->status]);

        return response()->json($table);
    }

    public function layout(Request $request)
    {
        $tables = Table::where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('number')
            ->get();

        return response()->json($tables);
    }

    public function updateLayout(Request $request)
    {
        abort_unless(
            in_array($request->user()->role, ['admin', 'manager'], true),
            403,
            "Seuls l'administrateur et le gérant peuvent déplacer les tables."
        );

        $validated = $request->validate([
            'tables' => 'required|array|min:1|max:50',
            'tables.*.id' => 'required|uuid',
            'tables.*.x_position' => 'required|integer|min:0|max:2000',
            'tables.*.y_position' => 'required|integer|min:0|max:2000',
        ]);

        $restaurantId = $request->user()->restaurant_id;

        // 1) Vérifier que toutes les tables appartiennent au restaurant
        $tableIds = collect($validated['tables'])->pluck('id')->all();
        $existing = Table::where('restaurant_id', $restaurantId)
            ->whereIn('id', $tableIds)
            ->pluck('id')
            ->all();

        if (count($existing) !== count(array_unique($tableIds))) {
            return response()->json([
                'message' => 'Une ou plusieurs tables sont introuvables.',
            ], 422);
        }

        // 2) Transaction : tout ou rien
        DB::transaction(function () use ($validated, $restaurantId) {
            foreach ($validated['tables'] as $entry) {
                Table::where('id', $entry['id'])
                    ->where('restaurant_id', $restaurantId)
                    ->update([
                        'x_position' => $entry['x_position'],
                        'y_position' => $entry['y_position'],
                    ]);
            }
        });

        LogService::log($request, 'tables.layout_updated',
            'Layout mis à jour ('.count($validated['tables']).' tables)',
            'table', null);

        return response()->json(['success' => true, 'updated' => count($validated['tables'])]);
    }

    public function rotate(Request $request, Table $table)
    {
        $this->ensureTableBelongsToRestaurant($request, $table);

        abort_unless(
            in_array($request->user()->role, ['admin', 'manager', 'waiter'], true),
            403,
            "Seuls l'administrateur, le gérant et le serveur peuvent pivoter une table."
        );

        $validated = $request->validate([
            'rotation' => 'required|integer|in:0,90',
        ]);

        $old = $table->rotation;
        $table->update(['rotation' => $validated['rotation']]);

        LogService::log($request, 'table.rotated',
            "Table {$table->number} pivotée : {$old}° → {$validated['rotation']}°",
            'table', $table->id,
            ['rotation' => $old], ['rotation' => $validated['rotation']]);

        return response()->json($table);
    }

    public function updateModel(Request $request, Table $table)
    {
        $this->ensureTableBelongsToRestaurant($request, $table);

        abort_unless(
            in_array($request->user()->role, ['admin', 'manager'], true),
            403,
            "Seuls l'administrateur et le gérant peuvent modifier le modèle d'une table."
        );

        $validated = $request->validate([
            'shape' => ['required', 'string', 'in:round,square,rectangle,banquette,oval'],
            'size_label' => ['required', 'string', 'max:30'],
        ]);

        $old = $table->only(['shape', 'size_label']);
        $table->update([
            'shape' => $validated['shape'],
            'size_label' => $validated['size_label'],
        ]);

        LogService::log($request, 'table.model_changed',
            "Modèle de la table {$table->number} : {$validated['shape']} {$validated['size_label']}",
            'table', $table->id, $old, $table->only(['shape', 'size_label']));

        return response()->json($table);
    }
}
