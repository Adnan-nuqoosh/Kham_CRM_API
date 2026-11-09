<?php

use App\Http\Controllers\Api\V1\{
    AuthController,
    CatalogController,
    CouponController,
    CustomerController,
    DashboardController,
    InventoryController,
    MediaController,
    OrderController,
    PricingController,
    ProductController,
    ShippingRuleController,
    CheckoutQuoteController,
    FinanceExpenseController,
    FinanceReportController
};
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PublicCheckoutQuoteController;
use App\Http\Controllers\Api\V1\MarketTaxRuleController;
use App\Http\Controllers\Api\V1\LocalGuestCheckoutController;
use App\Http\Controllers\Api\V1\LocalMyFatoorahWebhookController;
use App\Http\Controllers\Api\V1\LocalMyFatoorahHostedCheckoutController;
use App\Http\Controllers\Api\V1\LocalMyFatoorahReservationStatusController;
use App\Http\Controllers\Api\V1\LocalMyFatoorahProviderEnquiryController;

Route::prefix('v1')->group(function () {
    // Disabled by default; controller also rejects non-local environments.
    Route::post('/storefront/payments/myfatoorah/local-webhook', LocalMyFatoorahWebhookController::class)
        ->middleware('throttle:storefront-test-order');
    Route::post('/storefront/checkout/test-order', LocalGuestCheckoutController::class)
        ->middleware('throttle:storefront-test-order');
    Route::post('/storefront/checkout/quote', PublicCheckoutQuoteController::class)
        ->middleware('throttle:storefront-quote');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/dashboard', DashboardController::class)
            ->middleware('permission:dashboard.view');

        Route::middleware('permission:products.view')->group(function () {
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show']);

            Route::get('/categories', [CatalogController::class, 'categories']);
            Route::get('/categories/{category}', [CatalogController::class, 'showCategory']);

            Route::get('/brands', [CatalogController::class, 'brands']);
            Route::get('/brands/{brand}', [CatalogController::class, 'showBrand']);
        });

        Route::middleware('permission:products.manage')->group(function () {
            Route::post('/products', [ProductController::class, 'store']);

            // Multipart product updates (including new images) must use POST because
            // PHP does not reliably parse multipart bodies for PUT/PATCH.
            Route::post('/products/{product}', [ProductController::class, 'update']);
            Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);

            Route::post('/categories', [CatalogController::class, 'storeCategory']);
            Route::patch('/categories/{category}', [CatalogController::class, 'updateCategory']);
            Route::delete('/categories/{category}', [CatalogController::class, 'destroyCategory']);

            Route::post('/brands', [CatalogController::class, 'storeBrand']);
            Route::patch('/brands/{brand}', [CatalogController::class, 'updateBrand']);
            Route::delete('/brands/{brand}', [CatalogController::class, 'destroyBrand']);

            Route::post('/categories/{category}/image', [MediaController::class, 'uploadCategoryImage']);
            Route::delete('/categories/{category}/image', [MediaController::class, 'deleteCategoryImage']);
            Route::post('/brands/{brand}/image', [MediaController::class, 'uploadBrandLogo']);
            Route::delete('/brands/{brand}/image', [MediaController::class, 'deleteBrandLogo']);
            Route::post('/products/{product}/images', [MediaController::class, 'uploadProductImages']);
            Route::delete('/products/{product}/images/{media}', [MediaController::class, 'deleteProductImage']);
        });

        Route::get('/currencies', [CatalogController::class, 'currencies'])
            ->middleware('permission:settings.manage');
        Route::get('/markets', [CatalogController::class, 'markets'])
            ->middleware('permission:settings.manage');
        Route::get('/warehouses', [CatalogController::class, 'warehouses'])
            ->middleware('permission:inventory.view');

        Route::post('/prices/upsert', [PricingController::class, 'upsert'])
            ->middleware('permission:pricing.manage');

        Route::get('/inventory', [InventoryController::class, 'index'])
            ->middleware('permission:inventory.view');
        Route::get('/inventory/movements', [InventoryController::class, 'movements'])
            ->middleware('permission:inventory.view');
        Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])
            ->middleware('permission:inventory.manage');

        Route::get('/customers', [CustomerController::class, 'index'])
            ->middleware('permission:customers.view');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])
            ->middleware('permission:customers.view');
        Route::post('/customers', [CustomerController::class, 'store'])
            ->middleware('permission:customers.manage');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
            ->middleware('permission:customers.manage');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
            ->middleware('permission:customers.manage');

        Route::get('/coupons', [CouponController::class, 'index'])
            ->middleware('permission:coupons.manage');
        Route::get('/coupons/{coupon}', [CouponController::class, 'show'])
            ->middleware('permission:coupons.manage');
        Route::post('/coupons', [CouponController::class, 'store'])
            ->middleware('permission:coupons.manage');
        Route::patch('/coupons/{coupon}', [CouponController::class, 'update'])
            ->middleware('permission:coupons.manage');
        Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy'])
            ->middleware('permission:coupons.manage');

        // Authenticated CRM quote: tax is deliberately not configured yet.
        Route::post('/checkout/quote', CheckoutQuoteController::class)
            ->middleware('permission:orders.manage');

        Route::get('/market-tax-rules', [MarketTaxRuleController::class, 'index'])
            ->middleware('permission:settings.manage');
        Route::put('/markets/{market}/tax-rule', [MarketTaxRuleController::class, 'upsert'])
            ->middleware('permission:settings.manage');
        // Approval requires a separate permission, never granted by the API.
        Route::post('/markets/{market}/tax-rule/approve', [MarketTaxRuleController::class, 'approve'])
            ->middleware('permission:tax.approve');

        Route::get('/shipping-rules', [ShippingRuleController::class, 'index'])
            ->middleware('permission:settings.manage');
        Route::post('/shipping-rules', [ShippingRuleController::class, 'store'])
            ->middleware('permission:settings.manage');
        Route::patch('/shipping-rules/{shippingRule}', [ShippingRuleController::class, 'update'])
            ->middleware('permission:settings.manage');

        Route::get('/finance/report/export.csv', [FinanceReportController::class, 'exportCsv'])
            ->middleware('permission:finance.view');
        Route::get('/finance/report', [FinanceReportController::class, 'summary'])
            ->middleware('permission:finance.view');
        Route::get('/finance/expenses', [FinanceExpenseController::class, 'index'])
            ->middleware('permission:finance.view');
        Route::post('/finance/expenses', [FinanceExpenseController::class, 'store'])
            ->middleware('permission:finance.manage');
        Route::patch('/finance/expenses/{expense}', [FinanceExpenseController::class, 'update'])
            ->middleware('permission:finance.manage');
        Route::delete('/finance/expenses/{expense}', [FinanceExpenseController::class, 'destroy'])
            ->middleware('permission:finance.manage');
        Route::patch('/finance/order-items/{item}/cost', [FinanceExpenseController::class, 'setItemCost'])
            ->middleware('permission:finance.manage');

        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware('permission:orders.view');
        Route::get('/orders/{order}', [OrderController::class, 'show'])
            ->middleware('permission:orders.view');
        Route::post('/orders/{order}/payments/myfatoorah/sandbox-hosted/provider-enquiry', LocalMyFatoorahProviderEnquiryController::class)
            ->middleware(['permission:orders.manage', 'throttle:storefront-test-order']);
        Route::get('/orders/{order}/payments/myfatoorah/sandbox-hosted/status', LocalMyFatoorahReservationStatusController::class)
            ->middleware(['permission:orders.view', 'throttle:storefront-test-order']);
        Route::post('/orders/{order}/payments/myfatoorah/sandbox-hosted', LocalMyFatoorahHostedCheckoutController::class)
            ->middleware(['permission:orders.manage', 'throttle:storefront-test-order']);
        Route::post('/orders', [OrderController::class, 'store'])
            ->middleware('permission:orders.manage');
        Route::patch('/orders/{order}/status', [OrderController::class, 'status'])
            ->middleware('permission:orders.manage');
    });
});
