<?php
/**
 * Local-only order currency and inventory atomicity regression.
 * All fixture modifications and order attempts are rolled back.
 * Run: php scripts/local_order_currency_inventory_regression.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\ProductStatus;
use App\Models\Currency;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\Order;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires local MySQL kham_crm_api.\n"); exit(2);
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$market = Market::with('currency')->where('is_active', true)->whereHas('currency', fn ($q) => $q->where('is_active', true))->first();
if (!$market) { fwrite(STDERR, "STOP: No active market/currency fixture.\n"); exit(2); }
$warehouse = app(FulfillmentWarehouseResolver::class)->forMarket((int) $market->id);
$price = ProductMarketPrice::query()
    ->where('market_id', $market->id)->where('currency_id', $market->currency_id)
    ->where('is_active', true)
    ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
    ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
    ->whereHas('variant', fn ($q) => $q->where('is_active', true)
        ->whereHas('product', fn ($p) => $p->where('status', ProductStatus::Active->value)))
    ->first();
if (!$price) { fwrite(STDERR, "STOP: No active priced variant fixture.\n"); exit(2); }
$variant = ProductVariant::findOrFail($price->variant_id);
$base = [
    'market_id' => $market->id,
    'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
    'shipping_mode' => 'manual',
    'shipping_total' => 0,
    'tax_total' => 0,
    'shipping_address' => [
        'first_name' => 'Regression',
        'line1' => 'Local test only',
        'city' => 'Cairo',
        'country_code' => $market->country_code,
    ],
];
$service = app(OrderService::class);
$alternate = Currency::query()->whereKeyNot($market->currency_id)->first();
if (!$alternate) {
    echo "SKIP: Currency mismatch needs a second existing currency.\n";
} else {
    DB::beginTransaction();
    try {
        $price->update(['currency_id' => $alternate->id]);
        $before = Order::count();
        try {
            $service->create($base);
            throw new RuntimeException('FAIL: mismatched currency accepted');
        } catch (ValidationException $e) {
            $assert(Order::count() === $before, 'currency mismatch rejected without order');
        }
    } finally { DB::rollBack(); }
}
$stock = InventoryStock::query()->where('warehouse_id', $warehouse->id)
    ->where('variant_id', $variant->id)->first();
if (!$stock) {
    echo "SKIP: Inventory rollback needs existing stock row for priced variant.\n";
} else {
    DB::beginTransaction();
    try {
        $stock->update(['on_hand' => 0, 'reserved' => 0, 'damaged' => 0]);
        $before = Order::count();
        try {
            $service->create($base);
            throw new RuntimeException('FAIL: order accepted with zero stock');
        } catch (ValidationException $e) {
            $assert(Order::count() === $before, 'insufficient inventory rolls back order');
            $stock->refresh();
            $assert((int) $stock->reserved === 0, 'failed reservation does not change reserved stock');
        }
    } finally { DB::rollBack(); }
}
echo "ORDER CURRENCY/INVENTORY REGRESSION FINISHED (rolled back; review SKIPs)\n";
