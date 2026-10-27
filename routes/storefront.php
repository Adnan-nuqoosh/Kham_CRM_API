<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\{StorefrontController,StoreAuthController,StoreCheckoutController,StoreContentController,StoreCmsAdminController,StoreRulesAdminController};

// Public storefront + customer-specific authenticated endpoints.
Route::prefix('v1/store')->middleware('throttle:api')->group(function(){
 Route::get('/settings',[StorefrontController::class,'settings']);
 Route::get('/home',[StorefrontController::class,'home']);
 Route::get('/categories',[StorefrontController::class,'categories']);
 Route::get('/brands',[StorefrontController::class,'brands']);
 Route::get('/products',[StorefrontController::class,'products']);
 Route::get('/products/{slug}',[StorefrontController::class,'product']);
 Route::get('/ingredients',[StoreContentController::class,'ingredients']);
 Route::get('/rituals',[StoreContentController::class,'rituals']);
 Route::get('/content',[StoreContentController::class,'content']);
 Route::post('/cart/quote',[StoreCheckoutController::class,'quote']);
 Route::post('/auth/register',[StoreAuthController::class,'register'])->middleware('throttle:login');
 Route::post('/auth/login',[StoreAuthController::class,'login'])->middleware('throttle:login');
 Route::middleware('auth:sanctum')->group(function(){
  Route::get('/auth/me',[StoreAuthController::class,'me']);
  Route::post('/auth/logout',[StoreAuthController::class,'logout']);
  Route::get('/addresses',[StoreAuthController::class,'addresses']);
  Route::post('/addresses',[StoreAuthController::class,'saveAddress']);
  Route::delete('/addresses/{id}',[StoreAuthController::class,'deleteAddress']);
  Route::get('/wishlist',[StoreAuthController::class,'wishlist']);
  Route::post('/wishlist/{product}',[StoreAuthController::class,'addWishlist']);
  Route::delete('/wishlist/{product}',[StoreAuthController::class,'removeWishlist']);
  Route::post('/checkout',[StoreCheckoutController::class,'checkout'])->middleware('throttle:login');
  Route::get('/orders',[StoreAuthController::class,'orders']);
  Route::get('/orders/{number}',[StoreAuthController::class,'order']);
 });
});

// CRM administrators manage website content and market checkout configuration here.
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::prefix('store-cms')->middleware('permission:settings.manage')->group(function () {
        Route::get('/{type}', [StoreCmsAdminController::class, 'index']);
        Route::post('/{type}', [StoreCmsAdminController::class, 'save']);
        Route::patch('/{type}/{id}', [StoreCmsAdminController::class, 'update']);
        Route::delete('/{type}/{id}', [StoreCmsAdminController::class, 'delete']);
    });
    Route::get('/store-rules', [StoreRulesAdminController::class, 'index'])->middleware('permission:settings.manage');
    Route::post('/store-rules', [StoreRulesAdminController::class, 'upsert'])->middleware('permission:settings.manage');
});
