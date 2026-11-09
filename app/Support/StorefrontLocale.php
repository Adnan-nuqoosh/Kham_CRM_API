<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves storefront content language without changing CRM defaults.
 * An explicit query locale takes precedence over Accept-Language.
 */
final class StorefrontLocale
{
    public static function resolve(Request $request): string
    {
        $explicit = $request->query('locale');
        if (is_string($explicit) && $explicit !== '') {
            return in_array(strtolower($explicit), ['en', 'ar'], true)
                ? strtolower($explicit)
                : 'en';
        }

        foreach (explode(',', (string) $request->header('Accept-Language', '')) as $entry) {
            $tag = strtolower(trim(explode(';', $entry, 2)[0]));
            $language = explode('-', str_replace('_', '-', $tag), 2)[0];
            if (in_array($language, ['ar', 'en'], true)) {
                return $language;
            }
        }

        return 'en';
    }

    public static function text(?string $english, ?string $arabic, string $locale): ?string
    {
        if ($locale === 'ar' && $arabic !== null && trim($arabic) !== '') {
            return $arabic;
        }
        return $english;
    }
}
