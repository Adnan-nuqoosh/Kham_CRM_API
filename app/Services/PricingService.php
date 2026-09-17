<?php
namespace App\Services;
use App\Models\ProductMarketPrice;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;
class PricingService {
 public function priceFor(ProductVariant $variant,int $marketId):ProductMarketPrice {
  $price=$variant->prices()->where('market_id',$marketId)->where('is_active',true)->where(fn($q)=>$q->whereNull('starts_at')->orWhere('starts_at','<=',now()))->where(fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>=',now()))->latest('id')->first();
  if(!$price) throw ValidationException::withMessages(['market_id'=>['No active price exists for this product variant in the selected market.']]);
  return $price;
 }
}
