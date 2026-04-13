<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TableController extends Controller
{
    public function index()
    {
        return response()->json(Table::with('currentOrder')->orderBy('number')->get());
    }

    public function show(Table $table)
    {
        return response()->json($table->load('currentOrder'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'number'   => 'required|string',
            'capacity' => 'integer|min:1',
        ]);

        $table = Table::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'number'        => $request->number,
            'capacity'      => $request->capacity ?? 4,
            'status'        => 'free',
            'qr_code'       => 'https://clicettable.com/t/' . Str::random(8),
        ]);

        LogService::log($request, 'table.created',
            "Table {$table->number} créée (capacité : {$table->capacity})",
            'table', $table->id);

        return response()->json($table, 201);
    }

    public function update(Request $request, Table $table)
    {
        $old = $table->only(['number', 'capacity']);
        $table->update($request->only(['number', 'capacity', 'x_position', 'y_position']));

        LogService::log($request, 'table.updated',
            "Table {$table->number} modifiée",
            'table', $table->id, $old, $table->only(['number', 'capacity']));

        return response()->json($table);
    }

    public function destroy(Table $table, Request $request)
    {
        LogService::log($request, 'table.deleted',
            "Table {$table->number} supprimée",
            'table', $table->id);

        $table->delete();

        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Table $table)
    {
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
}
