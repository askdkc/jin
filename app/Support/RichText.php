<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class RichText
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitizeInput(mixed $value): mixed
    {
        return is_string($value) ? self::sanitize($value) : $value;
    }

    /**
     * Plain-text rendering for excerpts/snippets: strips tags, drops the internal
     * code-fence "lang:xxx" marker (see RichTextEditor.svelte), and decodes entities
     * so escaped code like &lt;body&gt; reads as <body> instead of leaking markup.
     */
    public static function plainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // The marker is always the first text inside <pre>/<code>, so it follows a ">".
        $withoutMarker = preg_replace('/>lang:\S+\n/', '>', $html) ?? $html;

        return trim(html_entity_decode(
            strip_tags($withoutMarker),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));
    }

    public static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = self::sanitizer()->sanitize($value);
        $plainText = html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $isBlank = trim(str_replace("\u{00A0}", ' ', $plainText)) === '' && ! str_contains($sanitized, '<img');

        return $isBlank ? '' : $sanitized;
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowElement('a', ['href'])
            ->allowElement('blockquote')
            ->allowElement('br')
            ->allowElement('code')
            ->allowElement('del')
            ->allowElement('div')
            ->allowElement('em')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('h5')
            ->allowElement('h6')
            // ponytail: img is restricted to relative (same-origin) src only, so pasted/typed
            // HTML can never embed a remote tracking pixel — widen with allowMediaHosts() if
            // cross-origin thumbnails are ever needed. figure has no allowed attributes, which
            // strips Trix's own data-trix-* bookkeeping while keeping the <a><img></a> it wraps.
            ->allowElement('figure')
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            ->allowMediaSchemes([])
            ->allowRelativeMedias()
            ->allowElement('li')
            ->allowElement('ol', ['start'])
            ->allowElement('p')
            ->allowElement('pre')
            ->allowElement('strong')
            ->allowElement('table')
            ->allowElement('tbody')
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('thead')
            ->allowElement('tr')
            ->allowElement('ul')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->withMaxInputLength(100_000);

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
