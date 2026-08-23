<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\PushTokenController;
use App\Http\Controllers\Api\V1\Client\CatalogController as ClientCatalogController;
use App\Http\Controllers\Api\V1\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Api\V1\Client\OrderController as ClientOrderController;
use App\Http\Controllers\Api\V1\Client\StockEntryController as ClientStockEntryController;
use App\Http\Controllers\Api\V1\Supplier\DashboardController as SupplierDashboardController;
use App\Http\Controllers\Api\V1\Supplier\ProductController;
use App\Http\Controllers\Api\V1\Supplier\CustomerController;
use App\Http\Controllers\Api\V1\Supplier\StockEntryController as SupplierStockEntryController;
use App\Http\Controllers\Api\V1\Supplier\WarehouseStockController as SupplierWarehouseStockController;
use App\Http\Controllers\Api\V1\Supplier\UnitController;
use App\Http\Controllers\Api\V1\Admin\SupplierController as AdminSupplierController;
use App\Http\Controllers\Api\V1\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\V1\Admin\AdminController as AdminAdminController;
use App\Http\Controllers\Api\V1\Supplier\NotificationController;
use App\Http\Controllers\Api\V1\Supplier\OrderController as SupplierOrderController;
use App\Http\Controllers\Api\V1\Supplier\ResubscribeController;
use App\Http\Controllers\Api\V1\Supplier\SupplierLocationController;
use App\Http\Controllers\Api\V1\Supplier\WalkInSaleController;
use App\Http\Controllers\Api\V1\Client\ClientLocationController;

/*
|--------------------------------------------------------------------------
| API Routes — Gestion de stocks clients
|--------------------------------------------------------------------------
*/

