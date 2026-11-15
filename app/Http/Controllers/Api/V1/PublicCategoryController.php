<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Market;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use App\Support\StorefrontLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicCategoryController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'tree' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ]);

        $locale = StorefrontLocale::resolve($request);
        $tree = filter_var($data['tree'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($tree) {
            $roots = Category::query()
                ->where('is_active', true)
                ->whereNull('parent_id')
                ->with(['children' => fn ($q) => $q->where('is_active', true)
                    ->orderBy('sort_order')->orderBy('name')
                    ->with(['children' => fn ($q2) => $q2->where('is_active', true)
                        ->orderBy('sort_order')->orderBy('name')])])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

            return ApiResponse::success(
                $roots->map(fn (Category $category) => $this->displayTree($category, $locale))->all(),
                'Public categories tree fetched.',
                'STOREFRONT_CATEGORIES',
            )->header('Cache-Control', 'no-store');
        }

        $query = Category::query()->where('is_active', true);

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'];
            if ($parentId === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        if (!empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('name_ar', 'like', '%'.$term.'%')
                ->orWhere('slug', 'like', '%'.$term.'%'));
        }

        $page = $query->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->paginate(PaginationMeta::perPage($request, 50));

        return ApiResponse::success(
            collect($page->items())->map(fn (Category $category) => $this->display($category, $locale))->all(),
            'Public categories fetched.',
            'STOREFRONT_CATEGORIES',
            200,
            PaginationMeta::from($page)
        )->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $slug)
    {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
        ]);

        $market = Market::with('currency')->findOrFail($data['market_id']);
        if (!$market->is_active || !$market->currency?->is_active) {
            throw ValidationException::withMessages(['market_id' => ['Selected sales market is unavailable.']]);
        }

        $category = Category::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $products = Product::query()
            ->where('category_id', $category->id)
            ->where('status', ProductStatus::Active)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->whereHas('prices', fn ($p) => $p->where('market_id', $market->id)
                    ->where('currency_id', $market->currency_id)->where('is_active', true)
                    ->where(fn ($d) => $d->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($d) => $d->whereNull('ends_at')->orWhere('ends_at', '>=', now()))))
            ->orderBy('id')
            ->limit(100)
            ->get();

        $locale = StorefrontLocale::resolve($request);

        return ApiResponse::success([
            'category' => $this->display($category, $locale),
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => StorefrontLocale::text($product->name, $product->name_ar, $locale),
            ])->all(),
        ], 'Public category detail fetched.', 'STOREFRONT_CATEGORY')
            ->header('Cache-Control', 'no-store');
    }

    private function display(Category $category, string $locale): array
    {
        return [
            'id' => $category->id,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'name' => StorefrontLocale::text($category->name, $category->name_ar, $locale),
            'name_ar' => $category->name_ar,
            'description' => StorefrontLocale::text($category->description, $category->description_ar, $locale),
            'description_ar' => $category->description_ar,
            'image_url' => $category->image_url,
            'sort_order' => $category->sort_order,
        ];
    }

    private function displayTree(Category $category, string $locale): array
    {
        $node = $this->display($category, $locale);
        $node['children'] = $category->children
            ->map(fn (Category $child) => $this->displayTree($child, $locale))
            ->values()
            ->all();

        return $node;
    }
}
