<?php
/** Local-only order lifecycle and inventory idempotency regression. */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
$host = strtolower((string) config('database.connections.mysql.host',''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host,['localhost','127.0.0.1'],true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR,"STOP: local MySQL kham_crm_api only.\n"); exit(2);
}
$check = static function(bool $ok,string $label):void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$market = Market::with('currency')->where('is_active',true)
    ->whereHas('currency',fn($q)=>$q->where('is_active',true))->firstOrFail();
$warehouse = app(FulfillmentWarehouseResolver::class)->forMarket((int)$market->id);
DB::beginTransaction();
try {
    $source = ProductVariant::with('product')->where('is_active',true)->get()
        ->first(fn($v)=>$v->product && $v->product->status === ProductStatus::Active);
    if (!$source) throw new RuntimeException('STOP: Existing active catalog variant required.');
    $variant = $source->replicate();
    $variant->sku = 'LIFECYCLE-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12));
    $variant->save();
    ProductMarketPrice::create(['variant_id'=>$variant->id,'market_id'=>$market->id,
        'currency_id'=>$market->currency_id,'price'=>'100.0000','is_active'=>true]);
    $stock = InventoryStock::create(['warehouse_id'=>$warehouse->id,'variant_id'=>$variant->id,
        'on_hand'=>30,'reserved'=>0,'damaged'=>0]);
    $payload = ['market_id'=>$market->id,
        'items'=>[['variant_id'=>$variant->id,'quantity'=>2]],
        'shipping_mode'=>'manual','shipping_total'=>0,'tax_total'=>0,
        'shipping_address'=>['first_name'=>'Local','line1'=>'Rollback only',
            'city'=>'Cairo','country_code'=>$market->country_code]];
    $orders = app(OrderService::class);
    $statuses = app(OrderStatusService::class);
    $cancelOrder = $orders->create($payload);
    $check((int)$stock->fresh()->reserved===2 && (int)$stock->fresh()->on_hand===30,
        'order creation reserves stock without deducting on-hand');
    $statuses->transition($cancelOrder,OrderStatus::Cancelled);
    $check((int)$stock->fresh()->reserved===0 && (int)$stock->fresh()->on_hand===30,
        'cancellation releases reservation without selling stock');
    $statuses->transition($cancelOrder,OrderStatus::Cancelled);
    $check((int)$stock->fresh()->reserved===0 && (int)$stock->fresh()->on_hand===30,
        'repeated cancellation does not release stock twice');
    try {
        $statuses->transition($cancelOrder,OrderStatus::Shipped);
        throw new RuntimeException('FAIL: cancelled order shipped');
    } catch (ValidationException $e) {
        $check((int)$stock->fresh()->reserved===0 && (int)$stock->fresh()->on_hand===30,
            'cancelled order cannot be shipped');
    }
    $shipOrder = $orders->create($payload);
    $statuses->transition($shipOrder,OrderStatus::Shipped);
    $check((int)$stock->fresh()->reserved===0 && (int)$stock->fresh()->on_hand===28,
        'shipment commits reserved stock exactly once');
    $statuses->transition($shipOrder,OrderStatus::Shipped);
    $check((int)$stock->fresh()->reserved===0 && (int)$stock->fresh()->on_hand===28,
        'repeated shipment cannot deduct stock twice');
    try {
        $statuses->transition($shipOrder,OrderStatus::Cancelled);
        throw new RuntimeException('FAIL: shipped order cancelled');
    } catch (ValidationException $e) {
        $check((int)$stock->fresh()->on_hand===28,
            'shipped order cannot cancel and release consumed stock');
    }
    $statuses->transition($shipOrder,OrderStatus::Returned);
    $check((int)$stock->fresh()->on_hand===28,
        'returned status does not automatically restock uninspected goods');
    foreach ([\App\Enums\PaymentStatus::Authorized, \App\Enums\PaymentStatus::Paid,
        \App\Enums\PaymentStatus::PartiallyRefunded, \App\Enums\PaymentStatus::Refunded] as $paymentState) {
        $paidOrder = $orders->create($payload);
        $paidOrder->forceFill(['payment_status'=>$paymentState])->save();
        $beforeReserved = (int)$stock->fresh()->reserved;
        try {
            $statuses->transition($paidOrder,OrderStatus::Cancelled);
            throw new RuntimeException('FAIL: protected payment state cancelled: '.$paymentState->value);
        } catch (ValidationException $e) {
            $check($paidOrder->fresh()->status === OrderStatus::Pending
                && (int)$stock->fresh()->reserved === $beforeReserved,
                'cancellation blocked for '.$paymentState->value.' without releasing inventory');
        }
    }
    foreach (['authorized','paid','partially_refunded','refunded'] as $ledgerStatus) {
        $staleOrder = $orders->create($payload);
        \App\Models\Payment::create([
            'order_id'=>$staleOrder->id,'provider'=>'local-regression',
            'method'=>'test','status'=>$ledgerStatus,'amount'=>$staleOrder->grand_total,
            'currency_code'=>$staleOrder->currency_code,
        ]);
        $beforeReserved = (int)$stock->fresh()->reserved;
        try {
            $statuses->transition($staleOrder,OrderStatus::Cancelled);
            throw new RuntimeException('FAIL: stale order cancelled with '.$ledgerStatus.' ledger');
        } catch (ValidationException $e) {
            $check($staleOrder->fresh()->status===OrderStatus::Pending
                && (int)$stock->fresh()->reserved===$beforeReserved,
                'payment ledger '.$ledgerStatus.' blocks stale-order cancellation');
        }
    }
    // HTTP authorization: unauthorized actors must not transition orders or mutate stock.
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $http = static function (string $method, string $path, array $body = []) use ($kernel): int {
        $request = \Illuminate\Http\Request::create('/api/v1/'.$path,$method,$body,[],[],
            ['HTTP_ACCEPT'=>'application/json']);
        $response = $kernel->handle($request);
        try { return $response->getStatusCode(); }
        finally { $kernel->terminate($request,$response); }
    };
    $securedOrder = $orders->create($payload);
    $reservedBefore = (int)$stock->fresh()->reserved;
    $path = 'orders/'.$securedOrder->id.'/status';
    $check($http('PATCH',$path,['status'=>'cancelled'])===401
        && $securedOrder->fresh()->status===OrderStatus::Pending
        && (int)$stock->fresh()->reserved===$reservedBefore,
        'anonymous status mutation rejected without inventory changes');
    $viewer = \App\Models\User::create(['name'=>'Order permission tester',
        'email'=>'order-viewer-'.\Illuminate\Support\Str::random(14).'@example.invalid',
        'password'=>\Illuminate\Support\Str::random(48)]);
    $viewer->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate([
        'name'=>'orders.view','guard_name'=>'web']));
    \Laravel\Sanctum\Sanctum::actingAs($viewer,['*']);
    $check($http('PATCH',$path,['status'=>'cancelled'])===403
        && $securedOrder->fresh()->status===OrderStatus::Pending
        && (int)$stock->fresh()->reserved===$reservedBefore,
        'read-only user cannot cancel order or release stock');
    $check($http('PATCH',$path,['status'=>'shipped'])===403
        && $securedOrder->fresh()->status===OrderStatus::Pending
        && (int)$stock->fresh()->reserved===$reservedBefore,
        'read-only user cannot ship order or deduct stock');
    $manager = \App\Models\User::create(['name'=>'Order status manager',
        'email'=>'order-manager-'.\Illuminate\Support\Str::random(14).'@example.invalid',
        'password'=>\Illuminate\Support\Str::random(48)]);
    $manager->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate([
        'name'=>'orders.manage','guard_name'=>'web']));
    \Laravel\Sanctum\Sanctum::actingAs($manager,['*']);
    $check($http('PATCH',$path,['status'=>'invalid'])===422
        && $securedOrder->fresh()->status===OrderStatus::Pending,
        'manager invalid status rejected');
    $check($http('PATCH',$path,['status'=>'cancelled'])===200
        && $securedOrder->fresh()->status===OrderStatus::Cancelled
        && (int)$stock->fresh()->reserved===$reservedBefore-2,
        'authorized manager cancellation releases reservation once');
    $check($http('PATCH',$path,['status'=>'cancelled'])===200
        && (int)$stock->fresh()->reserved===$reservedBefore-2,
        'replayed authorized cancellation cannot double-release inventory');
    echo "ALL ORDER LIFECYCLE CHECKS PASSED (rolled back)\n";
} finally { DB::rollBack(); }
