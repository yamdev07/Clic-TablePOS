<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    private function adminOnly(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Accès réservé aux administrateurs');
        }
    }

    public function index(Request $request)
    {
        $this->adminOnly($request);

        $users = User::where('restaurant_id', $request->user()->restaurant_id)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'created_at', 'last_login_at']);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->adminOnly($request);

        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role'     => 'required|in:admin,manager,waiter,kitchen',
        ]);

        $user = User::create([
            'id'            => (string) Str::uuid(),
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'email'         => $request->email,
            'password'      => Hash::make($request->password),
            'role'          => $request->role,
            'is_active'     => true,
        ]);

        LogService::log($request, 'user.created',
            "Compte créé pour {$user->name} (rôle : {$user->role})",
            'user', $user->id);

        return response()->json($user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->adminOnly($request);

        $request->validate([
            'name'      => 'sometimes|string|max:255',
            'role'      => 'sometimes|in:admin,manager,waiter,kitchen',
            'is_active' => 'sometimes|boolean',
            'password'  => 'sometimes|string|min:6',
        ]);

        $old  = $user->only(['name', 'role', 'is_active']);
        $data = $request->only(['name', 'role', 'is_active']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        LogService::log($request, 'user.updated',
            "Compte de {$user->name} modifié",
            'user', $user->id, $old, $user->only(['name', 'role', 'is_active']));

        return response()->json($user->only(['id', 'name', 'email', 'role', 'is_active']));
    }

    public function destroy(User $user, Request $request)
    {
        $this->adminOnly($request);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'Impossible de désactiver votre propre compte'], 422);
        }

        $user->update(['is_active' => false]);

        LogService::log($request, 'user.deactivated',
            "Compte de {$user->name} désactivé",
            'user', $user->id);

        return response()->json(['message' => 'Utilisateur désactivé']);
    }
}
