<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\Product;
use App\Models\RitualQuizOption;
use App\Models\RitualQuizQuestion;
use App\Support\ApiResponse;
use App\Support\StorefrontLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RitualQuizController extends Controller
{
    public function questions(Request $request)
    {
        $locale = StorefrontLocale::resolve($request);
        $questions = RitualQuizQuestion::query()->where('is_active', true)
            ->whereHas('options', fn ($q) => $q->where('is_active', true))
            ->with(['options' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('id')->get();
        return ApiResponse::success($questions->map(fn ($question) => [
            'id' => $question->id,
            'question' => StorefrontLocale::text($question->question, $question->question_ar, $locale),
            'options' => $question->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => StorefrontLocale::text($option->label, $option->label_ar, $locale),
            ])->all(),
        ])->all(), 'Ritual quiz fetched.', 'RITUAL_QUIZ')->header('Cache-Control', 'no-store');
    }

    public function recommend(Request $request)
    {
        $data = $request->validate([
            'market_id' => ['required', 'integer', 'exists:markets,id'],
            'option_ids' => ['required', 'array', 'min:1', 'max:30'],
            'option_ids.*' => ['required', 'integer', 'distinct', 'exists:ritual_quiz_options,id'],
        ]);
        $market = Market::with('currency')->findOrFail($data['market_id']);
        if (!$market->is_active || !$market->currency?->is_active) {
            throw ValidationException::withMessages(['market_id' => ['Selected sales market is unavailable.']]);
        }
        $options = RitualQuizOption::query()->whereIn('id', $data['option_ids'])
            ->where('is_active', true)->whereHas('question', fn ($q) => $q->where('is_active', true))
            ->get(['id', 'question_id']);
        if ($options->count() !== count($data['option_ids']) ||
            $options->pluck('question_id')->unique()->count() !== $options->count()) {
            throw ValidationException::withMessages(['option_ids' => ['Choose one active option per question.']]);
        }
        $scores = DB::table('ritual_quiz_option_product')
            ->select('product_id')->selectRaw('SUM(weight) as score')
            ->whereIn('option_id', $options->pluck('id'))->groupBy('product_id')
            ->orderByDesc('score')->orderBy('product_id')->limit(100)->get();
        $ids = $scores->pluck('product_id')->all();
        $products = Product::query()->whereIn('id', $ids)->where('status', ProductStatus::Active)
            ->whereHas('variants', fn ($q) => $q->where('is_active', true)
                ->whereHas('prices', fn ($p) => $p->where('market_id', $market->id)
                    ->where('currency_id', $market->currency_id)->where('is_active', true)
                    ->where(fn ($d) => $d->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn ($d) => $d->whereNull('ends_at')->orWhere('ends_at', '>=', now()))))
            ->get()->keyBy('id');
        $locale = StorefrontLocale::resolve($request);
        $recommendations = $scores->filter(fn ($score) => $products->has($score->product_id))
            ->take(10)->map(function ($score) use ($products, $locale) {
                $product = $products->get($score->product_id);
                return ['id' => $product->id, 'slug' => $product->slug,
                    'name' => StorefrontLocale::text($product->name, $product->name_ar, $locale),
                    'score' => (int) $score->score];
            })->values()->all();
        return ApiResponse::success($recommendations, 'Ritual recommendations fetched.',
            'RITUAL_RECOMMENDATIONS')->header('Cache-Control', 'no-store');
    }

    public function adminIndex()
    {
        return ApiResponse::success(RitualQuizQuestion::with('options.products:id,name')->orderBy('sort_order')->get());
    }

    public function storeQuestion(Request $request)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'question_ar' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        return ApiResponse::success(RitualQuizQuestion::create($data), 'Quiz question created.', 'QUIZ_QUESTION_CREATED', 201);
    }

    public function updateQuestion(Request $request, RitualQuizQuestion $question)
    {
        $data = $request->validate([
            'question' => ['sometimes', 'string', 'max:255'],
            'question_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $question->update($data);
        return ApiResponse::success($question->refresh());
    }

    public function storeOption(Request $request, RitualQuizQuestion $question)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'label_ar' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        return ApiResponse::success($question->options()->create($data), 'Quiz option created.', 'QUIZ_OPTION_CREATED', 201);
    }

    public function updateOption(Request $request, RitualQuizOption $option)
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'label_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $option->update($data);
        return ApiResponse::success($option->refresh());
    }

    public function syncProducts(Request $request, RitualQuizOption $option)
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'max:100'],
            'products.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.weight' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $option->products()->sync(collect($data['products'])->mapWithKeys(
            fn ($p) => [$p['product_id'] => ['weight' => $p['weight']]]
        )->all());
        return ApiResponse::success($option->load('products:id,name'));
    }
}
