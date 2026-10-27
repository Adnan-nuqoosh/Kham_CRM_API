<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\{Market,StoreMarketRule};
use App\Support\ApiResponse;
use Illuminate\Http\Request;
class StoreRulesAdminController extends Controller {
 public function index(){return ApiResponse::success(StoreMarketRule::with('market')->get());}
 public function upsert(Request $request){
  $v=$request->validate(['market_id'=>'required|integer|exists:markets,id','shipping_flat'=>'required|numeric|min:0|max:999999','shipping_free_threshold'=>'nullable|numeric|min:0|max:999999','tax_percent'=>'required|numeric|min:0|max:100','tax_inclusive'=>'required|boolean','cod_enabled'=>'required|boolean','is_active'=>'required|boolean']);
  $rule=StoreMarketRule::updateOrCreate(['market_id'=>$v['market_id']],$v);
  return ApiResponse::success($rule,'Market checkout rule saved.','STORE_RULE_SAVED');
 }
}
