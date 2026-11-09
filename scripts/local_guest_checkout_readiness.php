<?php
/**
 * Module 5 guest checkout readiness audit (read-only).
 * Run: php scripts/local_guest_checkout_readiness.php
 * Never creates orders, customers, payments, reservations or gateway calls.
 */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
 if(!$ok)throw new RuntimeException('FAIL: '.$label);
 ++$checks;echo 'PASS: '.$label."\n";
};
$host=strtolower((string)config('database.connections.mysql.host',''));
$check(app()->environment('local') && config('database.default')==='mysql'
 && in_array($host,['localhost','127.0.0.1'],true)
 && DB::connection()->getDatabaseName()==='kham_crm_api','local database safety guard');
$routes=collect(Route::getRoutes()->getRoutes());
$hasRoute=static function(string $method,string $uri)use($routes):bool{
 return $routes->contains(static fn($r)=>$r->uri()===$uri && in_array($method,$r->methods(),true));
};
$check($hasRoute('POST','api/v1/storefront/checkout/quote'),'public guest quote route registered');
$check($hasRoute('POST','api/v1/storefront/checkout/test-order'),'local test-order route registered');
$check($hasRoute('POST','api/v1/storefront/payments/myfatoorah/local-webhook'),'local webhook harness route registered');
$check(!config('commerce.configured_shipping_orders_enabled'),'configured shipping order gate remains off');
$check(!config('commerce.myfatoorah_sandbox_enabled'),'MyFatoorah sandbox integration gate remains off');
$check(!config('commerce.myfatoorah_sandbox_hosted_checkout_enabled'),'hosted payment gate remains off');
$check(!config('commerce.myfatoorah_sandbox_webhook_enabled'),'webhook harness gate remains off');
$check(Schema::hasTable('storefront_checkout_attempts')
 && Schema::hasColumns('storefront_checkout_attempts',['key_hash','request_hash','order_id']),
 'checkout idempotency table ready');
$check(Schema::hasTable('orders')&&Schema::hasTable('order_items')
 &&Schema::hasTable('customers')&&Schema::hasTable('payments'),
 'guest order persistence tables ready');
$check(Schema::hasTable('inventory_stocks')||Schema::hasTable('inventory_stock'),
 'inventory stock table present');
echo "GUEST CHECKOUT READINESS: {$checks}/{$checks} PASS (read-only; no order or payment created)\n";
echo "STATUS: local mock/quote infrastructure only; real guest checkout remains gated.\n";
