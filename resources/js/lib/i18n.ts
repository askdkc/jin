import { page } from '@inertiajs/svelte';
import { interpolateTranslation } from '@/lib/translation';

export function currentLocale(): string | undefined {
    const locale = page.props.locale;

    return typeof locale === 'string' && locale
        ? locale.replaceAll('_', '-')
        : undefined;
}

/**
 * Translate the given key using the translations shared via Inertia props.
 * Behaves like Laravel's __() helper, including :placeholder replacement.
 *
 * Added by Breezejp (https://github.com/askdkc/breezejp)
 */
export function t(
    key: string,
    replacements: Record<string, string> = {},
): string {
    const translations = (page.props.translations ?? {}) as Record<
        string,
        string
    >;

    const translated = translations[key] ?? key;

    return interpolateTranslation(translated, replacements);
}
