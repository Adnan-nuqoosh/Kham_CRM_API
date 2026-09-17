<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class ProductStoreRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array { $id=$this->route('product')?->id; return ['brand_id'=>['nullable','exists:brands,id'],'category_id'=>['nullable','exists:categories,id'],'name'=>['required','string','max:255'],'slug'=>['required','string','max:255',Rule::unique('products','slug')->ignore($id)],'short_description'=>['nullable','string'],'description'=>['nullable','string'],'ingredients'=>['nullable','string'],'usage_instructions'=>['nullable','string'],'status'=>['required',Rule::in(['draft','active','archived'])],'track_inventory'=>['boolean'],'seo_title'=>['nullable','string','max:255'],'seo_description'=>['nullable','string'],'metadata'=>['nullable','array'],'variants'=>['sometimes','array'],'variants.*.sku'=>['required_with:variants','string','max:100','distinct'],'variants.*.barcode'=>['nullable','string','max:100'],'variants.*.title'=>['nullable','string','max:255'],'variants.*.option_values'=>['nullable','array'],'variants.*.weight_grams'=>['nullable','integer','min:0'],'variants.*.cost_price'=>['nullable','numeric','min:0']]; }
}
