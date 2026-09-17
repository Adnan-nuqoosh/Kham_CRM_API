<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class PriceUpsertRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['variant_id'=>['required','exists:product_variants,id'],'market_id'=>['required','exists:markets,id'],'currency_id'=>['required','exists:currencies,id'],'price'=>['required','numeric','min:0'],'compare_at_price'=>['nullable','numeric','gte:price'],'starts_at'=>['nullable','date'],'ends_at'=>['nullable','date','after:starts_at'],'is_active'=>['boolean']];} }
