<?php
/** Local-only rollback HTTP quote tax safety regression. */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Enums\ProductStatus;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\MarketTaxRule;
use App\Models\ProductVariant;
use App\Models\ProductMarketPrice;
use App\Models\ShippingRule;
use App\Models\User;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
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
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = static function (string $uri, array $payload) use ($kernel): array {
    $req = Request::create('/api/v1/'.$uri, 'POST', $payload, [], [], ['HTTP_ACCEPT'=>'application/json']);
    $res = $kernel->handle($req);
    try { return [$res->getStatusCode(), json_decode($res->getContent(), true)]; }
    finally { $kernel->terminate($req, $res); }
};
$market = Market::with('currency')->where('code','AE')->firstOrFail();
$warehouse = app(FulfillmentWarehouseResolver::class)->forMarket((int)$market->id);
// Create a dedicated local fixture inside a transaction, rather than requiring
// existing AE pricing and stock. Existing catalog and inventory stay unchanged.
DB::beginTransaction();
try {
    $variant = ProductVariant::with('product')->where('is_active', true)->get()
        ->first(fn ($v) => $v->product && $v->product->status === ProductStatus::Active);
    if (!$variant) {
        throw new RuntimeException('STOP: An existing active catalog variant is required; no fixture created.');
    }
    $variant = $variant->replicate();
    $variant->sku = 'TAX-HTTP-' . \Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(12));
    $variant->is_active = true;
    $variant->save();
    ProductMarketPrice::create([
        'variant_id' => $variant->id, 'market_id' => $market->id,
        'currency_id' => $market->currency_id, 'price' => '100.0000',
        'is_active' => true,
    ]);
    InventoryStock::create([
        'warehouse_id' => $warehouse->id, 'variant_id' => $variant->id,
        'on_hand' => 20, 'reserved' => 0, 'damaged' => 0,
    ]);
$payload = [
    'market_id'=>$market->id, 'items'=>[['variant_id'=>$variant->id,'quantity'=>1]],
    'shipping_address'=>['country_code'=>'AE','city'=>'Dubai'],
];
    ShippingRule::where('market_id',$market->id)->update(['is_active'=>false]);
    ShippingRule::updateOrCreate(['market_id'=>$market->id,'city_key'=>'dubai'],
        ['shipping_fee'=>20,'free_shipping_threshold'=>null,'is_active'=>true]);
    MarketTaxRule::where('market_id',$market->id)->delete();
    [$status,$body] = $call('storefront/checkout/quote',$payload);
    $check($status===200 && ($body['data']['tax_total']??'missing')===null
        && ($body['data']['is_final_payable_total']??null)===false,
        'public quote unconfigured tax remains non-payable');
    $rule = MarketTaxRule::create(['market_id'=>$market->id,'rate_percent'=>10,
        'calculation_method'=>'exclusive','tax_shipping'=>false]);
    [$status,$body] = $call('storefront/checkout/quote',$payload);
    $check($status===200 && ($body['data']['tax_total']??'missing')===null
        && ($body['data']['tax_status']??null)==='pending_approval',
        'public quote unapproved tax withheld');
    $user = User::query()->get()->first(fn ($u)=>$u->can('orders.manage'));
    if (!$user) throw new RuntimeException('STOP: Existing orders.manage user required for CRM HTTP checks.');
    Sanctum::actingAs($user,['*']);
    [$status,$body] = $call('checkout/quote',$payload);
    $check($status===200 && ($body['data']['tax_total']??'missing')===null
        && ($body['data']['is_final_payable_total']??null)===false,
        'CRM quote unapproved tax withheld');
    $rule->forceFill(['is_approved'=>true,'approved_at'=>now(),'approved_by'=>$user->id])->save();
    [$status,$body] = $call('checkout/quote',$payload);
    $check($status===200 && ($body['data']['tax_total']??null)!==null
        && ($body['data']['is_final_payable_total']??null)===false
        && !array_key_exists('final_payable_total',$body['data']??[]),
        'CRM approved tax preview never enables final payable');
    [$status,$body] = $call('storefront/checkout/quote',$payload);
    $check($status===200 && ($body['data']['tax_total']??null)!==null
        && ($body['data']['is_final_payable_total']??null)===false
        && !array_key_exists('final_payable_total',$body['data']??[]),
        'public approved tax preview never enables final payable');
    echo "ALL TAX CHECKOUT HTTP CHECKS PASSED (rolled back)\n";
} finally { DB::rollBack(); }
