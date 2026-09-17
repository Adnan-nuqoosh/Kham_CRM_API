<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class OrderStoreRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return ['customer_id'=>['nullable','exists:customers,id'],'market_id'=>['required','exists:markets,id'],'warehouse_id'=>['required','exists:warehouses,id'],'coupon_code'=>['nullable','string','max:80'],'shipping_total'=>['nullable','numeric','min:0'],'tax_total'=>['nullable','numeric','min:0'],'notes'=>['nullable','string'],'items'=>['required','array','min:1'],'items.*.variant_id'=>['required','exists:product_variants,id'],'items.*.quantity'=>['required','integer','min:1'],'shipping_address'=>['required','array'],'shipping_address.first_name'=>['required','string'],'shipping_address.line1'=>['required','string'],'shipping_address.city'=>['required','string'],'shipping_address.country_code'=>['required','string','size:2'],'billing_address'=>['nullable','array']];}
}
