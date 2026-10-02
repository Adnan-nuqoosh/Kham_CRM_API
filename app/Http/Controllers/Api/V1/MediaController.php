<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function uploadCategoryImage(Request $request, Category $category)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $oldUrl = $category->image_url;
        $path = $request->file('image')->store('catalog/categories', 'public');

        $category->update([
            'image_url' => Storage::disk('public')->url($path),
        ]);

        $this->deleteManagedFile($oldUrl);

        return ApiResponse::success(
            $category->fresh(),
            'Category image uploaded.',
            'CATEGORY_IMAGE_UPLOADED'
        );
    }

    public function deleteCategoryImage(Category $category)
    {
        $oldUrl = $category->image_url;

        $category->update(['image_url' => null]);
        $this->deleteManagedFile($oldUrl);

        return ApiResponse::success(
            $category->fresh(),
            'Category image removed.',
            'CATEGORY_IMAGE_DELETED'
        );
    }

    public function uploadBrandLogo(Request $request, Brand $brand)
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $oldUrl = $brand->logo_url;
        $path = $request->file('image')->store('catalog/brands', 'public');

        $brand->update([
            'logo_url' => Storage::disk('public')->url($path),
        ]);

        $this->deleteManagedFile($oldUrl);

        return ApiResponse::success(
            $brand->fresh(),
            'Brand logo uploaded.',
            'BRAND_LOGO_UPLOADED'
        );
    }

    public function deleteBrandLogo(Brand $brand)
    {
        $oldUrl = $brand->logo_url;

        $brand->update(['logo_url' => null]);
        $this->deleteManagedFile($oldUrl);

        return ApiResponse::success(
            $brand->fresh(),
            'Brand logo removed.',
            'BRAND_LOGO_DELETED'
        );
    }

    public function uploadProductImages(Request $request, Product $product)
    {
        $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'alt_texts' => ['nullable', 'array'],
            'alt_texts.*' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $nextSort = ((int) $product->media()->max('sort_order')) + 1;
        $uploaded = [];

        foreach ($request->file('images') as $index => $file) {
            $path = $file->store('catalog/products/' . $product->id, 'public');

            try {
                if ($request->boolean('is_primary') && $index === 0) {
                    $product->media()->increment('sort_order');
                    $sortOrder = 0;
                } else {
                    $sortOrder = $nextSort++;
                }

                $uploaded[] = ProductMedia::create([
                    'product_id' => $product->id,
                    'type' => 'image',
                    'url' => Storage::disk('public')->url($path),
                    'alt_text' => $request->input("alt_texts.$index"),
                    'sort_order' => $sortOrder,
                ]);
            } catch (\Throwable $e) {
                Storage::disk('public')->delete($path);
                throw $e;
            }
        }

        return ApiResponse::success(
            $uploaded,
            'Product images uploaded.',
            'PRODUCT_IMAGES_UPLOADED',
            201
        );
    }

    public function deleteProductImage(Product $product, ProductMedia $media)
    {
        if ((int) $media->product_id !== (int) $product->id) {
            return ApiResponse::error(
                'Image does not belong to this product.',
                'PRODUCT_IMAGE_NOT_FOUND',
                404
            );
        }

        $url = $media->url;
        $media->delete();
        $this->deleteManagedFile($url);

        return ApiResponse::success(
            null,
            'Product image removed.',
            'PRODUCT_IMAGE_DELETED'
        );
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
