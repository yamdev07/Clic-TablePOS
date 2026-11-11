<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CollectionRequestController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KitchenController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentNumberController;
use App\Http\Controllers\Api\PaymentsHistoryController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\RestaurantSettingsController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\SupplementController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Routes protégées par token
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Paramètres fiscaux du restaurant
    Route::get('/restaurant/settings', [RestaurantSettingsController::class, 'show']);
    Route::patch('/restaurant/settings', [RestaurantSettingsController::class, 'update']);
    // Dashboard (admin et gérant)
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Tables
    Route::get('/tables', [TableController::class, 'index']);
    Route::get('/tables/{table}', [TableController::class, 'show']);
    Route::post('/tables', [TableController::class, 'store']);
    Route::put('/tables/{table}', [TableController::class, 'update']);
    Route::delete('/tables/{table}', [TableController::class, 'destroy']);
    Route::patch('/tables/{table}/status', [TableController::class, 'updateStatus']);

    // Catégories
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Menu
    Route::get('/menu', [MenuController::class, 'index']);
    Route::get('/menu/{item}', [MenuController::class, 'show']);
    Route::post('/menu', [MenuController::class, 'store']);
    Route::match(['put', 'patch'], '/menu/{item}', [MenuController::class, 'update']);
    Route::delete('/menu/{item}', [MenuController::class, 'destroy']);
    Route::patch('/menu/{item}/availability', [MenuController::class, 'toggleAvailability']);
    // Suppléments partagés
    Route::get('/supplements', [SupplementController::class, 'index']);
    Route::post('/supplements', [SupplementController::class, 'store']);
    Route::delete('/supplements/{supplement}', [SupplementController::class, 'destroy']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::post('/orders/{order}/items', [OrderController::class, 'addItem']);
    Route::delete('/orders/{order}/items/{item}', [OrderController::class, 'removeItem']);
    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/send-to-kitchen', [OrderController::class, 'sendToKitchen']);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);
    // Facture
    Route::get('/orders/{order}/receipt', [ReceiptController::class, 'show']);

    // Kitchen
    Route::get('/kitchen/pending', [KitchenController::class, 'pendingOrders']);
    Route::patch('/kitchen/orders/{order}/cooking', [KitchenController::class, 'startOrderCooking']);
    Route::patch('/kitchen/orders/{order}/ready', [KitchenController::class, 'markOrderReady']);
    Route::post('/kitchen/orders/{order}/call-waiter', [KitchenController::class, 'callWaiter']);
    Route::patch('/kitchen/items/{item}/rupture', [KitchenController::class, 'markOutOfStock']);

    // Payments
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
    Route::get('/orders/{order}/payments', [PaymentController::class, 'index']);
    // Caisse
    Route::get('/cash/summary', [CashController::class, 'summary']);
    Route::get('/payments', [PaymentsHistoryController::class, 'index']);
    // Numéros Mobile Money du restaurant
    Route::get('/payment-numbers', [PaymentNumberController::class, 'index']);
    Route::post('/payment-numbers', [PaymentNumberController::class, 'store']);
    Route::patch('/payment-numbers/{paymentNumber}', [PaymentNumberController::class, 'update']);
    Route::delete('/payment-numbers/{paymentNumber}', [PaymentNumberController::class, 'destroy']);
    // Demandes d'encaissement
    Route::get('/collection-requests', [CollectionRequestController::class, 'index']);
    Route::get('/collection-requests/{collectionRequest}', [CollectionRequestController::class, 'show']);
    Route::post('/collection-requests', [CollectionRequestController::class, 'store']);
    Route::post('/collection-requests/{collectionRequest}/validate', [CollectionRequestController::class, 'validateCollection']);
    Route::delete('/collection-requests/{collectionRequest}', [CollectionRequestController::class, 'cancel']);

    // Stats sidebar (1 appel au lieu de 2)
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/stats/z-report/closed', [StatsController::class, 'closedList']);
    Route::get('/stats/z-report/closed/{id}', [StatsController::class, 'closedShow']);

    Route::get('/stats/z-report', [StatsController::class, 'zReport']);
    Route::post('/stats/z-report/close', [StatsController::class, 'close']);

    // Tableau de bord (admin et gérant)
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Logs (admin only)
    Route::get('/logs', [LogController::class, 'index']);

    // Users (admin only)
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
});

// Route de test
Route::get('/test', function () {
    return response()->json(['message' => 'API works!']);
});
