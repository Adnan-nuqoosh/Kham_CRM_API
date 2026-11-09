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
        $page = $query->orderBy('id')->paginate(PaginationMeta::perPage($request, 20));
        $locale = StorefrontLocale::resolve($request);
        return ApiResponse::success(
            collect($page->items())->map(fn (Product $p) => $this->display($p, $locale, $market))->all(),
            'Public storefront products fetched.', 'STOREFRONT_PRODUCTS', 200, PaginationMeta::from($page)
        )->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Product $product)
    {
        $data = $request->validate(['market_id' => ['required', 'integer', 'exists:markets,id']]);
        $market = $this->market((int) $data['market_id']);
        abort_unless($product->status === ProductStatus::Active
            && $product->variants()->where(fn ($q) => $this->sellableVariant($q, $market))->exists(), 404);
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
        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'locale' => $locale,
            'name' => StorefrontLocale::text($product->name, $product->name_ar, $locale),
            'short_description' => StorefrontLocale::text($product->short_description, $product->short_description_ar, $locale),
            'description' => StorefrontLocale::text($product->description, $product->description_ar, $locale),
            'currency_code' => $market->currency->code,
            'variants' => $variants->map(fn ($v) => [
                'id' => $v->id,
                'title' => $v->title,
                'price' => $v->prices->first()?->price,
                'in_stock' => ($stockByVariant->get($v->id)?->available ?? 0) > 0,
            ])->all(),
        ];
    }
}
