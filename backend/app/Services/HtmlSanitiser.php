<?php

declare(strict_types=1);

namespace App\Services;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reduces admin-authored HTML to a safe allowlist before it is stored.
 *
 * Why this exists even though only administrators can author content: the CMS
 * body is rendered into every visitor's page on the same origin as the store.
 * A single compromised admin session, a hostile paste from a Word document, or
 * a future integration that writes content programmatically would otherwise
 * turn one row into stored XSS for every shopper. Trust in the author is not
 * an input filter.
 *
 * Sanitisation happens on *write*, not on read, so the stored value is the safe
 * value: nothing can later render the raw column and bypass the filter.
 *
 * Implemented over DOM rather than regular expressions. HTML is not a regular
 * language, and every regex-based sanitiser is eventually defeated by nesting,
 * malformed markup, or entity encoding — the parser normalises all three before
 * any decision is made.
 */
final class HtmlSanitiser
{
    /**
     * Attributes stripped from every element regardless of the allowlist.
     *
     * Event handlers are matched by prefix rather than enumerated: the set of
     * `on*` attributes grows with the platform, and an allowlist that lists
     * them individually is out of date the moment a browser ships a new one.
     */
    private const EVENT_ATTRIBUTE_PREFIX = 'on';

    /**
     * Elements removed together with their children.
     *
     * Unlisted elements are *unwrapped* — their text is kept — but the content
     * of a <script> is the payload, so keeping it would defeat the purpose.
     */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'form'];

    /**
     * Sanitise a rich-text fragment.
     *
     * Returns an empty string for null or blank input so callers can store the
     * result without branching.
     */
    public function sanitise(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $maxLength = (int) config('content.html.max_length', 200000);

        if (mb_strlen($html) > $maxLength) {
            $html = mb_substr($html, 0, $maxLength);
        }

        $document = $this->parse($html);

        if ($document === null) {
            // Unparseable markup is discarded rather than passed through: an
            // input the parser cannot model is precisely the input whose
            // rendering cannot be predicted.
            return '';
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->stripDangerousElements($document);
        $this->walk($body);

        return trim($this->innerHtml($body));
    }

    /**
     * Strip every tag, returning readable plain text.
     *
     * Used for excerpts and meta descriptions, where markup would appear
     * verbatim in a search result.
     */
    public function toPlainText(?string $html, ?int $limit = null): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        // Block elements are separated first: strip_tags would otherwise weld
        // "...end of paragraph" to "Start of next" with no space between.
        $spaced = preg_replace('/<\/(p|div|h[1-6]|li|tr|br|blockquote|section|article)>/i', ' ', $html) ?? $html;
        $spaced = preg_replace('/<br\s*\/?>/i', ' ', $spaced) ?? $spaced;

        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($limit !== null && mb_strlen($text) > $limit) {
            // Cut on a word boundary so a description never ends mid-word.
            $text = mb_substr($text, 0, $limit);
            $lastSpace = mb_strrpos($text, ' ');

            if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
                $text = mb_substr($text, 0, $lastSpace);
            }

            $text = rtrim($text, " \t\n\r\0\x0B.,;:") . '…';
        }

        return $text;
    }

    /**
     * Parse a fragment into a document.
     *
     * The meta charset is prepended because DOMDocument assumes ISO-8859-1 for
     * input without one, which mangles every non-ASCII character in the body.
     */
    private function parse(string $html): ?DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        // libxml complains about HTML5 elements it does not know; the errors
        // are not actionable and must not reach the log or the response.
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $html . '</body>',
            // LIBXML_NONET: refuse network access while parsing. Without it a
            // crafted doctype could make the parser fetch an external entity —
            // server-side request forgery triggered by saving a page.
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /**
     * Remove elements whose content is itself the hazard.
     */
    private function stripDangerousElements(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        $selector = implode(' | ', array_map(
            static fn (string $tag): string => "//{$tag}",
            self::STRIP_WITH_CONTENT,
        ));

        $nodes = $xpath->query($selector);

        if ($nodes === false) {
            return;
        }

        // Iterated in reverse: a DOMNodeList is live, so removing a node while
        // walking forwards shifts every subsequent index and silently skips
        // half the matches.
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            $node?->parentNode?->removeChild($node);
        }

        // Comments can carry conditional-comment script in older engines, and
        // never carry anything an operator intended to publish.
        $comments = $xpath->query('//comment()');

        if ($comments !== false) {
            for ($i = $comments->length - 1; $i >= 0; $i--) {
                $comment = $comments->item($i);

                $comment?->parentNode?->removeChild($comment);
            }
        }
    }

    /**
     * Recursively filter an element's children.
     *
     * A disallowed element is unwrapped rather than deleted: an operator who
     * pasted a <font> tag around a paragraph meant to keep the paragraph.
     */
    private function walk(DOMElement $element): void
    {
        $allowedTags = array_map('strtolower', (array) config('content.html.allowed_tags', []));

        // Snapshot the children before mutating: the live node list would
        // reindex underneath the loop as nodes are unwrapped.
        /** @var array<int, DOMNode> $children */
        $children = iterator_to_array($element->childNodes);

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            // Descend first, so an unwrapped element's children have already
            // been filtered by the time they are lifted into this element.
            $this->walk($child);

            if (! in_array($tag, $allowedTags, strict: true)) {
                $this->unwrap($child);

                continue;
            }

            $this->filterAttributes($child, $tag);
        }
    }

    /**
     * Replace an element with its children.
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Strip every attribute not allowed for this tag.
     */
    private function filterAttributes(DOMElement $element, string $tag): void
    {
        /** @var array<string, array<int, string>> $config */
        $config = (array) config('content.html.allowed_attributes', []);

        $allowed = array_map('strtolower', array_merge(
            $config['*'] ?? [],
            $config[$tag] ?? [],
        ));

        /** @var array<int, DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes ?? []);

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->nodeName);

            // Event handlers first: `onclick` is not in any allowlist, but
            // checking the prefix explicitly documents the intent and guards
            // against a careless future addition to the config.
            if (str_starts_with($name, self::EVENT_ATTRIBUTE_PREFIX)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (! in_array($name, $allowed, strict: true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], strict: true)
                && ! $this->isSafeUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        $this->hardenLink($element, $tag);
        $this->markImageLazy($element, $tag);
    }

    /**
     * A link opening in a new tab gets `rel="noopener noreferrer"`.
     *
     * Without noopener the opened page receives a handle to this one via
     * window.opener and can navigate it elsewhere — a phishing vector that
     * costs nothing to close.
     */
    private function hardenLink(DOMElement $element, string $tag): void
    {
        if ($tag !== 'a' || $element->getAttribute('target') !== '_blank') {
            return;
        }

        $rel = trim($element->getAttribute('rel'));
        $tokens = array_filter(preg_split('/\s+/', $rel) ?: []);

        foreach (['noopener', 'noreferrer'] as $token) {
            if (! in_array($token, $tokens, strict: true)) {
                $tokens[] = $token;
            }
        }

        $element->setAttribute('rel', implode(' ', $tokens));
    }

    /**
     * Images in body content are lazy-loaded.
     *
     * Applied here rather than in the frontend because this HTML is injected
     * as a blob — the storefront's <Image> component never sees these tags, so
     * they would otherwise be the one place on the site with eager loading.
     */
    private function markImageLazy(DOMElement $element, string $tag): void
    {
        if ($tag === 'img' && ! $element->hasAttribute('loading')) {
            $element->setAttribute('loading', 'lazy');
        }
    }

    /**
     * Whether a URL uses an allowed scheme.
     *
     * Relative URLs pass — they cannot change origin or execute. Everything
     * else must name a scheme on the allowlist, which is what rejects
     * `javascript:` and `data:` payloads.
     */
    private function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        /*
         * Control characters are stripped before the scheme is read.
         * `java\0script:` and `java\tscript:` are treated as a scheme by
         * browsers but would slip past a naive parse of the raw string.
         */
        $normalised = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? $url);

        if (str_starts_with($normalised, '#') || str_starts_with($normalised, '/')) {
            return true;
        }

        // Protocol-relative (//evil.test) inherits the page scheme and is a
        // fully external navigation; treat it as needing an explicit scheme.
        if (str_starts_with($normalised, '//')) {
            return false;
        }

        if (! str_contains($normalised, ':')) {
            // No scheme at all — a relative path like `about/team`.
            return true;
        }

        $scheme = strstr($normalised, ':', true);

        if ($scheme === false) {
            return false;
        }

        return in_array(
            $scheme,
            array_map('strtolower', (array) config('content.html.allowed_schemes', [])),
            strict: true,
        );
    }

    /**
     * Serialise an element's children without the wrapper itself.
     */
    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
