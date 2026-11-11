<?php
/** Local-only refund approval HTTP regression; transaction rollback. */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Models\InventoryStock;
use App\Models\Market;
use App\Models\OrderRefundRequest;
use App\Models\RefundProviderEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\FulfillmentWarehouseResolver;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: local MySQL kham_crm_api only.\n"); exit(2);
}
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = static function (string $method, string $uri, array $data = []) use ($kernel): array {
    $request = Request::create('/api/v1/' . $uri, $method, $data, [], [],
        ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    try { return [$response->getStatusCode(), json_decode($response->getContent(), true)]; }
    finally { $kernel->terminate($request, $response); }
};
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$market = Market::with('currency')->where('is_active', true)
    ->whereHas('currency', fn($q) => $q->where('is_active', true))->firstOrFail();
$warehouse = app(FulfillmentWarehouseResolver::class)->forMarket((int) $market->id);
DB::beginTransaction();
try {
    $source = ProductVariant::with('product')->where('is_active', true)->get()
        ->first(fn($v) => $v->product && $v->product->status === ProductStatus::Active);
    if (!$source) throw new RuntimeException('STOP: active catalog variant required.');
    $variant = $source->replicate();
    $variant->sku = 'REFUND-' . Str::upper(Str::random(12));
    $variant->save();
    ProductMarketPrice::create(['variant_id' => $variant->id, 'market_id' => $market->id,
        'currency_id' => $market->currency_id, 'price' => '100.0000', 'is_active' => true]);
    $stock = InventoryStock::create(['warehouse_id' => $warehouse->id,
        'variant_id' => $variant->id, 'on_hand' => 10, 'reserved' => 0, 'damaged' => 0]);
    $order = app(OrderService::class)->create(['market_id' => $market->id,
        'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
        'shipping_mode' => 'manual', 'shipping_total' => 0, 'tax_total' => 0,
        'shipping_address' => ['first_name' => 'Local', 'line1' => 'Rollback only',
            'city' => 'Cairo', 'country_code' => $market->country_code]]);
    $payment = $order->payments()->create([
        'provider' => 'myfatoorah', 'method' => 'hosted',
        'transaction_reference' => (string) random_int(100000000, 999999999),
        'status' => 'paid', 'amount' => $order->grand_total,
        'currency_code' => $order->currency_code, 'payload' => ['synthetic' => true],
    ]);
    $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
    $manage = Permission::firstOrCreate(['name' => 'orders.manage', 'guard_name' => 'web']);
    $approve = Permission::firstOrCreate(['name' => 'refunds.approve', 'guard_name' => 'web']);
    $requester = User::create(['name' => 'Refund requester',
        'email' => 'refund-requester-' . Str::random(12) . '@example.invalid',
        'password' => Str::random(48)]);
    $requester->givePermissionTo($manage);
    $finance = User::create(['name' => 'Refund approver',
        'email' => 'refund-finance-' . Str::random(12) . '@example.invalid',
        'password' => Str::random(48)]);
    $finance->givePermissionTo($approve);
    $uri = "orders/{$order->id}/refund-requests";
    [$status] = $call('POST', $uri, ['reason' => 'Customer requested refund']);
    $check($status === 401, 'anonymous refund request rejected');
    Sanctum::actingAs($requester, ['*']);
    [$status, $body] = $call('POST', $uri, ['reason' => 'Customer requested refund']);
    $refund = OrderRefundRequest::where('order_id', $order->id)->first();
    $check($status === 201 && $refund && $refund->status === 'pending_approval',
        'authorized requester creates pending refund');
    [$status] = $call('POST', $uri, ['reason' => 'Second duplicate refund']);
    $check($status === 422 && OrderRefundRequest::where('order_id', $order->id)->count() === 1,
        'duplicate open refund rejected');
    [$status] = $call('POST', "refund-requests/{$refund->id}/approve", ['confirm' => true]);
    $check($status === 403, 'requester without finance permission cannot approve');
    $requester->givePermissionTo($approve);
    [$status] = $call('POST', "refund-requests/{$refund->id}/approve", ['confirm' => true]);
    $check($status === 422, 'requester cannot self-approve even with finance permission');
    Sanctum::actingAs($finance, ['*']);
    [$status] = $call('POST', "refund-requests/{$refund->id}/approve", []);
    $check($status === 422, 'finance approval requires explicit confirmation');
    [$status] = $call('POST', "refund-requests/{$refund->id}/approve", ['confirm' => true]);
    $check($status === 200 && $refund->fresh()->status === 'approved_awaiting_provider'
        && (int) $refund->fresh()->approved_by === (int) $finance->id,
        'separate finance approver approves request only');
    [$status] = $call('POST', "refund-requests/{$refund->id}/approve", ['confirm' => true]);
    $check($status === 422, 'duplicate approval rejected');
    $check($order->fresh()->payment_status === PaymentStatus::Paid
        && $payment->fresh()->status === 'paid',
        'approval does not change order or payment paid status');
    $check((int) $stock->fresh()->reserved === 1
        && (int) $stock->fresh()->on_hand === 10,
        'approval does not release or sell reserved stock');
    [$status] = $call('GET', "refund-requests/{$refund->id}/audit");
    $check($status === 200, 'finance approver can read refund audit');
    $events = \App\Models\RefundAuditEvent::where('refund_request_id', $refund->id)->orderBy('id')->get();
    $check($events->count() === 2 && $events[0]->event_type === 'requested'
        && $events[1]->event_type === 'finance_approved',
        'request and approval recorded as ordered audit events');
    $check((int) $events[0]->actor_id === (int) $requester->id
        && (int) $events[1]->actor_id === (int) $finance->id,
        'audit records distinct requester and approver');
    Sanctum::actingAs($requester, ['*']);
    $requester->revokePermissionTo($approve);
    [$status] = $call('GET', "refund-requests/{$refund->id}/audit");
    $check($status === 403, 'non-finance requester cannot read refund audit');
    $evidence = RefundProviderEvidence::recordUnverified(
        $refund->fresh(), 'myfatoorah', 'REF-' . Str::random(20),
        (string) $refund->amount, (string) $refund->currency_code
    );
    $check($evidence->verification_status === 'unverified',
        'provider reference evidence remains unverified');
    $check($order->fresh()->payment_status === PaymentStatus::Paid
        && $payment->fresh()->status === 'paid',
        'unverified provider reference does not settle payment');
    foreach ([
        [(string) $refund->amount, 'INVALID'],
        ['999999.0000', (string) $refund->currency_code],
    ] as [$amount, $currency]) {
        try {
            RefundProviderEvidence::recordUnverified($refund->fresh(), 'myfatoorah',
                'INVALID-' . Str::random(12), $amount, $currency);
            throw new RuntimeException('FAIL: mismatched provider evidence accepted');
        } catch (ValidationException $exception) {
            $check(true, 'mismatched provider evidence rejected');
        }
    }
    try {
        RefundProviderEvidence::recordUnverified($refund->fresh(), 'myfatoorah',
            'SECOND-' . Str::random(12), (string) $refund->amount, (string) $refund->currency_code);
        throw new RuntimeException('FAIL: second evidence for same request accepted');
    } catch (QueryException $exception) {
        $check(true, 'database rejects second evidence for same refund request');
    }
    $check(RefundProviderEvidence::where('refund_request_id', $refund->id)->count() === 1,
        'duplicate attempt leaves exactly one provider evidence row');
    echo "ALL REFUND APPROVAL HTTP CHECKS PASSED (rolled back)\n";
} finally {
    DB::rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
