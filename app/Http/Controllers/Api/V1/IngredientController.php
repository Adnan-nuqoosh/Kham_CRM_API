<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Support\ApiResponse;
use App\Support\PaginationMeta;
use App\Support\StorefrontLocale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $ingredients = Ingredient::query()->orderBy('name')
            ->paginate(PaginationMeta::perPage($request, 20));
        return ApiResponse::success($ingredients->items(), 'Ingredients fetched.',
            'INGREDIENT_LIST', 200, PaginationMeta::from($ingredients));
    }

    public function show(Ingredient $ingredient)
    {
        return ApiResponse::success($ingredient);
    }

    public function store(Request $request)
    {
        $data = $this->validateIngredient($request);
        return ApiResponse::success(Ingredient::create($data), 'Ingredient created.', 'INGREDIENT_CREATED', 201);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $ingredient->update($this->validateIngredient($request, $ingredient));
        return ApiResponse::success($ingredient->refresh(), 'Ingredient updated.', 'INGREDIENT_UPDATED');
    }

    public function publicIndex(Request $request)
    {
        $request->validate(['search' => ['sometimes', 'string', 'max:120']]);
        $query = Ingredient::query()->where('is_active', true);
        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('name_ar', 'like', '%'.$term.'%'));
        }
        $page = $query->orderBy('id')->paginate(PaginationMeta::perPage($request, 20));
        $locale = StorefrontLocale::resolve($request);
        return ApiResponse::success(collect($page->items())
            ->map(fn ($ingredient) => $this->publicFields($ingredient, $locale))->all(),
            'Public ingredients fetched.', 'STOREFRONT_INGREDIENTS', 200, PaginationMeta::from($page))
            ->header('Cache-Control', 'no-store');
    }

    public function publicShow(Request $request, string $slug)
    {
        $ingredient = Ingredient::query()->where('slug', $slug)->where('is_active', true)->firstOrFail();
        return ApiResponse::success($this->publicFields($ingredient, StorefrontLocale::resolve($request)),
            'Public ingredient fetched.', 'STOREFRONT_INGREDIENT')
            ->header('Cache-Control', 'no-store');
    }

    private function publicFields(Ingredient $ingredient, string $locale): array
    {
        return [
            'id' => $ingredient->id,
            'slug' => $ingredient->slug,
            'name' => StorefrontLocale::text($ingredient->name, $ingredient->name_ar, $locale),
            'description' => StorefrontLocale::text($ingredient->description, $ingredient->description_ar, $locale),
        ];
    }

    private function validateIngredient(Request $request, ?Ingredient $ingredient = null): array
    {
        return $request->validate([
            'slug' => [$ingredient ? 'sometimes' : 'required', 'string', 'max:255',
                Rule::unique('ingredients', 'slug')->ignore($ingredient?->id)],
            'name' => [$ingredient ? 'sometimes' : 'required', 'string', 'max:255'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'description_ar' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
