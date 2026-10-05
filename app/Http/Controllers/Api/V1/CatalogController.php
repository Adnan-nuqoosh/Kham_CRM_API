<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Market;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function categories()
    {
        $categories = Category::query()
            ->with([
                'children' => fn ($query) => $query
                    ->orderBy('sort_order')
                    ->with([
                        'children' => fn ($childQuery) => $childQuery->orderBy('sort_order'),
                    ]),
            ])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($categories);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'sort_order' => ['integer'],
            'is_active' => ['boolean'],
        ]);

        $this->ensureCategoryDepth($data['parent_id'] ?? null);

        $path = null;

        try {
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('catalog/categories', 'public');
                $data['image_url'] = Storage::disk('public')->url($path);
            }

            unset($data['image']);
            $category = Category::create($data);

            return ApiResponse::success(
                $category->load('parent'),
                'Category created.',
                'CATEGORY_CREATED',
                201
            );
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }

    public function brands()
    {
        return ApiResponse::success(Brand::orderBy('name')->get());
    }

    public function storeBrand(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'unique:brands,slug'],
            'origin_country' => ['nullable', 'string', 'size:2'],
            'description' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['boolean'],
        ]);

        $path = null;

        try {
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('catalog/brands', 'public');
                $data['logo_url'] = Storage::disk('public')->url($path);
            }

            unset($data['image']);
            $brand = Brand::create($data);

            return ApiResponse::success($brand, 'Brand created.', 'BRAND_CREATED', 201);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }
    }

    public function currencies()
    {
        return ApiResponse::success(Currency::orderByDesc('is_base')->orderBy('code')->get());
    }

    public function markets()
    {
        return ApiResponse::success(Market::with('currency')->orderBy('name')->get());
    }

    public function warehouses()
    {
        return ApiResponse::success(Warehouse::with('market.currency')->orderBy('name')->get());
    }

    private function ensureCategoryDepth(?int $parentId): void
    {
        if (!$parentId) {
            return;
        }

        $depth = 1;
        $category = Category::with('parent.parent')->findOrFail($parentId);

        while ($category) {
            $depth++;

            if ($depth > 3) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Maximum category depth is 3 levels: Category → Subcategory → Child Category.'],
                ]);
            }

            $category = $category->parent;
        }
    }
}
