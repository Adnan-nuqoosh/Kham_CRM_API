<?php
/**
 * Local mock checkout regression: requires dedicated XAMPP kham_crm_api.
 * Uses a single outer transaction and rolls back all fixtures.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array(strtolower((string) config('database.connections.mysql.host')), ['localhost','127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Local XAMPP kham_crm_api required.\n");
    exit(2);
}
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
};
$send = static function (array $body) use ($kernel): array {
    $request = Request::create('http://localhost/api/v1/storefront/checkout/test-order',
        'POST', [], [], [], ['HTTP_ACCEPT'=>'application/json',
        'CONTENT_TYPE'=>'application/json','REMOTE_ADDR'=>'127.0.0.1'],
        json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    try { return [$response->getStatusCode(), json_decode($response->getContent(), true)]; }
    finally { $kernel->terminate($request, $response); }
};
$tables = ['orders','order_items','customers','payments','inventory_movements','storefront_checkout_attempts'];
$counts = static function () use ($tables): array {
    $result=[]; foreach ($tables as $t) $result[$t]=DB::table($t)->count(); return $result;
};
$before=$counts(); $exit=0;
DB::beginTransaction();
try {
    $market=DB::table('markets')->where('code','AE')->first();
    $warehouse=DB::table('warehouses')->where('code','EG-01')->first();
    $stock=DB::table('inventory_stocks')->where('warehouse_id',$warehouse->id)
        ->whereRaw('(on_hand - reserved - damaged) >= 3')->first();
    if (!$market || !$warehouse || !$stock) throw new RuntimeException('Need AE market and 3 Egypt stock units.');
    $variant=DB::table('product_variants')->where('id',$stock->variant_id)->first();
    DB::table('products')->where('id',$variant->product_id)->update(['status'=>'active']);
    DB::table('product_variants')->where('id',$variant->id)->update(['is_active'=>1]);
    DB::table('product_market_prices')->updateOrInsert(
        ['variant_id'=>$variant->id,'market_id'=>$market->id],
        ['currency_id'=>$market->currency_id,'price'=>100,'compare_at_price'=>null,
         'starts_at'=>null,'ends_at'=>null,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
    DB::table('shipping_rules')->updateOrInsert(
        ['market_id'=>$market->id,'city_key'=>'dubai'],
        ['shipping_fee'=>15,'free_shipping_threshold'=>200,'is_active'=>1,
         'created_at'=>now(),'updated_at'=>now()]);

    $payload=[
        'idempotency_key'=>'local-test-'.Str::random(24),
        'market_id'=>$market->id,
        'items'=>[['variant_id'=>$variant->id,'quantity'=>1]],
        'payment_method'=>'mock_fatoora',
        'customer'=>['first_name'=>'Mock','email'=>'mock-'.Str::random(12).'@example.invalid',
            'phone'=>'+971501234567'],
        'shipping_address'=>['first_name'=>'Mock','line1'=>'Test street',
            'city'=>'Dubai','country_code'=>'AE'],
    ];
    config(['commerce.storefront_test_checkout_enabled'=>false]);
    [$status]=$send($payload);
    $assert($status===404,'Mock checkout disabled by default');

    config(['commerce.storefront_test_checkout_enabled'=>true]);
    [$status,$response]=$send($payload);
    $assert($status===201 && ($response['code']??'')==='MOCK_CHECKOUT_ORDER',
        'Guest mock order created locally without CRM token');
    $number=$response['data']['order_number']??'';
    $order=DB::table('orders')->where('order_number',$number)->first();
    $assert($order && $order->currency_code==='AED' && $order->payment_status==='pending'
        && (float)$order->shipping_total===15.0 && (float)$order->grand_total===115.0,
        'AED pricing and configured shipping server-calculated; payment pending');
    $assert(DB::table('payments')->where('order_id',$order->id)
        ->where('provider','mock_fatoora')->where('status','pending')->count()===1,
        'Only a mock pending payment recorded');
    $assert((int) DB::table('inventory_stocks')->where('id',$stock->id)->value('reserved')
        === (int)$stock->reserved+1,'Egypt stock reserved exactly once');

    [$status,$again]=$send($payload);
    $assert($status===200 && ($again['data']['order_number']??'')===$number
        && DB::table('orders')->where('order_number',$number)->count()===1,
        'Idempotent replay does not create another order');
    $assert((int) DB::table('inventory_stocks')->where('id',$stock->id)->value('reserved')
        === (int)$stock->reserved+1,'Replay does not reserve twice');

    $changed=$payload; $changed['items'][0]['quantity']=2;
    [$status]=$send($changed);
    $assert($status===422,'Same idempotency key with changed cart rejected');

    $tamper=$payload; $tamper['idempotency_key']='local-test-'.Str::random(24);
    $tamper['shipping_total']=0;
    [$status]=$send($tamper);
    $assert($status===422,'Client shipping total rejected');

    $fake=$payload; $fake['idempotency_key']='local-test-'.Str::random(24);
    $fake['payment_status']='paid';
    [$status]=$send($fake);
    $assert($status===422,'Client cannot mark payment paid');

    $wrong=$payload; $wrong['idempotency_key']='local-test-'.Str::random(24);
    $wrong['shipping_address']['country_code']='SA';
    [$status]=$send($wrong);
    $assert($status===422,'Cross-market shipping rejected');

    $assert(DB::table('orders')->count()===$before['orders']+1
        && DB::table('payments')->count()===$before['payments']+1,
        'Rejected requests did not create extra orders or payments');
    echo "ALL LOCAL MOCK CHECKOUT TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit=1; fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);
} finally {
    DB::rollBack();
    config(['commerce.storefront_test_checkout_enabled'=>false]);
}
if ($before!==$counts()) {
    fwrite(STDERR,"FAIL: Row counts differ after rollback.\n"); $exit=1;
} else echo "PASS: Original orders, customers, payments and stock movements preserved.\n";
exit($exit);
