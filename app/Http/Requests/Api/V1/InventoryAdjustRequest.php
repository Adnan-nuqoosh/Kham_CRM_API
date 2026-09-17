<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class InventoryAdjustRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['variant_id'=>['required','exists:product_variants,id'],'warehouse_id'=>['required','exists:warehouses,id'],'quantity_delta'=>['required','integer','not_in:0'],'reason'=>['required','string','max:255']];} }
