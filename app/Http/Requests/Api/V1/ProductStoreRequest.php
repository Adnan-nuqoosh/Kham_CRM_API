<?php
namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['metadata', 'variants', 'image_alt_texts', 'ingredient_ids'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product?->id;
        $requiredOnCreate = $product ? 'sometimes' : 'required';

        return [
            'brand_id' => ['nullable', 'exists:brands,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'name' => [$requiredOnCreate, 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'short_description_ar' => ['sometimes', 'nullable', 'string'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'ingredients_ar' => ['sometimes', 'nullable', 'string'],
            'usage_instructions_ar' => ['sometimes', 'nullable', 'string'],
            'seo_title_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description_ar' => ['sometimes', 'nullable', 'string'],
            'slug' => [$requiredOnCreate, 'string', 'max:255', Rule::unique('products', 'slug')->ignore($productId)],
            'short_description' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'ingredients' => ['nullable', 'string'],
            'usage_instructions' => ['nullable', 'string'],
            'status' => [$requiredOnCreate, Rule::in(['draft', 'active', 'archived'])],
            'track_inventory' => ['boolean'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'ingredient_ids' => ['sometimes', 'array', 'max:100'],
            'ingredient_ids.*' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],

            'images' => ['sometimes', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'image_alt_texts' => ['nullable', 'array'],
            'image_alt_texts.*' => ['nullable', 'string', 'max:255'],

            'variants' => ['sometimes', 'array'],
            'variants.*.id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'variants.*.sku' => ['sometimes', 'string', 'max:100', 'distinct'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.title' => ['nullable', 'string', 'max:255'],
            'variants.*.option_values' => ['nullable', 'array'],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:0'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost_currency_code' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')->where('is_active', true)],
            'variants.*.is_active' => ['boolean'],

            'variants.*.prices' => ['sometimes', 'array'],
            'variants.*.prices.*.market_id' => ['required', 'integer', 'exists:markets,id'],
            'variants.*.prices.*.currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'variants.*.prices.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.prices.*.compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.prices.*.starts_at' => ['nullable', 'date'],
            'variants.*.prices.*.ends_at' => ['nullable', 'date'],
            'variants.*.prices.*.is_active' => ['boolean'],

            'variants.*.stocks' => ['sometimes', 'array'],
            'variants.*.stocks.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'variants.*.stocks.*.quantity' => ['required', 'integer', 'min:0'],
            'variants.*.stocks.*.reorder_level' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
