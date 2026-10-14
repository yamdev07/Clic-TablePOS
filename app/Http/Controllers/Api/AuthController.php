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

            if ($reason = $user->accessDenialReason()) {
                return response()->json([
                    'success' => false,
                    'message' => $reason,
                    'code'    => 'account_inactive',
                ], 403);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            // Log connexion (journal par restaurant : pas pour les comptes plateforme)
            if ($user->restaurant_id) {
                ActivityLog::create([
                    'restaurant_id' => $user->restaurant_id,
                    'user_id'       => $user->id,
                    'user_name'     => $user->name,
                    'action'        => 'auth.login',
                    'description'   => "{$user->name} s'est connecté(e)",
                    'entity_type'   => 'user',
                    'entity_id'     => $user->id,
                    'old_values'    => [],
                    'new_values'    => [],
                    'ip_address'    => $request->ip(),
                    'user_agent'    => substr($request->userAgent() ?? '', 0, 255),
                ]);
            }

            $user->update(['last_login_at' => now()]);

            return response()->json([
                'success' => true,
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                    'restaurant_id' => $user->restaurant_id,
                ],
                'token' => $token,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur lors de la connexion',
            ], 500);
        }
    }

    public function register(Request $request)
    {
        // Inscription SaaS : crée un nouveau restaurant (en essai) et son administrateur
        $request->validate([
            'restaurant_name' => 'required|string|max:255',
            'phone'           => 'nullable|string|max:30',
            'name'            => 'required|string|max:255',
            'email'           => 'required|email|max:255|unique:users,email|unique:restaurants,email',
            'password'        => 'required|string|min:8|confirmed',
        ]);

        $user = DB::transaction(function () use ($request) {
            $restaurant = Restaurant::create([
                'id'            => (string) Str::uuid(),
                'name'          => $request->restaurant_name,
                'slug'          => Restaurant::uniqueSlug($request->restaurant_name),
                'email'         => $request->email,
                'phone'         => $request->phone,
                'status'        => 'trial',
                'trial_ends_at' => now()->addDays(Restaurant::TRIAL_DAYS),
            ]);

            return User::create([
                'id'            => (string) Str::uuid(),
                'restaurant_id' => $restaurant->id,
                'name'          => $request->name,
                'email'         => $request->email,
                'phone'         => $request->phone,
                'password'      => Hash::make($request->password),
                'role'          => 'admin',
                'is_active'     => true,
            ]);
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'role'          => $user->role,
                'restaurant_id' => $user->restaurant_id,
            ],
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
        $user = $request->user()->load('restaurant:id,name,slug,status,trial_ends_at,currency');

        return response()->json($user);
    }
}
