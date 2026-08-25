<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Controllers
use App\Http\Controllers\Api\V1\{
    AuthController,
    UserController,
    CustomerController,
    CategoryController,
    ProductController,
    OrderController,
    SalesRepresentativeController,
    InvoiceController,
    PaymentController,
    ShippingController,
    AdminDashboardController,
    SRDashboardController,

    // Targets
    TargetController,
    IdleEventController,
    OutletVisitController,
    ReportController,
    LocationTrackController,
    PaymentWarningController
};

use App\Http\Controllers\{
    SiteSettingController,
    HealthController,
};

// API Version 1 routes
Route::prefix('v1')->group(function () {

    // Liveness — no database, no auth. Says only that the app booted.
    Route::get('/health', HealthController::class);

    Route::get('/site-setting', [SiteSettingController::class, 'isSuspended']);

    // Public routes
    Route::prefix('auth')->group(function () {
        Route::post('sign-up', [AuthController::class, 'signUp']);
        Route::post('sign-in', [AuthController::class, 'signIn']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::prefix('admin')->group(function () {
            Route::post('sign-in', [AuthController::class, 'adminSignIn']);
        });
    });

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('sign-out', [AuthController::class, 'signOut']);
        });

        // User routes
        Route::prefix('users')->group(function () {
            Route::post('/', [UserController::class, 'store']);
            Route::get('/', [UserController::class, 'index']);
            Route::get('/me', [UserController::class, 'getMe']);
            Route::get('/{id}', [UserController::class, 'show']);
            Route::post('/{id}', [UserController::class, 'update']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
        });

        // Admin Dashboard
        Route::prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        });

        // SR Dashboard
        Route::prefix('sr')->group(function () {
            Route::get('/dashboard', [SRDashboardController::class, 'index']);
        });

        // SalesRepresentative routes
        Route::prefix('sales-representatives')->group(function () {
            Route::post('/', [SalesRepresentativeController::class, 'store']);
            Route::get('/', [SalesRepresentativeController::class, 'index']);
            Route::get('/{id}', [SalesRepresentativeController::class, 'show']);
            Route::post('/{id}', [SalesRepresentativeController::class, 'update']);
            Route::delete('/{id}', [SalesRepresentativeController::class, 'destroy']);

            Route::prefix('my')->group(function () {
                // orders
                Route::get('/recent-orders', [SalesRepresentativeController::class, 'getMyRecentOrders']);
                Route::get('/orders', [SalesRepresentativeController::class, 'getMyOrders']);
            });
        });

         // Customer routes
        Route::prefix('customers')->group(function () {
            Route::post('/', [CustomerController::class, 'store']);
            Route::get('/', [CustomerController::class, 'index']);
            Route::get('/{id}', [CustomerController::class, 'show']);
            Route::post('/{id}', [CustomerController::class, 'update']);
            Route::delete('/{id}', [CustomerController::class, 'destroy']);
        });

        // Category routes
        Route::prefix('categories')->group(function () {
            Route::post('/', [CategoryController::class, 'store']);
            Route::get('/', [CategoryController::class, 'index']);
            Route::get('/{id}', [CategoryController::class, 'show']);
            Route::post('/{id}', [CategoryController::class, 'update']);
            Route::delete('/{id}', [CategoryController::class, 'destroy']);
        });

        // Product routes
        Route::prefix('products')->group(function () {
            Route::post('/', [ProductController::class, 'store']);
            Route::get('/', [ProductController::class, 'index']);
            Route::get('/{id}', [ProductController::class, 'show']);
            Route::post('/{id}', [ProductController::class, 'update']);
            Route::delete('/{id}', [ProductController::class, 'destroy']);

        });

        Route::get('/product-lists', [ProductController::class, 'getProducts']);

        // Order routes
        Route::prefix('orders')->group(function () {
            Route::post('/', [OrderController::class, 'store']);
            Route::get('/', [OrderController::class, 'index']);
            Route::get('/{id}', [OrderController::class, 'show']);
            Route::post('/{id}', [OrderController::class, 'update']);
            Route::post('/{id}/add-more-item', [OrderController::class, 'addMoreOrderItem']);
            Route::post('/{id}/remove-item', [OrderController::class, 'removeOrderItem']);
            Route::post('/{id}/cancel', [OrderController::class, 'cancelOrder']);
            Route::delete('/{id}', [OrderController::class, 'destroy']);
        });

        // Invoice routes
        Route::prefix('invoices')->group(function () {
            Route::post('/', [InvoiceController::class, 'store']);
            Route::get('/', [InvoiceController::class, 'index']);
            Route::get('/{id}', [InvoiceController::class, 'show']);
            Route::get('/{id}/download', [InvoiceController::class, 'downloadInvoice']);
            Route::post('/{id}', [InvoiceController::class, 'update']);
            Route::delete('/{id}', [InvoiceController::class, 'destroy']);
        });

        // Payment routes
        Route::prefix('payments')->group(function () {
            Route::post('/', [PaymentController::class, 'store']);
            Route::get('/', [PaymentController::class, 'index']);
            Route::post('/{id}/status', [PaymentController::class, 'updateStatus']);
        });

        // Payment Warnings
        Route::prefix('payment-warnings')->group(function () {
            Route::get('/',            [PaymentWarningController::class, 'index']);
            Route::get('/summary',     [PaymentWarningController::class, 'summary']);
            Route::post('/{id}/note',  [PaymentWarningController::class, 'addNote']);
            Route::post('/{id}/resolve', [PaymentWarningController::class, 'resolve']);
            Route::post('/generate',   [PaymentWarningController::class, 'generate']); // admin only
        });

         // Shipping routes
        Route::prefix('shippings')->group(function () {
            Route::post('/ready_to_ship/{id}', [ShippingController::class, 'readyToShip']);
            Route::post('/shipped/{id}', [ShippingController::class, 'store']);
            Route::post('/delivered/{id}', [ShippingController::class, 'delivered']);
            Route::get('/', [ShippingController::class, 'index']);
            // Route::get('/{id}', [PaymentController::class, 'show']);
            // Route::post('/{id}', [PaymentController::class, 'update']);
            // Route::delete('/{id}', [PaymentController::class, 'destroy']);
        });

        // Location endpoints
        Route::prefix('locations')->group(function () {
            Route::post('/points', [LocationTrackController::class, 'batchStore']);
            Route::get('/live', [LocationTrackController::class, 'live']);
            Route::get('/reports', [LocationTrackController::class, 'report']);
            Route::get('/path', [LocationTrackController::class, 'path']);
        });

        // ── Targets ───────────────────────────────────────────────────────────

        Route::prefix('targets')->group(function () {
            // GET  /api/targets                  → সব targets list
            // GET  /api/targets/achievement      → achievement summary (SR + Admin)
            // POST /api/targets                  → নতুন target তৈরি (Admin only)
            // PUT  /api/targets/{target}         → target update (Admin only)
            // DELETE /api/targets/{target}       → target delete (Admin only)

            Route::get('achievement', [TargetController::class, 'achievement']);
            Route::get('/', [TargetController::class, 'index']);
            Route::post('/', [TargetController::class, 'store']);
            Route::put('{target}', [TargetController::class, 'update']);
            Route::delete('{target}', [TargetController::class, 'destroy']);
        });

        // ── Idle Events ───────────────────────────────────────────────────────

        Route::prefix('idle-events')->group(function () {
            // GET  /api/idle-events              → list (Admin only)
            // GET  /api/idle-events/my-status    → current SR এর idle status
            // POST /api/idle-events/log          → SR নিজে idle log করে
            // POST /api/idle-events/{id}/resolve → Admin resolve করে

            Route::get('my-status', [IdleEventController::class, 'myStatus']);
            Route::post('log', [IdleEventController::class, 'log']);
            Route::get('/', [IdleEventController::class, 'index']);
            Route::post('{idleEvent}/resolve', [IdleEventController::class, 'resolve']);
        });

        // ── Outlet Visits ─────────────────────────────────────────────────────

        Route::prefix('outlet-visits')->group(function () {
            // GET  /api/outlet-visits            → list (SR = own, Admin = all)
            // GET  /api/outlet-visits/targets    → visit targets vs achieved
            // POST /api/outlet-visits            → নতুন visit log (SR only)

            Route::get('targets', [OutletVisitController::class, 'targets']);
            Route::get('/', [OutletVisitController::class, 'index']);
            Route::post('/', [OutletVisitController::class, 'store']);
        });

        // ── Reports (Admin only) ──────────────────────────────────────────────

        Route::prefix('reports')->group(function () {
            // GET /api/reports/sales             → period-wise bar chart data
            // GET /api/reports/sales-reps        → SR-wise summary table
            // GET /api/reports/areas             → area-wise sales
            // GET /api/reports/companies         → company/brand-wise sales

            Route::get('sales',      [ReportController::class, 'sales']);
            Route::get('sales-reps', [ReportController::class, 'salesReps']);
            Route::get('areas',      [ReportController::class, 'areas']);
            Route::get('companies',  [ReportController::class, 'companies']);
        });

    });
});
