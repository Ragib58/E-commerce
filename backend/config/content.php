<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | The homepage payload and CMS pages are identical for every visitor and
    | change only when an admin saves, so they are cached under the `content`
    | tag and purged by ContentChanged.
    |
    | The TTL is a backstop for a missed invalidation. It is also *capped* by
    | scheduling: HomepageService shortens the TTL when a section is due to
    | appear or expire sooner, because a ten-minute cache would otherwise keep
    | a flash sale on screen after it closed.
    |
    */

    'cache' => [
        'enabled' => (bool) env('CONTENT_CACHE_ENABLED', true),
        'ttl' => (int) env('CONTENT_CACHE_TTL', 600),
        'tag' => 'content',
    ],

    /*
    |--------------------------------------------------------------------------
    | Homepage
    |--------------------------------------------------------------------------
    */

    'homepage' => [
        // Ceiling on sections one homepage may hold. Not a technical limit —
        // a page with fifty sections is a configuration accident, and the
        // storefront would resolve fifty rails' worth of catalog queries.
        'max_sections' => (int) env('CONTENT_MAX_SECTIONS', 40),

        // Ceiling on items any single section may resolve, applied after a
        // section's own `limit` setting. Bounds the blast radius of a bad save.
        'max_items_per_section' => (int) env('CONTENT_MAX_SECTION_ITEMS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rich Text Sanitisation
    |--------------------------------------------------------------------------
    |
    | CMS content and custom-content sections are authored as HTML by an
    | administrator and rendered into the storefront. That an author is trusted
    | is not sufficient: a compromised admin session, or a paste from an
    | external document, would otherwise inject script into every visitor's
    | page. The allowlist below is applied on write by HtmlSanitiser.
    |
    | Deliberately absent: script, style, iframe, object, embed, form, input.
    | Also absent is any attribute beginning `on`, which is where most
    | injection actually lives.
    |
    */

    'html' => [
        'allowed_tags' => [
            'p', 'br', 'hr', 'div', 'span', 'section', 'article',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark', 'small',
            'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            'blockquote', 'pre', 'code',
            'a', 'img', 'figure', 'figcaption',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        ],

        'allowed_attributes' => [
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
            'td' => ['colspan', 'rowspan'],
            'th' => ['colspan', 'rowspan', 'scope'],
            'col' => ['span'],
            'colgroup' => ['span'],
            // `class` is allowed globally so an editor's formatting classes
            // survive. It cannot execute anything, unlike `style`, which is
            // omitted because it carries url() and expression() payloads.
            '*' => ['class', 'id', 'dir', 'lang'],
        ],

        // URL schemes permitted in href/src. `javascript:` and `data:` are
        // absent by design — both are script execution vectors in an href.
        'allowed_schemes' => ['http', 'https', 'mailto', 'tel'],

        'max_length' => (int) env('CONTENT_MAX_HTML_LENGTH', 200000),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Pages
    |--------------------------------------------------------------------------
    |
    | Slugs seeded on install and protected from deletion. They are ordinary
    | rows in every other respect — fully editable, and nothing in the code
    | branches on these values. The list exists so a footer link to a privacy
    | policy cannot be broken by a stray delete.
    |
    */

    'system_pages' => [
        'about-us',
        'contact',
        'privacy-policy',
        'terms-and-conditions',
        'refund-policy',
        'shipping-policy',
    ],

];
