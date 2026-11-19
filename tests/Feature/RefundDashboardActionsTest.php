<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Market;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

final class RefundDashboardActionsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $email, array $permissions): User
    {
        foreach (['orders.view', 'orders.manage', 'refunds.approve'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::create([
            'name' => 'QA Staff', 'email' => $email, 'password' => 'strong-test-password-123',
        ]);
        $user->givePermissionTo($permissions);
        return $user;
    }

    private function paidOrder(): Order
    {
        $currency = Currency::firstOrCreate(['code'=>'EGP'],[
            'name'=>'Egyptian Pound','symbol'=>'EGP','decimal_places'=>2,
            'rate_to_base'=>1,'is_base'=>true,'is_active'=>true,
        ]);
        $market = Market::firstOrCreate(['code'=>'EG'],[
            'name'=>'Egypt','country_code'=>'EG','currency_id'=>$currency->id,
            'timezone'=>'Africa/Cairo','is_default'=>true,'is_active'=>true,
        ]);
        $warehouse = Warehouse::firstOrCreate(['code'=>'EG-01'],[
            'market_id'=>$market->id,'name'=>'Main','is_active'=>true,
        ]);
        $order = Order::create([
            'order_number'=>'KH-REFUND-'.uniqid(),
            'market_id'=>$market->id,'warehouse_id'=>$warehouse->id,
            'currency_code'=>'EGP','status'=>'delivered','payment_status'=>'paid',
            'fulfillment_status'=>'fulfilled',
            'subtotal'=>'100.0000','discount_total'=>'0.0000',
            'shipping_total'=>'0.0000','tax_total'=>'0.0000',
            'grand_total'=>'100.0000','placed_at'=>now(),
        ]);
        $order->payments()->create([
            'provider'=>'myfatoorah','method'=>'hosted','status'=>'paid',
            'amount'=>'100.0000','currency_code'=>'EGP',
        ]);
        return $order;
    }

    public function test_authorized_approver_can_reject_with_reason_but_requester_cannot_reject(): void
    {
        $requester = $this->staff('requester@example.test', ['orders.view','orders.manage']);
        $approver = $this->staff('approver@example.test', ['refunds.approve']);
        $order = $this->paidOrder();

        Sanctum::actingAs($requester);
        $result = $this->postJson('/api/v1/orders/'.$order->id.'/refund-requests',[
            'reason'=>'Customer requested refund for damaged goods.',
        ])->assertCreated()->assertJsonPath('code','REFUND_REQUEST_CREATED');
        $id = $result->json('data.id');
        $this->postJson('/api/v1/refund-requests/'.$id.'/reject',[
            'reason'=>'Checked',
        ])->assertForbidden();

        Sanctum::actingAs($approver);
        $this->postJson('/api/v1/refund-requests/'.$id.'/reject',[
            'reason'=>'No',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/refund-requests/'.$id.'/reject',[
            'reason'=>'Unable to verify evidence.',
        ])->assertOk()->assertJsonPath('code','REFUND_REJECTED')
            ->assertJsonPath('data.status','rejected');
        $this->assertDatabaseHas('refund_audit_events',[
            'refund_request_id'=>$id,'event_type'=>'finance_rejected',
        ]);
        $this->assertDatabaseHas('orders',['id'=>$order->id,'payment_status'=>'paid']);
    }

    public function test_only_original_requester_can_cancel_pending_refund(): void
    {
        $requester = $this->staff('requester@example.test',['orders.view','orders.manage']);
        $other = $this->staff('other@example.test',['orders.manage']);
        $order = $this->paidOrder();
        Sanctum::actingAs($requester);
        $created = $this->postJson('/api/v1/orders/'.$order->id.'/refund-requests',[
            'reason'=>'Customer requested return and cancellation.',
        ])->assertCreated();
        $id = $created->json('data.id');

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/refund-requests/'.$id.'/cancel')
            ->assertForbidden();

        Sanctum::actingAs($requester);
        $this->postJson('/api/v1/refund-requests/'.$id.'/cancel')
            ->assertOk()->assertJsonPath('code','REFUND_CANCELLED')
            ->assertJsonPath('data.status','cancelled');
        $this->postJson('/api/v1/refund-requests/'.$id.'/cancel')
            ->assertUnprocessable();
        $this->assertDatabaseHas('refund_audit_events',[
            'refund_request_id'=>$id,'event_type'=>'requester_cancelled',
        ]);
        $this->assertDatabaseHas('orders',['id'=>$order->id,'payment_status'=>'paid']);
    }
}
