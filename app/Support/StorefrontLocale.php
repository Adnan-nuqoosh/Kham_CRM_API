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

    /**
     * Add localized display fields while preserving original English and Arabic values.
     */
    public static function catalogFields(object $model, string $locale, array $fields): array
    {
        $localized = ['locale' => $locale];
        foreach ($fields as $field) {
            $localized[$field . '_localized'] = self::text(
                $model->{$field},
                $model->{$field . '_ar'},
                $locale
            );
        }
        return $localized;
    }

    public static function text(?string $english, ?string $arabic, string $locale): ?string
    {
        if ($locale === 'ar' && $arabic !== null && trim($arabic) !== '') {
            return $arabic;
        }
        return $english;
    }
}
