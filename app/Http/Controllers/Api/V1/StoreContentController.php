<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
class StoreContentController extends Controller {
 public function ingredients(){return ApiResponse::success(DB::table('store_ingredients')->where('is_active',true)->orderBy('name')->get(),'Ingredients.');}
 public function rituals(){return ApiResponse::success(DB::table('store_rituals')->where('is_active',true)->orderBy('name')->get(),'Rituals.');}
 public function content(){return ApiResponse::success(DB::table('store_content_blocks')->where('is_active',true)->orderBy('sort_order')->get(),'Store content.');}
}
