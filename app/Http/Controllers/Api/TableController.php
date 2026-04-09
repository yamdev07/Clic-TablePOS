<?php

// app/Http/Controllers/Api/TableController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TableController extends Controller
{
    public function index()
    {
        $tables = Table::with('currentOrder')->orderBy('number')->get();

        return response()->json($tables);
    }

    public function show(Table $table)
    {
        return response()->json($table->load('currentOrder'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'number' => 'required|string',
            'capacity' => 'integer|min:1',
        ]);

        $table = Table::create([
            'id' => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'number' => $request->number,
            'capacity' => $request->capacity ?? 4,
            'status' => 'free',
            'qr_code' => 'https://clicettable.com/t/'.Str::random(8),
        ]);

        return response()->json($table, 201);
    }

    public function update(Request $request, Table $table)
    {
        $table->update($request->only(['number', 'capacity', 'x_position', 'y_position']));

        return response()->json($table);
    }

    public function destroy(Table $table)
    {
        $table->delete();

        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Table $table)
    {
        $request->validate([
            'status' => 'required|in:free,occupied,reserved,dirty',
        ]);

        $table->update(['status' => $request->status]);

        return response()->json($table);
    }
}
