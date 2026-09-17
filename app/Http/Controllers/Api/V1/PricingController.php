<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceUpsertRequest;
use App\Models\ProductMarketPrice;
use App\Support\ApiResponse;
class PricingController extends Controller { public function upsert(PriceUpsertRequest $r){$p=ProductMarketPrice::updateOrCreate(['variant_id'=>$r->variant_id,'market_id'=>$r->market_id],$r->validated());return ApiResponse::success($p->load(['variant.product','market.currency']),'Market price saved.','PRICE_SAVED',$p->wasRecentlyCreated?201:200);} }
