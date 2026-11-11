<?php
/** Local-only configured order tax integrity checks; all writes rolled back. */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\ProductStatus;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\MarketTaxRule;
use App\Models\Order;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost','127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: local MySQL kham_crm_api only.\n"); exit(2);
}
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$market = Market::with('currency')->where('code','AE')->firstOrFail();
$warehouse = app(FulfillmentWarehouseResolver::class)->forMarket((int) $market->id);
$user = User::query()->firstOrFail();
$originalFlag = config('commerce.configured_shipping_orders_enabled');
DB::beginTransaction();
try {
    $source = ProductVariant::with('product')->where('is_active', true)->get()
        ->first(fn ($v) => $v->product && $v->product->status === ProductStatus::Active);
    if (!$source) throw new RuntimeException('STOP: Active catalog variant required.');
    $variant = $source->replicate();
    $variant->sku = 'ORDER-TAX-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12));
    $variant->save();
    ProductMarketPrice::create(['variant_id'=>$variant->id,'market_id'=>$market->id,
        'currency_id'=>$market->currency_id,'price'=>'100.0000','is_active'=>true]);
    $stock = InventoryStock::create(['warehouse_id'=>$warehouse->id,'variant_id'=>$variant->id,
        'on_hand'=>20,'reserved'=>0,'damaged'=>0]);
    ShippingRule::where('market_id',$market->id)->update(['is_active'=>false]);
    ShippingRule::updateOrCreate(['market_id'=>$market->id,'city_key'=>'dubai'],
        ['shipping_fee'=>20,'free_shipping_threshold'=>null,'is_active'=>true]);
    MarketTaxRule::where('market_id',$market->id)->delete();
    config()->set('commerce.configured_shipping_orders_enabled', true);
    $base = ['market_id'=>$market->id,'items'=>[['variant_id'=>$variant->id,'quantity'=>1]],
        'shipping_mode'=>'configured','tax_total'=>10,
        'shipping_address'=>['first_name'=>'Local Test','line1'=>'Rollback only',
            'city'=>'Dubai','country_code'=>'AE']];
    $service = app(OrderService::class);
    $reject = static function (array $payload, string $label) use ($service,$stock,$check): void {
        $before = Order::count();
        $reserved = (int) $stock->fresh()->reserved;
        try {
            $service->create($payload);
            throw new RuntimeException("FAIL: {$label} accepted");
        } catch (ValidationException $e) {
            $check(isset($e->errors()['tax_total'])
                && Order::count() === $before
                && (int) $stock->fresh()->reserved === $reserved, $label);
        }
    };
    $reject($base,'missing tax policy rejects order without reserving stock');
    $rule = MarketTaxRule::create(['market_id'=>$market->id,'rate_percent'=>10,
        'calculation_method'=>'exclusive','tax_shipping'=>false]);
    $reject($base,'unapproved tax policy rejects order without reserving stock');
    $rule->forceFill(['is_approved'=>true,'approved_at'=>now(),'approved_by'=>$user->id])->save();
    $reject(array_replace($base,['tax_total'=>9]),'incorrect client tax rejects order without reserving stock');
    $reject(array_replace($base,['tax_total'=>0]),'zero client tax bypass rejected');
    $reject(array_diff_key($base,['tax_total'=>true]),'missing submitted tax rejected');
    $order = $service->create($base);
    $check((float) $order->tax_total === 10.0 && (float) $order->shipping_total === 20.0
        && (float) $order->grand_total === 130.0
        && (int) $stock->fresh()->reserved === 1,
        'approved matching server tax creates consistent order and reserves once');
    foreach ([
        ['shipping_total' => -1],
        ['shipping_total' => 'INF'],
        ['tax_total' => -1],
        ['tax_total' => 'NaN'],
    ] as $invalid) {
        $bad = array_replace($base, ['shipping_mode' => 'manual',
            'shipping_total' => 0, 'tax_total' => 0], $invalid);
        $field = array_key_first($invalid);
        $before = Order::count();
        $reserved = (int) $stock->fresh()->reserved;
        try {
            $service->create($bad);
            throw new RuntimeException('FAIL: invalid manual ' . $field . ' accepted');
        } catch (ValidationException $e) {
            $check(isset($e->errors()[$field]) && Order::count() === $before
                && (int) $stock->fresh()->reserved === $reserved,
                'invalid manual ' . $field . ' rejected without stock mutation');
        }
    }
    $quoteUser = User::create(['name'=>'Quote Tester',
        'email'=>'quote-'.\Illuminate\Support\Str::random(14).'@example.invalid',
        'password'=>\Illuminate\Support\Str::random(48)]);
    $quoteUser->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate([
        'name'=>'orders.manage','guard_name'=>'web']));
    \Laravel\Sanctum\Sanctum::actingAs($quoteUser,['*']);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $req = \Illuminate\Http\Request::create('/api/v1/checkout/quote','POST',[
        'market_id'=>$market->id,'items'=>[['variant_id'=>$variant->id,'quantity'=>1]],
        'shipping_address'=>['country_code'=>'AE','city'=>'Dubai'],
    ],[],[],['HTTP_ACCEPT'=>'application/json']);
    $res = $kernel->handle($req);
    try {
        $body = json_decode($res->getContent(),true);
        $check($res->getStatusCode()===200 && is_array($body),'authenticated quote succeeds');
        $quote = $body['data']??[];
        $check((float)($quote['subtotal']??-1)===(float)$order->subtotal
            && (float)($quote['discount_total']??-1)===(float)$order->discount_total,
            'quote subtotal and discount match order');
        $check((float)($quote['shipping']['shipping_total']??-1)===(float)$order->shipping_total,
            'quote shipping matches order');
        $check((float)($quote['tax_total']??-1)===(float)$order->tax_total,
            'quote tax matches order');
        $check(abs((float)($quote['total_before_tax']??-1)+(float)($quote['tax_total']??-1)
            -(float)$order->grand_total)<0.00001
            && ($quote['is_final_payable_total']??null)===false,
            'quote components reconcile while final payable stays disabled');
    } finally { $kernel->terminate($req,$res); }
    echo "ALL CONFIGURED ORDER TAX CHECKS PASSED (rolled back)\n";
} finally {
    config()->set('commerce.configured_shipping_orders_enabled',$originalFlag);
    DB::rollBack();
}
