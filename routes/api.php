<?php
use App\Http\Controllers\Api\V1\{AuthController,CatalogController,CouponController,CustomerController,DashboardController,InventoryController,OrderController,PricingController,ProductController};
use Illuminate\Support\Facades\Route;
Route::prefix('v1')->group(function(){
 Route::post('/auth/login',[AuthController::class,'login'])->middleware('throttle:login');
 Route::middleware('auth:sanctum')->group(function(){
  Route::get('/auth/me',[AuthController::class,'me']);Route::post('/auth/logout',[AuthController::class,'logout']);
  Route::get('/dashboard',DashboardController::class)->middleware('permission:dashboard.view');

  Route::middleware('permission:products.view')->group(function(){Route::get('/products',[ProductController::class,'index']);Route::get('/products/{product}',[ProductController::class,'show']);Route::get('/categories',[CatalogController::class,'categories']);Route::get('/brands',[CatalogController::class,'brands']);});
  Route::middleware('permission:products.manage')->group(function(){Route::post('/products',[ProductController::class,'store']);Route::match(['put','patch'],'/products/{product}',[ProductController::class,'update']);Route::delete('/products/{product}',[ProductController::class,'destroy']);Route::post('/categories',[CatalogController::class,'storeCategory']);Route::post('/brands',[CatalogController::class,'storeBrand']);});

  Route::get('/currencies',[CatalogController::class,'currencies'])->middleware('permission:settings.manage');
  Route::get('/markets',[CatalogController::class,'markets'])->middleware('permission:settings.manage');
  Route::get('/warehouses',[CatalogController::class,'warehouses'])->middleware('permission:inventory.view');
  Route::post('/prices/upsert',[PricingController::class,'upsert'])->middleware('permission:pricing.manage');

  Route::get('/inventory',[InventoryController::class,'index'])->middleware('permission:inventory.view');
  Route::get('/inventory/movements',[InventoryController::class,'movements'])->middleware('permission:inventory.view');
  Route::post('/inventory/adjust',[InventoryController::class,'adjust'])->middleware('permission:inventory.manage');

  Route::get('/customers',[CustomerController::class,'index'])->middleware('permission:customers.view');
  Route::get('/customers/{customer}',[CustomerController::class,'show'])->middleware('permission:customers.view');
  Route::post('/customers',[CustomerController::class,'store'])->middleware('permission:customers.manage');
  Route::patch('/customers/{customer}',[CustomerController::class,'update'])->middleware('permission:customers.manage');

  Route::get('/coupons',[CouponController::class,'index'])->middleware('permission:coupons.manage');
  Route::post('/coupons',[CouponController::class,'store'])->middleware('permission:coupons.manage');
  Route::patch('/coupons/{coupon}',[CouponController::class,'update'])->middleware('permission:coupons.manage');

  Route::get('/orders',[OrderController::class,'index'])->middleware('permission:orders.view');
  Route::get('/orders/{order}',[OrderController::class,'show'])->middleware('permission:orders.view');
  Route::post('/orders',[OrderController::class,'store'])->middleware('permission:orders.manage');
  Route::patch('/orders/{order}/status',[OrderController::class,'status'])->middleware('permission:orders.manage');
 });
});
