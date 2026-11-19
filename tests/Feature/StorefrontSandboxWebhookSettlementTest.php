<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Market;
use App\Models\Order;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

final class StorefrontSandboxWebhookSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_staging_sandbox_webhook_settles_once_and_duplicate_is_safe(): void
    {
        $currency=Currency::create([
            'code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP',
            'decimal_places'=>2,'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market=Market::create([
            'code'=>'EG','name'=>'Egypt','country_code'=>'EG',
            'currency_id'=>$currency->id,'timezone'=>'Africa/Cairo',
            'is_default'=>true,'is_active'=>true,
        ]);
        $warehouse=Warehouse::create([
            'code'=>'EG-01','market_id'=>$market->id,
            'name'=>'Egypt Warehouse','is_active'=>true,
        ]);
        $customer=Customer::create(['first_name'=>'Testing','email'=>'sandbox-webhook@example.test','phone'=>'+20123456789']);
        $order=Order::create([
            'order_number'=>'KH-2026-000001','customer_id'=>$customer->id,'market_id'=>$market->id,
            'warehouse_id'=>$warehouse->id,'currency_code'=>'EGP',
            'status'=>'pending','payment_status'=>'pending',
            'fulfillment_status'=>'unfulfilled','subtotal'=>'100.0000',
            'discount_total'=>'0.0000','shipping_total'=>'10.0000',
            'tax_total'=>'0.0000','grand_total'=>'110.0000',
            'placed_at'=>now(),
        ]);
        $payment=$order->payments()->create([
            'provider'=>'myfatoorah','method'=>'hosted',
            'transaction_reference'=>'98765','status'=>'pending',
            'amount'=>'110.0000','currency_code'=>'EGP',
            'payload'=>['sandbox'=>true],
        ]);
        $secret='testing-webhook-only-secret';
        \Illuminate\Support\Facades\DB::table('myfatoorah_checkout_reservations')->insert([
            'order_id'=>$order->id,'idempotency_key_hash'=>hash('sha256','sandbox-webhook-test'),
            'status'=>'initiated','amount'=>'110.0000','currency_code'=>'EGP',
            'invoice_id'=>'98765','payment_url'=>'https://example.test/pay',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->app->detectEnvironment(fn ()=>'staging');
        config([
            'commerce.storefront_sandbox_checkout_enabled'=>true,
            'commerce.myfatoorah_sandbox_enabled'=>true,
            'commerce.myfatoorah_sandbox_webhook_enabled'=>true,
            'commerce.myfatoorah_sandbox_token'=>'testing-token-not-real',
            'commerce.myfatoorah_webhook_secret'=>$secret,
        ]);
        Http::fake([
            'https://apitest.myfatoorah.com/v3/payments/12345'=>Http::response([
                'IsSuccess'=>true,
                'Data'=>[
                    'Invoice'=>['Id'=>'98765','ExternalIdentifier'=>'KH-2026-000001','Status'=>'PAID'],
                    'Transaction'=>['PaymentId'=>'12345','Status'=>'SUCCESS'],
                    'Amount'=>['DisplayCurrency'=>'EGP','ValueInDisplayCurrency'=>'110.0000'],
                ],
            ],200),
        ]);

        $fields=[
            'Invoice.Id=98765',
            'Invoice.Status=PAID',
            'Transaction.Status=SUCCESS',
            'Transaction.PaymentId=12345',
            'Invoice.ExternalIdentifier=KH-2026-000001',
        ];
        $signature=base64_encode(hash_hmac('sha256',implode(',',$fields),$secret,true));
        $event=[
            'Event'=>[
                'Code'=>1,
                'Name'=>'PAYMENT_STATUS_CHANGED',
                'Reference'=>'TEST-REF-123',
            ],
            'Data'=>[
                'Invoice'=>[
                    'Id'=>'98765','Status'=>'PAID',
                    'ExternalIdentifier'=>'KH-2026-000001',
                ],
                'Transaction'=>['Status'=>'SUCCESS','PaymentId'=>'12345'],
            ],
        ];

        try {
            $url='/api/v1/storefront/payments/myfatoorah/sandbox-webhook';
            $this->withHeader('MyFatoorah-Signature',$signature)
                ->postJson($url,$event)->assertOk()
                ->assertJsonPath('data.payment_status','paid')
                ->assertJsonPath('data.order_payment_status_changed',true);
            $this->assertDatabaseHas('orders',['id'=>$order->id,'payment_status'=>'paid']);
            $this->assertDatabaseHas('payments',['id'=>$payment->id,'status'=>'paid']);

            $this->withHeader('MyFatoorah-Signature',$signature)
                ->postJson($url,$event)->assertOk()
                ->assertJsonPath('data.duplicate',true)
                ->assertJsonPath('data.order_payment_status_changed',false);
            $this->assertDatabaseCount('myfatoorah_webhook_receipts',1);
            Http::assertSentCount(2);
        } finally {
            $this->app->detectEnvironment(fn ()=>'testing');
        }
    }
}
