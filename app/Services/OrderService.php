<?php
namespace App\Services;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
class OrderService {
 public function __construct(private PricingService $pricing,private CouponService $coupons,private InventoryService $inventory){}
 public function create(array $payload):Order {
  return DB::transaction(function()use($payload){
   $lines=[];$subtotal=0;
   foreach($payload['items'] as $item){$variant=ProductVariant::with('product')->findOrFail($item['variant_id']);$price=$this->pricing->priceFor($variant,$payload['market_id']);$qty=(int)$item['quantity'];$line=(float)$price->price*$qty;$subtotal+=$line;$lines[]=compact('variant','qty','price','line');}
   $discount=$this->coupons->discount($payload['coupon_code']??null,$subtotal);$shipping=(float)($payload['shipping_total']??0);$tax=(float)($payload['tax_total']??0);
   $order=Order::create(['order_number'=>$this->nextNumber(),'customer_id'=>$payload['customer_id']??null,'market_id'=>$payload['market_id'],'warehouse_id'=>$payload['warehouse_id'],'currency_code'=>$lines[0]['price']->currency->code??$lines[0]['price']->currency_id,'status'=>OrderStatus::Pending,'payment_status'=>PaymentStatus::Pending,'fulfillment_status'=>'unfulfilled','subtotal'=>$subtotal,'discount_total'=>$discount,'shipping_total'=>$shipping,'tax_total'=>$tax,'grand_total'=>max(0,$subtotal-$discount+$shipping+$tax),'coupon_code'=>$payload['coupon_code']??null,'notes'=>$payload['notes']??null,'placed_at'=>now()]);
   foreach($lines as $row){$order->items()->create(['product_id'=>$row['variant']->product_id,'variant_id'=>$row['variant']->id,'sku'=>$row['variant']->sku,'name'=>$row['variant']->product->name.($row['variant']->title?' - '.$row['variant']->title:''),'quantity'=>$row['qty'],'unit_price'=>$row['price']->price,'discount_total'=>0,'tax_total'=>0,'line_total'=>$row['line']]);$this->inventory->reserve($row['variant']->id,$payload['warehouse_id'],$row['qty'],$order->id);}
   foreach(['shipping','billing'] as $type){if(!empty($payload[$type.'_address']))$order->addresses()->create(array_merge($payload[$type.'_address'],['type'=>$type]));}
   return $order->load(['items','addresses','market.currency','warehouse','customer']);
  });
 }
 private function nextNumber():string { $prefix='KH-'.now()->format('Y').'-';$last=(int)(Order::query()->where('order_number','like',$prefix.'%')->max(DB::raw("CAST(SUBSTRING_INDEX(order_number, '-', -1) AS UNSIGNED)"))??0);return $prefix.str_pad((string)($last+1),6,'0',STR_PAD_LEFT); }
}
