<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $user = User::where('email', $request->email)->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiants incorrects',
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            // Log connexion
            ActivityLog::create([
                'restaurant_id' => $user->restaurant_id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'action' => 'auth.login',
                'description' => "{$user->name} s'est connecté(e)",
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'old_values' => [],
                'new_values' => [],
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            ]);

            $user->update(['last_login_at' => now()]);

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'restaurant_id' => $user->restaurant_id,
                    'restaurant' => $user->restaurant?->only(['id', 'name']),
                ],
                'token' => $token,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'restaurant_name' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers(),
            ],
            'timezone' => 'nullable|timezone',
        ]);

        $user = DB::transaction(function () use ($validated) {
            $restaurant = Restaurant::create([
                'id' => (string) Str::uuid(),
                'name' => $validated['restaurant_name'],
                'slug' => Str::slug($validated['restaurant_name'])
                    .'-'.Str::random(4),
                'email' => $validated['email'],
                'currency' => 'XOF',
                'timezone' => $validated['timezone'] ?? 'Africa/Porto-Novo',
                'status' => 'active',
            ]);

            return User::create([
                'id' => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'admin',
                'is_active' => true,
            ]);
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user->load('restaurant:id,name'),
            'token' => $token,
        ], 201);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        LogService::log($request, 'auth.logout', "{$user->name} s'est déconnecté(e)", 'user', $user->id);

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('restaurant:id,name'));
    }
}
