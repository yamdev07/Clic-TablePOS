<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TableController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Routes protégées par token
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Tables
    Route::get('/tables', [TableController::class, 'index']);
    Route::get('/tables/{table}', [TableController::class, 'show']);
    Route::post('/tables', [TableController::class, 'store']);
    Route::put('/tables/{table}', [TableController::class, 'update']);
    Route::delete('/tables/{table}', [TableController::class, 'destroy']);
    Route::patch('/tables/{table}/status', [TableController::class, 'updateStatus']);

    // Menu
    Route::get('/menu', [MenuController::class, 'index']);
    Route::get('/menu/{item}', [MenuController::class, 'show']);
    Route::post('/menu', [MenuController::class, 'store']);
    Route::put('/menu/{item}', [MenuController::class, 'update']);
    Route::delete('/menu/{item}', [MenuController::class, 'destroy']);
    Route::patch('/menu/{item}/availability', [MenuController::class, 'toggleAvailability']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::post('/orders/{order}/items', [OrderController::class, 'addItem']);
    Route::delete('/orders/{order}/items/{item}', [OrderController::class, 'removeItem']);
    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/send-to-kitchen', [OrderController::class, 'sendToKitchen']);

    // Kitchen
    Route::get('/kitchen/pending', [KitchenController::class, 'pendingOrders']);
    Route::patch('/kitchen/items/{item}/cooking', [KitchenController::class, 'startCooking']);
    Route::patch('/kitchen/items/{item}/ready', [KitchenController::class, 'markReady']);
    Route::patch('/kitchen/items/{item}/serve', [KitchenController::class, 'markServed']);

    // Payments
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
    Route::get('/orders/{order}/payments', [PaymentController::class, 'index']);
});

// À ajouter à la fin du fichier
Route::get('/test', function () {
    return response()->json(['message' => 'API works!']);
});
