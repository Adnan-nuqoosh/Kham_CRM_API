<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\InventoryStock;
use App\Services\FulfillmentWarehouseResolver;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use App\Support\StorefrontLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicStorefrontCatalogController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'search' => ['sometimes', 'string', 'max:120'],
            'brand' => ['sometimes', 'string', 'max:160'],
            'category' => ['sometimes', 'string', 'max:160'],
            'sort' => ['sometimes', 'in:newest,name,price_asc,price_desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $market = $this->market((int) $data['market_id']);
        $query = Product::query()->where('status', ProductStatus::Active)
            ->whereHas('variants', fn ($q) => $this->sellableVariant($q, $market));
        if (!empty($data['search'])) {
            $term = trim($data['search']);
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('name_ar', 'like', '%'.$term.'%'));
        }
        if (!empty($data['brand'])) {
            $query->whereHas('brand', fn ($q) => $q->where('slug', $data['brand'])
                ->orWhere('id', ctype_digit($data['brand']) ? (int) $data['brand'] : -1));
        }
        if (!empty($data['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $data['category'])
                ->orWhere('id', ctype_digit($data['category']) ? (int) $data['category'] : -1));
        }

        $sort = $data['sort'] ?? 'newest';
        if ($sort === 'name') {
            $query->orderBy('name')->orderBy('id');
        } elseif (in_array($sort, ['price_asc', 'price_desc'], true)) {
            $direction = $sort === 'price_asc' ? 'asc' : 'desc';
            $query->orderBy(
                \App\Models\ProductMarketPrice::query()
                    ->select('price')
                    ->join('product_variants as sv', 'sv.id', '=', 'product_market_prices.variant_id')
                    ->whereColumn('sv.product_id', 'products.id')
                    ->where('sv.is_active', true)
                    ->where('product_market_prices.market_id', $market->id)
                    ->where('product_market_prices.currency_id', $market->currency_id)
                    ->where('product_market_prices.is_active', true)
                    ->where(fn ($dates) => $dates->whereNull('product_market_prices.starts_at')
                        ->orWhere('product_market_prices.starts_at', '<=', now()))
                    ->where(fn ($dates) => $dates->whereNull('product_market_prices.ends_at')
                        ->orWhere('product_market_prices.ends_at', '>=', now()))
                    ->orderBy('price', $direction)->limit(1),
                $direction
            )->orderBy('products.id');
        } else {
            $query->orderByDesc('products.id');
        }
        $query->withCount(['reviews as review_count' => fn ($r) => $r->where('status', 'approved')])
            ->withAvg(['reviews as average_rating' => fn ($r) => $r->where('status', 'approved')], 'rating');
        $page = $query->with(['media', 'brand', 'category'])
            ->paginate(PaginationMeta::perPage($request, 20));
        $locale = StorefrontLocale::resolve($request);
        return ApiResponse::success(
            collect($page->items())->map(fn (Product $p) => $this->display($p, $locale, $market))->all(),
            'Public storefront products fetched.', 'STOREFRONT_PRODUCTS', 200, PaginationMeta::from($page)
        )->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $product)
    {
        $data = $request->validate(['market_id' => ['required', 'integer', 'exists:markets,id']]);
        $market = $this->market((int) $data['market_id']);
        $product = Product::query()
            ->withCount(['reviews as review_count' => fn ($r) => $r->where('status', 'approved')])
            ->withAvg(['reviews as average_rating' => fn ($r) => $r->where('status', 'approved')], 'rating')
            ->where('slug', $product)
            ->when(ctype_digit($product), fn ($q) => $q->orWhere('id', (int) $product))
            ->firstOrFail();
        abort_unless($product->status === ProductStatus::Active
            && $product->variants()->where(fn ($q) => $this->sellableVariant($q, $market))->exists(), 404);
        $product->loadMissing(['media', 'brand', 'category']);
        return ApiResponse::success($this->display($product, StorefrontLocale::resolve($request), $market),
            'Public storefront product fetched.', 'STOREFRONT_PRODUCT')
            ->header('Cache-Control', 'no-store');
    }

    private function market(int $id): Market
    {
        $market = Market::with('currency')->findOrFail($id);
        if (!$market->is_active || !$market->currency || !$market->currency->is_active) {
            throw ValidationException::withMessages(['market_id' => ['Selected sales market is unavailable.']]);
        }
        return $market;
    }

    private function sellableVariant($query, Market $market)
    {
        return $query->where('is_active', true)
            ->whereHas('prices', fn ($q) => $q->where('market_id', $market->id)
                ->where('currency_id', $market->currency_id)->where('is_active', true)
                ->where(fn ($dates) => $dates->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>=', now())));
    }

    private function display(Product $product, string $locale, Market $market): array
    {
        $warehouse = app(FulfillmentWarehouseResolver::class)->forMarket((int) $market->id);
        $variants = $product->variants()->where(fn ($q) => $this->sellableVariant($q, $market))
            ->with(['prices' => fn ($q) => $q->where('market_id', $market->id)
                ->where('currency_id', $market->currency_id)->where('is_active', true)
                ->where(fn ($dates) => $dates->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($dates) => $dates->whereNull('ends_at')->orWhere('ends_at', '>=', now()))])
            ->orderBy('id')->get();
        $stockByVariant = InventoryStock::query()->where('warehouse_id', $warehouse->id)
            ->whereIn('variant_id', $variants->pluck('id'))->get()->keyBy('variant_id');
        $linkedIngredients = $product->linkedIngredients()->where('is_active', true)->get();
        $media = $product->relationLoaded('media')
            ? $product->media
            : $product->media()->orderBy('sort_order')->get();
        $images = $media->values()->map(fn ($m) => [
            'id' => $m->id,
            'url' => $m->url,
            'alt_text' => $m->alt_text,
            'sort_order' => $m->sort_order,
        ])->all();

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'badges' => app(\App\Services\StorefrontProductBadgeService::class)->badges($product, (int) $market->id),
            'review_count' => (int) ($product->review_count ?? 0),
            'average_rating' => $product->average_rating === null ? null : round((float) $product->average_rating, 2),
            'locale' => $locale,
            'name' => StorefrontLocale::text($product->name, $product->name_ar, $locale),
            'name_ar' => $product->name_ar,
            'short_description' => StorefrontLocale::text($product->short_description, $product->short_description_ar, $locale),
            'description' => StorefrontLocale::text($product->description, $product->description_ar, $locale),
            'description_ar' => $product->description_ar,
            'ingredients' => StorefrontLocale::text($product->ingredients, $product->ingredients_ar, $locale),
            'linked_ingredients' => $linkedIngredients->map(fn ($ingredient) => [
                'id' => $ingredient->id,
                'slug' => $ingredient->slug,
                'name' => StorefrontLocale::text($ingredient->name, $ingredient->name_ar, $locale),
                'description' => StorefrontLocale::text($ingredient->description, $ingredient->description_ar, $locale),
            ])->all(),
            'usage_instructions' => StorefrontLocale::text($product->usage_instructions, $product->usage_instructions_ar, $locale),
            'currency_code' => $market->currency->code,
            'brand_id' => $product->brand_id,
            'brand_slug' => $product->brand?->slug,
            'brand_name' => $product->brand
                ? StorefrontLocale::text($product->brand->name, $product->brand->name_ar, $locale)
                : null,
            'category_id' => $product->category_id,
            'category_slug' => $product->category?->slug,
            'category_name' => $product->category
                ? StorefrontLocale::text($product->category->name, $product->category->name_ar, $locale)
                : null,
            'image_url' => $images[0]['url'] ?? null,
            'media' => $images,
            'variants' => $variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'available_qty' => max(0, (int) ($stockByVariant->get($v->id)?->available ?? 0)),
                'title' => $v->title,
                'price' => $v->prices->first()?->price,
                'compare_at_price' => $v->prices->first()?->compare_at_price,
                'in_stock' => ($stockByVariant->get($v->id)?->available ?? 0) > 0,
            ])->all(),
        ];
    }
}
