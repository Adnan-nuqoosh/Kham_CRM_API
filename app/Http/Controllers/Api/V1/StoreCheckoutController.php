<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Market,ProductVariant,Warehouse,Order,StoreCustomer,InventoryStock};
use App\Services\{CouponService,OrderService,PricingService,StoreTotalsService};
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class StoreCheckoutController extends Controller {
 private function validateCart(Request $r):array {return $r->validate(['market'=>'required|string|max:20','items'=>'required|array|min:1|max:50','items.*.variant_id'=>'required|integer|distinct|exists:product_variants,id','items.*.quantity'=>'required|integer|min:1|max:25','coupon_code'=>'nullable|string|max:80','checkout_key'=>'nullable|string|min:12|max:100']);}
 private function context(string $code):array {
  $market=Market::with('currency')->where('code',strtoupper($code))->where('is_active',true)->firstOrFail();
  $warehouse=Warehouse::where('market_id',$market->id)->where('is_active',true)->orderBy('id')->firstOrFail();
  return [$market,$warehouse];
 }
 private function priced(array $input,Market $market,Warehouse $warehouse,PricingService $pricing,CouponService $coupons,StoreTotalsService $totals):array {
  $lines=[];$subtotal=0;
  foreach($input['items'] as $row){
   $v=ProductVariant::with('product')->where('is_active',true)->findOrFail($row['variant_id']);
   if(($v->product->status instanceof \BackedEnum?$v->product->status->value:$v->product->status)!=='active')throw ValidationException::withMessages(['items'=>'Product unavailable.']);
   $p=$pricing->priceFor($v,$market->id);
   if($p->currency_id!==$market->currency_id)throw ValidationException::withMessages(['market'=>'Currency mismatch.']);
   $stock=InventoryStock::where('variant_id',$v->id)->where('warehouse_id',$warehouse->id)->first();
   if(!$stock||$stock->available<$row['quantity'])throw ValidationException::withMessages(['items'=>'Insufficient inventory for one or more items.']);
   $subtotal+=round((float)$p->price*$row['quantity'],4);
   $lines[]=['variant_id'=>$v->id,'sku'=>$v->sku,'quantity'=>$row['quantity'],'unit_price'=>$p->price];
  }
  $discount=$coupons->discount($input['coupon_code']??null,$subtotal);
  $costs=$totals->calculate($market,$subtotal,$discount);
  return array_merge(['items'=>$lines,'subtotal'=>round($subtotal,4),'discount_total'=>$discount,'currency_code'=>$market->currency->code,'market_id'=>$market->id,'warehouse_id'=>$warehouse->id,'checkout_enabled'=>(bool)config('storefront.checkout_enabled',false)&&$costs['rules_configured']&&$costs['cod_enabled']],$costs);
 }
 public function quote(Request $r,PricingService $pricing,CouponService $coupons,StoreTotalsService $totals){$v=$this->validateCart($r);[$market,$warehouse]=$this->context($v['market']);return ApiResponse::success($this->priced($v,$market,$warehouse,$pricing,$coupons,$totals),'Server-priced cart.','STORE_QUOTE');}
 public function checkout(Request $r,PricingService $pricing,CouponService $coupons,StoreTotalsService $totals,OrderService $orders){
  if(!config('storefront.checkout_enabled',false))return ApiResponse::error('Checkout is disabled.','CHECKOUT_DISABLED',503);
  $identity=$r->user();if(!$identity instanceof StoreCustomer)return ApiResponse::error('Customer login required.','STORE_LOGIN_REQUIRED',401);
  $v=$this->validateCart($r);
  $address=$r->validate(['payment_method'=>'required|in:cod','shipping_address'=>'required|array','shipping_address.first_name'=>'required|string|max:120','shipping_address.last_name'=>'nullable|string|max:120','shipping_address.email'=>'nullable|email|max:190','shipping_address.phone'=>'required|string|max:40','shipping_address.line1'=>'required|string|max:255','shipping_address.line2'=>'nullable|string|max:255','shipping_address.city'=>'required|string|max:120','shipping_address.state'=>'nullable|string|max:120','shipping_address.postal_code'=>'nullable|string|max:30','shipping_address.country_code'=>'required|string|size:2']);
  [$market,$warehouse]=$this->context($v['market']);
  if(strtoupper($address['shipping_address']['country_code'])!==$market->country_code)throw ValidationException::withMessages(['shipping_address.country_code'=>'Shipping country must match market.']);
  // A checkout key is required for safe client retries. Online payments are not accepted.
  if(empty($v['checkout_key']))throw ValidationException::withMessages(['checkout_key'=>'A unique checkout key is required.']);
  $fingerprint=hash('sha256',json_encode(['market'=>$market->code,'items'=>$v['items'],'coupon'=>$v['coupon_code']??null,'address'=>$address['shipping_address'],'method'=>'cod'],JSON_THROW_ON_ERROR));
  $order=DB::transaction(function()use($v,$identity,$pricing,$coupons,$totals,$orders,$market,$warehouse,$address,$fingerprint){
   // Lock customer row to serialize checkout requests for this customer.
   StoreCustomer::whereKey($identity->id)->lockForUpdate()->firstOrFail();
   $prior=Order::where('checkout_key',$v['checkout_key'])->first();
   if($prior){if((int)$prior->store_customer_id!==$identity->id||$prior->checkout_payload_hash!==$fingerprint)abort(409,'Checkout key already used for another request.');return $prior;}
   $quote=$this->priced($v,$market,$warehouse,$pricing,$coupons,$totals);
   if(!$quote['rules_configured']||!$quote['cod_enabled'])throw ValidationException::withMessages(['payment_method'=>'COD is unavailable for this market.']);
   $o=$orders->create(['market_id'=>$quote['market_id'],'warehouse_id'=>$quote['warehouse_id'],'items'=>$v['items'],'coupon_code'=>$v['coupon_code']??null,'shipping_total'=>$quote['shipping_total'],'tax_total'=>$quote['tax_inclusive']?0:$quote['tax_total'],'shipping_address'=>$address['shipping_address']]);
   $o->update(['store_customer_id'=>$identity->id,'checkout_key'=>$v['checkout_key'],'checkout_payload_hash'=>$fingerprint,'payment_method'=>'cod']);
   return $o;
  },3);
  return ApiResponse::success($order->load(['items','addresses']),'Order accepted with cash on delivery; payment pending.','STORE_ORDER_CREATED',$order->wasRecentlyCreated?201:200);
 }
}
