<?php
namespace App\Services;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Market;
use Illuminate\Validation\ValidationException;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
class OrderService {
 public function __construct(private PricingService $pricing,private CouponService $coupons,private InventoryService $inventory,private FulfillmentWarehouseResolver $warehouses,private ShippingQuoteService $shippingQuotes){}
 public function create(array $payload, bool $allowLocalTestConfiguredShipping = false):Order {
  return DB::transaction(function()use($payload,$allowLocalTestConfiguredShipping){
   $warehouse=$this->warehouses->forMarket((int)$payload['market_id']);
   $warehouseId=$warehouse->id;
   $lines=[];$subtotal=0;
   foreach($payload['items'] as $item){$variant=ProductVariant::with('product')->findOrFail($item['variant_id']);$price=$this->pricing->priceFor($variant,$payload['market_id']);$qty=(int)$item['quantity'];$line=(float)$price->price*$qty;$subtotal+=$line;$lines[]=compact('variant','qty','price','line');}
   $discount=$this->coupons->discount($payload['coupon_code']??null,$subtotal);
   $mode=$payload['shipping_mode']??'manual';
   $shippingRuleId=null;
   $shipping=(float)($payload['shipping_total']??0);
   $tax=(float)($payload['tax_total']??0);
   if($mode==='configured'){
    if(!config('commerce.configured_shipping_orders_enabled')
       && !($allowLocalTestConfiguredShipping && app()->environment('local')
           && config('commerce.storefront_test_checkout_enabled', false))){
     throw ValidationException::withMessages(['shipping_mode'=>['Configured shipping orders are not enabled.']]);
    }
    if(array_key_exists('shipping_total',$payload)){
     throw ValidationException::withMessages(['shipping_total'=>['Shipping total is calculated by the server in configured mode.']]);
    }
    if(!array_key_exists('tax_total',$payload)){
     throw ValidationException::withMessages(['tax_total'=>['An explicitly approved tax total is required for internal CRM orders.']]);
    }
    $market=Market::with('currency')->findOrFail($payload['market_id']);
    $destination=$payload['shipping_address'];
    $quote=$this->shippingQuotes->quote($market,$destination['country_code'],$destination['city'],max(0,round($subtotal-$discount,4)));
    $shipping=(float)$quote['shipping_total'];
    $shippingRuleId=$quote['rule_id'];
   }
   $order=Order::create(['order_number'=>$this->nextNumber(),'customer_id'=>$payload['customer_id']??null,'market_id'=>$payload['market_id'],'warehouse_id'=>$warehouseId,'currency_code'=>$lines[0]['price']->currency->code??$lines[0]['price']->currency_id,'status'=>OrderStatus::Pending,'payment_status'=>PaymentStatus::Pending,'fulfillment_status'=>'unfulfilled','subtotal'=>$subtotal,'discount_total'=>$discount,'shipping_total'=>$shipping,'shipping_mode'=>$mode,'shipping_rule_id'=>$shippingRuleId,'tax_total'=>$tax,'grand_total'=>max(0,$subtotal-$discount+$shipping+$tax),'coupon_code'=>$payload['coupon_code']??null,'notes'=>$payload['notes']??null,'placed_at'=>now()]);
   foreach($lines as $row){$order->items()->create(['product_id'=>$row['variant']->product_id,'variant_id'=>$row['variant']->id,'sku'=>$row['variant']->sku,'name'=>$row['variant']->product->name.($row['variant']->title?' - '.$row['variant']->title:''),'quantity'=>$row['qty'],'unit_price'=>$row['price']->price,'discount_total'=>0,'tax_total'=>0,'line_total'=>$row['line'],'unit_cost_snapshot'=>$row['variant']->cost_price !== null && $row['variant']->cost_currency_code !== null ? $row['variant']->cost_price : null,'unit_cost_currency_code'=>$row['variant']->cost_price !== null ? $row['variant']->cost_currency_code : null]);$this->inventory->reserve($row['variant']->id,$warehouseId,$row['qty'],$order->id);}
   foreach(['shipping','billing'] as $type){if(!empty($payload[$type.'_address']))$order->addresses()->create(array_merge($payload[$type.'_address'],['type'=>$type]));}
   return $order->load(['items','addresses','market.currency','warehouse','customer']);
  }, 5);
 }
 private function nextNumber():string {
  // Called inside create()'s transaction. Lock one year row to serialize
  // order-number allocation across simultaneous requests.
  $year=(int)now()->format('Y');
  DB::table('order_number_sequences')->insertOrIgnore([
   'year'=>$year,'last_number'=>0,'created_at'=>now(),'updated_at'=>now(),
  ]);
  $sequence=DB::table('order_number_sequences')->where('year',$year)->lockForUpdate()->first();
  $next=(int)$sequence->last_number+1;
  DB::table('order_number_sequences')->where('year',$year)->update([
   'last_number'=>$next,'updated_at'=>now(),
  ]);
  return 'KH-'.$year.'-'.str_pad((string)$next,6,'0',STR_PAD_LEFT);
 }
}
