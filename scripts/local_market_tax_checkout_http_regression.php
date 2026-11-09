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
$variant = ProductVariant::with('product')->where('is_active',true)->get()
    ->first(function ($v) use ($market, $warehouse) {
        if (!$v->product || $v->product->status !== ProductStatus::Active) return false;
        $stock = InventoryStock::where('warehouse_id',$warehouse->id)->where('variant_id',$v->id)->first();
        if (!$stock || $stock->available < 1) return false;
        try {
            $price = app(PricingService::class)->priceFor($v, (int)$market->id);
            return (int)$price->currency_id === (int)$market->currency_id;
        } catch (Throwable $e) { return false; }
    });
if (!$variant) { fwrite(STDERR, "STOP: AE active priced in-stock variant fixture required.\n"); exit(2); }
$payload = [
    'market_id'=>$market->id, 'items'=>[['variant_id'=>$variant->id,'quantity'=>1]],
    'shipping_address'=>['country_code'=>'AE','city'=>'Dubai'],
];
DB::beginTransaction();
try {
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
