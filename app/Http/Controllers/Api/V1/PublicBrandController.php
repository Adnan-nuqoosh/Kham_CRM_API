<?php
namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Market;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use App\Support\StorefrontLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicBrandController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = Brand::query()->where('is_active', true);
        if (!empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('name_ar', 'like', '%'.$term.'%'));
        }
        $page = $query->orderBy('name')->orderBy('id')
            ->paginate(PaginationMeta::perPage($request, 20));
        $locale = StorefrontLocale::resolve($request);
        return ApiResponse::success(
            collect($page->items())->map(fn (Brand $brand) => $this->display($brand, $locale))->all(),
            'Public brands fetched.', 'STOREFRONT_BRANDS', 200, PaginationMeta::from($page)
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
        $brand = Brand::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        $products = Product::query()->where('brand_id', $brand->id)
            ->where('status', ProductStatus::Active)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->whereHas('prices', fn ($p) => $p->where('market_id', $market->id)
                    ->where('currency_id', $market->currency_id)->where('is_active', true)
                    ->where(fn ($d) => $d->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($d) => $d->whereNull('ends_at')->orWhere('ends_at', '>=', now()))))
            ->orderBy('id')->limit(100)->get();
        $locale = StorefrontLocale::resolve($request);
        return ApiResponse::success([
            'brand' => $this->display($brand, $locale),
            'products' => $products->map(fn (Product $product) => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => StorefrontLocale::text($product->name, $product->name_ar, $locale),
            ])->all(),
        ], 'Public brand detail fetched.', 'STOREFRONT_BRAND')
            ->header('Cache-Control', 'no-store');
    }

    private function display(Brand $brand, string $locale): array
    {
        return [
            'id' => $brand->id,
            'slug' => $brand->slug,
            'name' => StorefrontLocale::text($brand->name, $brand->name_ar, $locale),
            'description' => StorefrontLocale::text($brand->description, $brand->description_ar, $locale),
            'origin_country' => $brand->origin_country,
            'logo_url' => $brand->logo_url,
        ];
    }
}
