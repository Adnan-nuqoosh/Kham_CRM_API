<?php
namespace App\Http\Controllers\Api\V1;

use App\Enums\MovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductStoreRequest;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductMarketPrice;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with($this->relations());

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', '%' . $request->search . '%')
                ->orWhereHas('variants', fn ($variant) => $variant->where('sku', 'like', '%' . $request->search . '%'))
            );
        }

        $products = $query->latest()->paginate(\App\Support\PaginationMeta::perPage($request, 20));

        return ApiResponse::success(
            $products->items(),
            'Products fetched.',
            'PRODUCT_LIST',
            200,
            \App\Support\PaginationMeta::from($products)
        );
    }

    public function store(ProductStoreRequest $request)
    {
        $this->assertNestedPermissions($request);
        $storedPaths = [];

        try {
            DB::beginTransaction();

            $product = Product::create($this->productData($request));

            foreach ($request->validated('variants', []) as $variantData) {
                $this->createOrUpdateVariant($product, $variantData, true);
            }

            $this->storeProductImages($product, $request, $storedPaths);

            DB::commit();

            return ApiResponse::success(
                $product->fresh($this->relations()),
                'Product created with media, prices and stock.',
                'PRODUCT_CREATED',
                201
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteStoredPaths($storedPaths);
            throw $e;
        }
    }

    public function show(Product $product)
    {
        return ApiResponse::success($product->load($this->relations()));
    }

    public function update(ProductStoreRequest $request, Product $product)
    {
        $this->assertNestedPermissions($request);
        $storedPaths = [];

        try {
            DB::beginTransaction();

            $product->update($this->productData($request));

            foreach ($request->validated('variants', []) as $variantData) {
                $this->createOrUpdateVariant($product, $variantData, false);
            }

            $this->storeProductImages($product, $request, $storedPaths);

            DB::commit();

            return ApiResponse::success(
                $product->fresh($this->relations()),
                'Product updated with media, prices and stock.',
                'PRODUCT_UPDATED'
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->deleteStoredPaths($storedPaths);
            throw $e;
        }
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return ApiResponse::success(null, 'Product archived.', 'PRODUCT_DELETED');
    }

    private function productData(ProductStoreRequest $request): array
    {
        return Arr::only($request->validated(), [
            'brand_id',
            'category_id',
            'name',
            'slug',
            'short_description',
            'description',
            'ingredients',
            'usage_instructions',
            'status',
            'track_inventory',
            'seo_title',
            'seo_description',
            'metadata',
        ]);
    }

    private function createOrUpdateVariant(Product $product, array $variantData, bool $creatingProduct): ProductVariant
    {
        $prices = $variantData['prices'] ?? [];
        $stocks = $variantData['stocks'] ?? [];
        $variantId = $variantData['id'] ?? null;

        unset($variantData['id'], $variantData['prices'], $variantData['stocks']);

        if ($variantId) {
            $variant = $product->variants()->whereKey($variantId)->first();

            if (!$variant) {
                throw ValidationException::withMessages([
                    'variants' => ["Variant {$variantId} does not belong to this product."],
                ]);
            }

            $variant->update($variantData);
        } else {
            if (empty($variantData['sku'])) {
                throw ValidationException::withMessages([
                    'variants' => ['SKU is required when creating a new product variant.'],
                ]);
            }

            $variant = $product->variants()->create($variantData);
        }

        $this->syncVariantPrices($variant, $prices);
        $this->syncVariantStocks(
            $variant,
            $stocks,
            $creatingProduct ? 'Opening stock on product creation' : 'Stock updated from product form'
        );

        return $variant;
    }

    private function syncVariantPrices(ProductVariant $variant, array $prices): void
    {
        foreach ($prices as $priceData) {
            ProductMarketPrice::updateOrCreate(
                [
                    'variant_id' => $variant->id,
                    'market_id' => $priceData['market_id'],
                ],
                Arr::only($priceData, [
                    'currency_id',
                    'price',
                    'compare_at_price',
                    'starts_at',
                    'ends_at',
                    'is_active',
                ])
            );
        }
    }

    private function syncVariantStocks(ProductVariant $variant, array $stocks, string $note): void
    {
        foreach ($stocks as $stockData) {
            $stock = InventoryStock::firstOrCreate(
                [
                    'variant_id' => $variant->id,
                    'warehouse_id' => $stockData['warehouse_id'],
                ],
                [
                    'on_hand' => 0,
                    'reserved' => 0,
                    'damaged' => 0,
                    'reorder_level' => $stockData['reorder_level'] ?? 10,
                ]
            );

            $stock = InventoryStock::whereKey($stock->id)->lockForUpdate()->first();
            $desiredQuantity = (int) $stockData['quantity'];
            $delta = $desiredQuantity - (int) $stock->on_hand;

            $updates = ['on_hand' => $desiredQuantity];

            if (array_key_exists('reorder_level', $stockData) && $stockData['reorder_level'] !== null) {
                $updates['reorder_level'] = (int) $stockData['reorder_level'];
            }

            $stock->update($updates);

            if ($delta !== 0) {
                InventoryMovement::create([
                    'variant_id' => $variant->id,
                    'warehouse_id' => $stock->warehouse_id,
                    'type' => MovementType::Adjustment,
                    'quantity' => $delta,
                    'note' => $note,
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function storeProductImages(Product $product, ProductStoreRequest $request, array &$storedPaths): void
    {
        $files = $request->file('images', []);

        if (!$files) {
            return;
        }

        $nextSort = $product->media()->exists()
            ? ((int) $product->media()->max('sort_order')) + 1
            : 0;

        foreach ($files as $index => $file) {
            $path = $file->store('catalog/products/' . $product->id, 'public');
            $storedPaths[] = $path;

            ProductMedia::create([
                'product_id' => $product->id,
                'type' => 'image',
                'url' => Storage::disk('public')->url($path),
                'alt_text' => $request->input("image_alt_texts.$index"),
                'sort_order' => $nextSort++,
            ]);
        }
    }

    private function deleteStoredPaths(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function assertNestedPermissions(ProductStoreRequest $request): void
    {
        $variants = $request->input('variants', []);
        $hasPrices = false;
        $hasStocks = false;

        foreach (is_array($variants) ? $variants : [] as $variant) {
            $hasPrices = $hasPrices || !empty($variant['prices']);
            $hasStocks = $hasStocks || !empty($variant['stocks']);
        }

        abort_if($hasPrices && !$request->user()?->can('pricing.manage'), 403, 'Missing pricing.manage permission.');
        abort_if($hasStocks && !$request->user()?->can('inventory.manage'), 403, 'Missing inventory.manage permission.');
    }

    private function relations(): array
    {
        return [
            'brand',
            'category',
            'variants.prices.market.currency',
            'variants.stocks.warehouse.market',
            'media',
        ];
    }
}
