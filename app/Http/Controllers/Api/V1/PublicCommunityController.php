<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicCommunityController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
        ]);
        $locale = $validated['locale'] ?? 'en';
        $content = config('community', []);
        $translate = static fn ($value) => is_array($value)
            ? ($value[$locale] ?? $value['en'] ?? '')
            : ($value ?? '');

        $sections = collect($content['sections'] ?? [])
            ->filter(static fn ($section) => is_array($section) && ($section['is_published'] ?? false) === true)
            ->sortBy('sort_order')
            ->values()
            ->map(static fn ($section) => [
                'key' => $section['key'] ?? null,
                'title' => $translate($section['title'] ?? null),
                'body' => $translate($section['body'] ?? null),
                'image_url' => $section['image_url'] ?? null,
                'link_url' => $section['link_url'] ?? null,
            ])->all();

        return ApiResponse::success([
            'locale' => $locale,
            'title' => $translate($content['title'] ?? null),
            'intro' => $translate($content['intro'] ?? null),
            'sections' => $sections,
            'links' => $content['links'] ?? [],
        ], 'Community page fetched.', 'STOREFRONT_COMMUNITY')
            ->header('Cache-Control', 'public, max-age=60');
    }
}
