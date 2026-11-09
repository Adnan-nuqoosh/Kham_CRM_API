<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class OrderStoreRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['customer_id'=>['nullable','exists:customers,id'],'market_id'=>['required','exists:markets,id'],'warehouse_id'=>['sometimes','nullable','integer','exists:warehouses,id'],'coupon_code'=>['nullable','string','max:80'],'shipping_mode'=>['sometimes','in:manual,configured'],'shipping_total'=>['nullable','numeric','min:0','prohibited_if:shipping_mode,configured'],'tax_total'=>['required_if:shipping_mode,configured','nullable','numeric','min:0'],'notes'=>['nullable','string'],'items'=>['required','array','min:1','max:100'],'items.*.variant_id'=>['required','integer','distinct:strict','exists:product_variants,id'],'items.*.quantity'=>['required','integer','min:1','max:10000'],'shipping_address'=>['required','array'],'shipping_address.first_name'=>['required','string'],'shipping_address.line1'=>['required','string'],'shipping_address.city'=>['required','string'],'shipping_address.country_code'=>['required','string','size:2'],'billing_address'=>['nullable','array']];}
}
