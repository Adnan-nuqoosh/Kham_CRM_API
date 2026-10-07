<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Market;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    public function categories(Request $request)
    {
        // Full tree for pickers/forms when tree=1 or when no page/paginate requested.
        $wantsTree = $request->boolean('tree')
            || (!$request->filled('page') && !$request->boolean('paginate'));

        if ($wantsTree) {
            $query = Category::query();
            $this->applyCategoryFilters($query, $request);

            $categories = $query
                ->with([
                    'children' => fn ($childQuery) => $childQuery
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->with([
                            'children' => fn ($grandChildQuery) => $grandChildQuery
                                ->orderBy('sort_order')
                                ->orderBy('name'),
                        ]),
                ])
                ->whereNull('parent_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return ApiResponse::success($categories, 'Category tree fetched.', 'CATEGORY_TREE');
        }

        $query = Category::query()->with(['parent.parent']);
        $this->applyCategoryFilters($query, $request);

        $paginator = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(PaginationMeta::perPage($request, 20));

        return ApiResponse::success(
            $paginator->items(),
            'Categories fetched.',
            'CATEGORY_LIST',
            200,
            PaginationMeta::from($paginator)
        );
    }

    public function showCategory(Category $category)
    {
        return ApiResponse::success(
            $category->load(['parent', 'children.children']),
            'Category fetched.',
            'CATEGORY_DETAIL'
        );
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate($this->categoryRules());

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

    public function updateCategory(Request $request, Category $category)
    {
        $data = $request->validate($this->categoryRules($category));

        if (array_key_exists('parent_id', $data)) {
            $this->ensureCategoryParentIsValid($category, $data['parent_id']);
            $this->ensureCategoryMoveDepth($category, $data['parent_id']);
        }

        unset($data['image']);
        $category->update($data);

        return ApiResponse::success(
            $category->fresh(['parent', 'children']),
            'Category updated.',
            'CATEGORY_UPDATED'
        );
    }

    public function destroyCategory(Category $category)
    {
        $imageUrl = $category->image_url;
        $category->delete();
        $this->deleteManagedFile($imageUrl);

        return ApiResponse::success(null, 'Category deleted.', 'CATEGORY_DELETED');
    }

    public function brands(Request $request)
    {
        $query = Brand::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('origin_country', 'like', "%{$search}%")
            );
        }

        if ($request->query->has('is_active') && $request->query('is_active') !== '') {
            $query->where(
                'is_active',
                filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
            );
        }

        $paginator = $query
            ->orderBy('name')
            ->paginate(PaginationMeta::perPage($request, 20));

        return ApiResponse::success(
            $paginator->items(),
            'Brands fetched.',
            'BRAND_LIST',
            200,
            PaginationMeta::from($paginator)
        );
    }

    public function showBrand(Brand $brand)
    {
        return ApiResponse::success($brand, 'Brand fetched.', 'BRAND_DETAIL');
    }

    public function storeBrand(Request $request)
    {
        $data = $request->validate($this->brandRules());

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

    public function updateBrand(Request $request, Brand $brand)
    {
        $data = $request->validate($this->brandRules($brand));
        unset($data['image']);

        $brand->update($data);

        return ApiResponse::success($brand->fresh(), 'Brand updated.', 'BRAND_UPDATED');
    }

    public function destroyBrand(Brand $brand)
    {
        $logoUrl = $brand->logo_url;
        $brand->delete();
        $this->deleteManagedFile($logoUrl);

        return ApiResponse::success(null, 'Brand deleted.', 'BRAND_DELETED');
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

    private function applyCategoryFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
            );
        }

        if ($request->query->has('is_active') && $request->query('is_active') !== '') {
            $query->where(
                'is_active',
                filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
            );
        }
    }

    private function categoryRules(?Category $category = null): array
    {
        $required = $category ? 'sometimes' : 'required';

        return [
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image' => [$category ? 'prohibited' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function brandRules(?Brand $brand = null): array
    {
        $required = $brand ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:255', Rule::unique('brands', 'slug')->ignore($brand?->id)],
            'origin_country' => ['nullable', 'string', 'size:2'],
            'description' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'image' => [$brand ? 'prohibited' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function ensureCategoryParentIsValid(Category $category, ?int $parentId): void
    {
        if (!$parentId) {
            return;
        }

        if ($parentId === $category->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A category cannot be its own parent.'],
            ]);
        }

        $ancestor = Category::find($parentId);

        while ($ancestor) {
            if ($ancestor->id === $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A category cannot be moved below one of its own children.'],
                ]);
            }

            $ancestor = $ancestor->parent;
        }
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

    private function ensureCategoryMoveDepth(Category $category, ?int $parentId): void
    {
        $newDepth = 1;

        if ($parentId) {
            $parent = Category::findOrFail($parentId);

            while ($parent) {
                $newDepth++;
                $parent = $parent->parent;
            }
        }

        $subtreeDepth = $this->categorySubtreeDepth($category);

        if (($newDepth + $subtreeDepth - 1) > 3) {
            throw ValidationException::withMessages([
                'parent_id' => ['This move would make the category tree deeper than 3 levels.'],
            ]);
        }
    }

    private function categorySubtreeDepth(Category $category): int
    {
        $category->loadMissing('children.children');

        if ($category->children->isEmpty()) {
            return 1;
        }

        return 1 + $category->children
            ->map(fn (Category $child) => $this->categorySubtreeDepth($child))
            ->max();
    }

    private function deleteManagedFile(?string $url): void
    {
        if (!$url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && str_starts_with($path, '/storage/')) {
            Storage::disk('public')->delete(
                ltrim(substr($path, strlen('/storage/')), '/')
            );
        }
    }
}
