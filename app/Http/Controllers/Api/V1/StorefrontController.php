<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Market;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Public, read-only store endpoints; intentionally does not expose cost prices. */
class StorefrontController extends Controller
{
    private function market(Request $request): Market
    {
        $code = strtoupper((string) $request->query('market', ''));
        $q = Market::query()->where('is_active', true)->with('currency');
        return ($code ? (clone $q)->where('code', $code)->first() : null)
            ?? (clone $q)->where('is_default', true)->firstOrFail();
    }

    private function productsForMarket(Market $market)
    {
        return Product::query()->where('status', 'active')
            ->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->whereHas('prices', fn ($p) => $p->where('market_id', $market->id)
                    ->where('is_active', true)
                    ->where(fn ($v) => $v->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($v) => $v->whereNull('ends_at')->orWhere('ends_at', '>=', now()))))
            ->with(['brand:id,name,slug,logo_url', 'category:id,name,slug', 'media',
                'variants' => fn ($q) => $q->where('is_active', true)->select('id','product_id','sku','title','option_values'),
                'variants.prices' => fn ($q) => $q->where('market_id', $market->id)->where('is_active', true)
                    ->where(fn ($v) => $v->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($v) => $v->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->select('id','variant_id','market_id','currency_id','price','compare_at_price','starts_at','ends_at'),
            ]);
    }

    public function settings(Request $request)
    {
        $market = $this->market($request);
        return ApiResponse::success(['market' => $market->only(['code','name','country_code','timezone']),
            'currency' => $market->currency?->only(['code','symbol','decimal_places'])], 'Store settings.', 'STOREFRONT_SETTINGS');
    }

    public function categories()
    {
        return ApiResponse::success(Category::query()->where('is_active', true)
            ->orderBy('sort_order')->get(['id','parent_id','name','slug','description','image_url']), 'Categories.', 'STOREFRONT_CATEGORIES');
    }

    public function brands()
    {
        return ApiResponse::success(Brand::query()->where('is_active', true)
            ->orderBy('name')->get(['id','name','slug','origin_country','description','logo_url']), 'Brands.', 'STOREFRONT_BRANDS');
    }

    public function products(Request $request)
    {
        $data = $request->validate([
            'market' => ['nullable','string','max:20'], 'q' => ['nullable','string','max:120'],
            'category' => ['nullable','string','max:150'], 'brand' => ['nullable','string','max:150'],
            'sort' => ['nullable','in:newest,name,price_asc,price_desc'],
            'per_page' => ['nullable','integer','min:1','max:48'],
        ]);
        $market = $this->market($request);
        $q = $this->productsForMarket($market);
        if (!empty($data['q'])) {
            $term = '%' . $data['q'] . '%';
            $q->where(fn ($x) => $x->where('name', 'like', $term)->orWhere('description', 'like', $term));
        }
        if (!empty($data['category'])) $q->whereHas('category', fn ($x) => $x->where('slug', $data['category']));
        if (!empty($data['brand'])) $q->whereHas('brand', fn ($x) => $x->where('slug', $data['brand']));
        $priceSubquery = \Illuminate\Support\Facades\DB::table('product_variants as pv')
            ->join('product_market_prices as pp', 'pp.variant_id', '=', 'pv.id')
            ->whereColumn('pv.product_id', 'products.id')->where('pv.is_active', true)
            ->where('pp.market_id', $market->id)->where('pp.is_active', true)
            ->where(fn ($x) => $x->whereNull('pp.starts_at')->orWhere('pp.starts_at', '<=', now()))
            ->where(fn ($x) => $x->whereNull('pp.ends_at')->orWhere('pp.ends_at', '>=', now()))
            ->selectRaw('MIN(pp.price)');
        match ($data['sort'] ?? 'newest') {
            'name' => $q->orderBy('name'),
            'price_asc' => $q->orderBy($priceSubquery),
            'price_desc' => $q->orderByDesc($priceSubquery),
            default => $q->latest('id'),
        };
        $page = $q->paginate((int) ($data['per_page'] ?? 16));
        return ApiResponse::success($page->items(), 'Products.', 'STOREFRONT_PRODUCTS', 200,
            ['pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()],
             'market' => $market->code, 'currency' => $market->currency?->code]);
    }

    public function product(Request $request, string $slug)
    {
        $market = $this->market($request);
        $product = $this->productsForMarket($market)->where('slug', $slug)->firstOrFail();
        return ApiResponse::success(['product' => $product, 'market' => $market->code,
            'currency' => $market->currency?->code], 'Product.', 'STOREFRONT_PRODUCT');
    }

    public function home(Request $request)
    {
        $market = $this->market($request);
        $products = $this->productsForMarket($market)->latest('id')->limit(8)->get();
        return ApiResponse::success([
            'featured_products' => $products,
            'featured_brands' => Brand::where('is_active', true)->orderBy('name')->limit(8)
                ->get(['id','name','slug','logo_url','origin_country']),
            'categories' => Category::where('is_active', true)->orderBy('sort_order')->limit(12)
                ->get(['id','name','slug','image_url']),
            'market' => $market->code, 'currency' => $market->currency?->code,
            'content_blocks' => \Illuminate\Support\Facades\DB::table('store_content_blocks')->where('is_active',true)->orderBy('sort_order')->get(),
            'rituals' => \Illuminate\Support\Facades\DB::table('store_rituals')->where('is_active',true)->orderBy('name')->limit(8)->get(),
        ], 'Home collections.', 'STOREFRONT_HOME');
    }
}
