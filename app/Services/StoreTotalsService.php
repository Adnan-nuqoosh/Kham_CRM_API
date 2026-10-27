<?php
namespace App\Services;
use App\Models\{Market,StoreMarketRule};
use Illuminate\Validation\ValidationException;
class StoreTotalsService {
 public function calculate(Market $market,float $subtotal,float $discount):array {
  $r=StoreMarketRule::where('market_id',$market->id)->where('is_active',true)->first();
  $base=max(0,round($subtotal-$discount,4));
  if(!$r)return ['shipping_total'=>null,'tax_total'=>null,'grand_total'=>null,'cod_enabled'=>false,'rules_configured'=>false];
  $shipping=$r->shipping_free_threshold!==null&&$base>=(float)$r->shipping_free_threshold?0.0:(float)$r->shipping_flat;
  $rate=(float)$r->tax_percent/100;
  $tax=$r->tax_inclusive?($rate>0?$base-$base/(1+$rate):0):$base*$rate;
  $tax=round($tax,4);$shipping=round($shipping,4);
  return ['shipping_total'=>$shipping,'tax_total'=>$tax,'grand_total'=>round($base+$shipping+($r->tax_inclusive?0:$tax),4),'cod_enabled'=>$r->cod_enabled,'rules_configured'=>true,'tax_inclusive'=>$r->tax_inclusive];
 }
}