// --- Auth (publique) ---
Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// --- Routes protégées ---
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/switch-mode', [AuthController::class, 'switchMode']);

    // Profil (accessible à tout utilisateur connecté, pas de scope tenant)
    Route::post('/auth/avatar', [ProfileController::class, 'updateAvatar']);
    Route::put('/auth/password', [ProfileController::class, 'updatePassword']);

    // Token push (notifications sur l'icône / barre de notifications)
    Route::post('/push-token', [PushTokenController::class, 'store']);
    Route::delete('/push-token', [PushTokenController::class, 'destroy']);

    // --- Notifications (accessibles même si abonnement suspendu) ---
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);

    // --- Plans disponibles (réabonnement, accessible même si suspendu) ---
    Route::get('/plans/available', [ResubscribeController::class, 'plans']);
    Route::post('/supplier/resubscribe', [ResubscribeController::class, 'resubscribe']);

    // --- Client final (mobile) ---
    Route::prefix('client')->middleware('client.scope')->group(function () {
        Route::get('/dashboard', [ClientDashboardController::class, 'index']);
        Route::get('/catalog', [ClientCatalogController::class, 'index']);
        Route::get('/stock-entries', [ClientStockEntryController::class, 'index']);
        Route::post('/stock-entries', [ClientStockEntryController::class, 'store']);
        Route::delete('/stock-entries/{entry}', [ClientStockEntryController::class, 'destroy']);

        // Commandes
        Route::post('/location', [ClientLocationController::class, 'store']);
        Route::get('/orders', [ClientOrderController::class, 'index']);
        Route::post('/orders', [ClientOrderController::class, 'store']);
        Route::get('/orders/{order}', [ClientOrderController::class, 'show']);
        Route::post('/orders/{order}/cancel', [ClientOrderController::class, 'cancel']);
        Route::post('/orders/{order}/confirm-delivery', [ClientOrderController::class, 'confirmDelivery']);
    });

    // --- Fournisseur ---
    Route::prefix('supplier')->middleware('tenant')->group(function () {
        Route::get('/dashboard', [SupplierDashboardController::class, 'index']);

        // Produits
        Route::apiResource('/products', ProductController::class);

        // Unités paramétrables
        Route::get('/units', [UnitController::class, 'index']);
        Route::post('/units', [UnitController::class, 'store']);
        Route::delete('/units/{unit}', [UnitController::class, 'destroy']);

        // Clients
        Route::apiResource('/customers', CustomerController::class);
        Route::post('/customers/{customer}/reset-password', [CustomerController::class, 'resetPassword']);
        Route::post('/customers/{customer}/products', [CustomerController::class, 'attachProduct']);
        Route::post('/customers/{customer}/products/batch', [CustomerController::class, 'attachProductsBatch']);
        Route::delete('/customers/{customer}/products/{product}', [CustomerController::class, 'detachProduct']);
        Route::get('/customers/{customer}/stock', [CustomerController::class, 'stock']);

        // MAJ livraison
        Route::put('/customer-products/{id}', [CustomerController::class, 'updateProduct']);
        Route::get('/customer-products/{id}/history', [CustomerController::class, 'productHistory']);

        // Abonnement
        Route::get('/subscription', function (Request $request) {
            $supplier = \App\Models\Supplier::find($request->input('_tenant_supplier_id'));
            return response()->json($supplier->quotaInfo());
        });

        // Saisies de stock
        Route::get('/stock-entries', [SupplierStockEntryController::class, 'index']);
        Route::post('/stock-entries', [SupplierStockEntryController::class, 'store']);

        // Stock entrepôt
        Route::get('/warehouse-stock', [SupplierWarehouseStockController::class, 'index']);
        Route::post('/warehouse-stock/{product}/receive', [SupplierWarehouseStockController::class, 'receive']);
        Route::post('/warehouse-stock/{product}/adjust', [SupplierWarehouseStockController::class, 'adjust']);
        Route::get('/warehouse-stock/{product}/history', [SupplierWarehouseStockController::class, 'history']);
        Route::post('/walk-in-sales', [WalkInSaleController::class, 'store']);
        Route::post('/location', [SupplierLocationController::class, 'store']);
        Route::post('/customers/{customer}/location', [CustomerController::class, 'storeLocation']);
        Route::get('/customers-map', [CustomerController::class, 'mapData']);

        // Commandes (pending-count et pending-deliveries AVANT {order} pour éviter le conflit de binding)
        Route::get('/orders/pending-count', [SupplierOrderController::class, 'pendingCount']);
        Route::get('/orders/pending-deliveries', [SupplierOrderController::class, 'pendingDeliveries']);
        Route::get('/orders', [SupplierOrderController::class, 'index']);
        Route::get('/orders/{order}', [SupplierOrderController::class, 'show']);
        Route::post('/orders/{order}/accept', [SupplierOrderController::class, 'accept']);
        Route::post('/orders/{order}/reject', [SupplierOrderController::class, 'reject']);
        Route::post('/orders/{order}/message', [SupplierOrderController::class, 'message']);
        Route::post('/orders/{order}/deliver', [SupplierOrderController::class, 'deliver']);
    });

    // --- Admin / Éditeur (principal et secondaires) ---
    Route::prefix('admin')->middleware('role:super_admin|admin')->group(function () {
        Route::apiResource('/suppliers', AdminSupplierController::class)->only(['index', 'store']);
        Route::post('/suppliers/{supplier}/toggle-status', [AdminSupplierController::class, 'toggleStatus']);
        Route::post('/suppliers/{supplier}/reset-password', [AdminSupplierController::class, 'resetPassword']);
        // Plans
        Route::apiResource('/plans', AdminPlanController::class)->only(['index', 'store', 'update']);
        Route::post('/suppliers/{supplier}/assign-plan', [AdminPlanController::class, 'assignPlan']);
        Route::get('/subscription-stats', [AdminPlanController::class, 'stats']);

        // Gestion des administrateurs : réservée au principal uniquement
        Route::middleware('role:super_admin')->group(function () {
            Route::get('/admins', [AdminAdminController::class, 'index']);
            Route::post('/admins', [AdminAdminController::class, 'store']);
            Route::delete('/admins/{admin}', [AdminAdminController::class, 'destroy']);
        });
    });
});
