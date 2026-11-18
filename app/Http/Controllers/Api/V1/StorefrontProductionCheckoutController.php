<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\Order;
use App\Models\StorefrontAccount;
use App\Services\MarketTaxQuoteService;
use App\Services\MyFatoorahLiveClient;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Services\ShippingQuoteService;
use App\Services\StorefrontCheckoutReadiness;
use App\Models\ProductVariant;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authenticated, idempotent production checkout.
 * A provider timeout means UNKNOWN: do not automatically retry.
 * Payment status is finalized exclusively by verified provider enquiry.
 */
final class StorefrontProductionCheckoutController extends Controller
{
    public function __invoke(Request $request, OrderService $orders, MyFatoorahLiveClient $client)
    {
        $account=$request->user();
        if (!$account instanceof StorefrontAccount || !$account->tokenCan('storefront')) abort(403);
        if (!app()->environment('production')
            || !config('commerce.storefront_production_checkout_enabled',false)
            || !config('commerce.myfatoorah_live_enabled',false)
            || !config('commerce.configured_shipping_orders_enabled',false)) {
            return ApiResponse::error('Production checkout is unavailable.','CHECKOUT_DISABLED',503);
        }
        $redirect=(string) config('commerce.myfatoorah_live_redirect_url','');
        if (!filter_var($redirect,FILTER_VALIDATE_URL) || parse_url($redirect,PHP_URL_SCHEME)!=='https') {
            return ApiResponse::error('Provider return URL is not configured.','CHECKOUT_NOT_CONFIGURED',503);
        }
        $data=$request->validate([
            'idempotency_key'=>['required','string','min:16','max:128','regex:/^[A-Za-z0-9_-]+$/D'],
            'market_id'=>['required','integer','exists:markets,id'],
            'payment_method'=>['required','in:CARD,APPLE_PAY,GOOGLE_PAY,KNET'],
            'items'=>['required','array','min:1','max:25'],
            'items.*.variant_id'=>['required','integer','distinct:strict','exists:product_variants,id'],
            'items.*.quantity'=>['required','integer','min:1','max:100'],
            'shipping_address'=>['required','array'],
            'shipping_address.first_name'=>['required','string','max:100'],
            'shipping_address.last_name'=>['nullable','string','max:100'],
            'shipping_address.phone'=>['nullable','string','max:30'],
            'shipping_address.line1'=>['required','string','max:255'],
            'shipping_address.line2'=>['nullable','string','max:255'],
            'shipping_address.city'=>['required','string','max:120'],
            'shipping_address.state'=>['nullable','string','max:120'],
            'shipping_address.postal_code'=>['nullable','string','max:30'],
            'shipping_address.country_code'=>['required','string','size:2'],
            'coupon_code'=>['prohibited'],
            'unit_price'=>['prohibited'], 'shipping_total'=>['prohibited'],
            'tax_total'=>['prohibited'],'customer_id'=>['prohibited'],
            'warehouse_id'=>['prohibited'],'payment_status'=>['prohibited'],
        ]);
        $marketForReadiness=Market::with('currency')->findOrFail($data['market_id']);
        $readiness=app(StorefrontCheckoutReadiness::class)->forMarket($marketForReadiness);
        if (!$readiness['ready']) {
            return ApiResponse::error(
                'Checkout is not ready for the selected market.',
                'CHECKOUT_NOT_READY',503,['checkout'=>$readiness['blockers']]
            )->header('Cache-Control','no-store');
        }

        // All checkout dimensions are bound into the idempotency fingerprint.
        usort($data['items'],fn($a,$b)=>(int)$a['variant_id']<=>(int)$b['variant_id']);
        $keyHash=hash('sha256','production:'.$account->id.':'.$data['idempotency_key']);
        unset($data['idempotency_key']);
        $fingerprint=hash('sha256',json_encode(['account_id'=>$account->id,'payload'=>$data],JSON_THROW_ON_ERROR));

        $created=DB::transaction(function()use($account,$data,$keyHash,$fingerprint,$orders){
            DB::table('storefront_checkout_attempts')->insertOrIgnore([
                'key_hash'=>$keyHash,'request_hash'=>$fingerprint,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $attempt=DB::table('storefront_checkout_attempts')
                ->where('key_hash',$keyHash)->lockForUpdate()->first();
            if (!$attempt || !hash_equals($attempt->request_hash,$fingerprint)) {
                throw ValidationException::withMessages([
                    'idempotency_key'=>['Key is already used by a different checkout.'],
                ]);
            }
            if ($attempt->order_id) {
                $order=Order::whereKey($attempt->order_id)->where('customer_id',$account->customer_id)->firstOrFail();
                return [$order,false];
            }
            $market=Market::with('currency')->findOrFail($data['market_id']);
            if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
                throw ValidationException::withMessages(['market_id'=>['Market unavailable.']]);
            }
            $subtotal=0.0;
            foreach($data['items'] as $item) {
                $variant=ProductVariant::with('product')->findOrFail($item['variant_id']);
                if (!$variant->is_active || !$variant->product || $variant->product->status->value!=='active') {
                    throw ValidationException::withMessages(['items'=>['Product unavailable.']]);
                }
                $price=app(PricingService::class)->priceFor($variant,$market->id);
                if ((int)$price->currency_id!==(int)$market->currency_id) {
                    throw ValidationException::withMessages(['market_id'=>['Price currency mismatch.']]);
                }
                $subtotal+=(float)$price->price*(int)$item['quantity'];
            }
            $shipping=app(ShippingQuoteService::class)->quote(
                $market,$data['shipping_address']['country_code'],
                $data['shipping_address']['city'],$subtotal
            );
            $tax=app(MarketTaxQuoteService::class)->preview(
                $market,number_format($subtotal,4,'.',''),
                (string)$shipping['shipping_total']
            );
            if (($tax['tax_status']??null)!=='approved_not_enabled' || $tax['tax_total']===null) {
                throw ValidationException::withMessages(['tax'=>['Approved tax policy is required.']]);
            }
            $address=$data['shipping_address'];
            $address['email']=$account->email;
            $address['phone']=$address['phone']??$account->customer->phone;
            $order=$orders->create([
                'market_id'=>$market->id,
                'customer_id'=>$account->customer_id,
                'items'=>$data['items'],
                'shipping_mode'=>'configured',
                'tax_total'=>$tax['tax_total'],
                'shipping_address'=>$address,
            ]);
            DB::table('storefront_checkout_attempts')->where('id',$attempt->id)
                ->update(['order_id'=>$order->id,'updated_at'=>now()]);
            DB::table('myfatoorah_checkout_reservations')->insert([
                'order_id'=>$order->id,'idempotency_key_hash'=>$keyHash,
                'status'=>'reserved','amount'=>$order->grand_total,
                'currency_code'=>$order->currency_code,
                'payment_method'=>$data['payment_method'],
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            return [$order,true];
        },3);
        [$order,$isNew]=$created;
        if (!$isNew) {
            $reservation=DB::table('myfatoorah_checkout_reservations')->where('order_id',$order->id)->first();
            if ($order->payment_status === PaymentStatus::Paid) {
                return ApiResponse::success([
                    'order_number'=>$order->order_number,'payment_status'=>'paid',
                ],'Order payment already confirmed.','CHECKOUT_ALREADY_PAID')
                    ->header('Cache-Control','no-store');
            }
            if ($reservation && $reservation->status==='initiated' && $reservation->payment_url) {
                return ApiResponse::success([
                    'order_number'=>$order->order_number,
                    'payment_url'=>$reservation->payment_url,
                    'payment_status'=>$order->payment_status->value,
                ],'Existing payment session returned.','CHECKOUT_REUSED')
                    ->header('Cache-Control','no-store');
            }
            return ApiResponse::success([
                'order_number'=>$order->order_number,'payment_status'=>$order->payment_status->value,
                'initiation_status'=>$reservation->status??'not_started',
            ],'Checkout already exists; payment initiation must be reconciled.','CHECKOUT_RECONCILIATION_REQUIRED',202)
                ->header('Cache-Control','no-store');
        }

        // Reservation is committed atomically with the order above. Mark
        // provider request started before the external network call.
        $reservationId=DB::table('myfatoorah_checkout_reservations')
            ->where('order_id',$order->id)->value('id');
        DB::table('myfatoorah_checkout_reservations')->where('id',$reservationId)
            ->where('status','reserved')->update([
                'status'=>'requesting',
                'provider_requested_at'=>now(),'updated_at'=>now(),
            ]);
        $providerKey=hash('sha256','live:'.$order->order_number);
        try {
            $response=$client->createHostedPayment(
                $order->market->code,$order->order_number,
                (string)$order->grand_total,$redirect,
                $providerKey,$data['payment_method']
            );
        } catch(\Throwable $e) {
            DB::table('myfatoorah_checkout_reservations')->where('id',$reservationId)
                ->update(['status'=>'uncertain','updated_at'=>now()]);
            return ApiResponse::error(
                'Provider initiation is uncertain; contact support before retrying.',
                'PAYMENT_INITIATION_UNCERTAIN',503,
                ['order_number'=>[$order->order_number]]
            )->header('Cache-Control','no-store');
        }
        try {
            DB::transaction(function()use($order,$reservationId,$response){
                $locked=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($locked->payment_status!==PaymentStatus::Pending || $locked->payments()->exists()) {
                    throw ValidationException::withMessages(['payment'=>['Payment state changed; reconcile manually.']]);
                }
                $locked->payments()->create([
                    'provider'=>'myfatoorah','method'=>'hosted',
                    'transaction_reference'=>$response['invoice_id'],
                    'status'=>PaymentStatus::Pending->value,
                    'amount'=>$locked->grand_total,'currency_code'=>$locked->currency_code,
                    'payload'=>['sandbox'=>false],
                ]);
                DB::table('myfatoorah_checkout_reservations')->where('id',$reservationId)->update([
                    'status'=>'initiated','invoice_id'=>$response['invoice_id'],
                    'payment_url'=>$response['payment_url'],
                    'resolved_at'=>now(),'updated_at'=>now(),
                ]);
            },3);
        } catch(\Throwable $e) {
            DB::table('myfatoorah_checkout_reservations')->where('id',$reservationId)
                ->update(['status'=>'uncertain','updated_at'=>now()]);
            return ApiResponse::error(
                'Payment created by provider but local reconciliation is required.',
                'PAYMENT_RECONCILIATION_REQUIRED',503
            )->header('Cache-Control','no-store');
        }
        return ApiResponse::success([
            'order_number'=>$order->order_number,
            'payment_url'=>$response['payment_url'],
            'payment_status'=>'pending','currency_code'=>$order->currency_code,
            'amount'=>$order->grand_total,
        ],'Hosted payment initiated. Redirect is not proof of payment.',
        'STOREFRONT_CHECKOUT_CREATED',201)->header('Cache-Control','no-store');
    }
}
