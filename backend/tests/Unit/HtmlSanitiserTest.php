<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HtmlSanitiser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rich-text sanitiser.
 *
 * This is the single control standing between admin-authored HTML and every
 * visitor's browser, so the negative cases below are the point of the file: the
 * storefront renders the stored string with dangerouslySetInnerHTML, and
 * anything that survives sanitisation is executed.
 */
final class HtmlSanitiserTest extends TestCase
{
    private HtmlSanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitiser = new HtmlSanitiser();
    }

    #[Test]
    public function it_preserves_ordinary_formatting(): void
    {
        $html = '<h2>Returns</h2><p>Send it back within <strong>30 days</strong>.</p><ul><li>Unused</li></ul>';

        $result = $this->sanitiser->sanitise($html);

        $this->assertStringContainsString('<h2>Returns</h2>', $result);
        $this->assertStringContainsString('<strong>30 days</strong>', $result);
        $this->assertStringContainsString('<li>Unused</li>', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scriptPayloads(): array
    {
        return [
            'script tag' => ['<p>Hi</p><script>alert(1)</script>'],
            'inline handler' => ['<p onclick="alert(1)">Hi</p>'],
            'mixed-case handler' => ['<p OnMouseOver="alert(1)">Hi</p>'],
            'svg onload' => ['<svg onload="alert(1)"></svg><p>Hi</p>'],
            'img onerror' => ['<img src="x" onerror="alert(1)">'],
            'iframe' => ['<iframe src="https://evil.test"></iframe><p>Hi</p>'],
            'object' => ['<object data="x.swf"></object><p>Hi</p>'],
            'style block' => ['<style>body{display:none}</style><p>Hi</p>'],
            'form' => ['<form action="https://evil.test"><input name="password"></form>'],
        ];
    }

    #[Test]
    #[DataProvider('scriptPayloads')]
    public function it_strips_script_execution_vectors(string $payload): void
    {
        $result = strtolower($this->sanitiser->sanitise($payload));

        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onload', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
        $this->assertStringNotContainsString('<iframe', $result);
        $this->assertStringNotContainsString('<object', $result);
        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringNotContainsString('<form', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'uppercase scheme' => ['JavaScript:alert(1)'],
            // Browsers ignore embedded control characters when resolving a
            // scheme, so a naive check against the raw string passes these.
            'tab-separated scheme' => ["java\tscript:alert(1)"],
            'newline-separated scheme' => ["java\nscript:alert(1)"],
            'null byte' => ["java\0script:alert(1)"],
            'data uri' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            // Protocol-relative: inherits the page scheme and is a fully
            // external navigation.
            'protocol relative' => ['//evil.test/phish'],
        ];
    }

    #[Test]
    #[DataProvider('dangerousUrls')]
    public function it_strips_dangerous_href_schemes(string $url): void
    {
        $result = $this->sanitiser->sanitise(sprintf('<a href="%s">Click</a>', $url));

        // The link text survives — only the href is removed, so the operator's
        // prose is not silently deleted along with the payload.
        $this->assertStringContainsString('Click', $result);
        $this->assertStringNotContainsString('href', $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeUrls(): array
    {
        return [
            'https' => ['https://example.test/sale'],
            'http' => ['http://example.test/sale'],
            'mailto' => ['mailto:help@example.test'],
            'tel' => ['tel:+441234567890'],
            'root-relative' => ['/categories/sale'],
            'relative' => ['about/team'],
            'fragment' => ['#delivery'],
        ];
    }

    #[Test]
    #[DataProvider('safeUrls')]
    public function it_preserves_safe_href_schemes(string $url): void
    {
        $result = $this->sanitiser->sanitise(sprintf('<a href="%s">Link</a>', $url));

        $this->assertStringContainsString('href', $result);
    }

    #[Test]
    public function it_unwraps_a_disallowed_tag_but_keeps_its_text(): void
    {
        // An operator who pasted a <font> tag around a paragraph meant to keep
        // the paragraph.
        $result = $this->sanitiser->sanitise('<font color="red"><p>Important</p></font>');

        $this->assertStringContainsString('<p>Important</p>', $result);
        $this->assertStringNotContainsString('<font', $result);
    }

    #[Test]
    public function it_hardens_links_that_open_in_a_new_tab(): void
    {
        $result = $this->sanitiser->sanitise(
            '<a href="https://partner.test" target="_blank">Partner</a>',
        );

        // Without noopener the destination receives a handle to this page via
        // window.opener and can navigate it elsewhere.
        $this->assertStringContainsString('noopener', $result);
        $this->assertStringContainsString('noreferrer', $result);
    }

    #[Test]
    public function it_marks_body_images_as_lazy_loaded(): void
    {
        $result = $this->sanitiser->sanitise('<img src="/storage/pages/hero.jpg" alt="Hero">');

        // These tags are injected as a blob and never pass through next/image,
        // so this is the only place they can be given a loading hint.
        $this->assertStringContainsString('loading="lazy"', $result);
    }

    #[Test]
    public function it_strips_comments(): void
    {
        $result = $this->sanitiser->sanitise('<p>Visible</p><!--[if IE]><script>x()</script><![endif]-->');

        $this->assertStringContainsString('Visible', $result);
        $this->assertStringNotContainsString('<!--', $result);
    }

    #[Test]
    public function it_preserves_non_ascii_characters(): void
    {
        // DOMDocument assumes ISO-8859-1 without an explicit charset, which
        // mangles every non-ASCII character in the body.
        $result = $this->sanitiser->sanitise('<p>Prix : 15 € — livraison offerte 🚚</p>');

        $this->assertStringContainsString('€', $result);
        $this->assertStringContainsString('—', $result);
        $this->assertStringContainsString('🚚', $result);
    }

    #[Test]
    public function it_returns_an_empty_string_for_blank_input(): void
    {
        $this->assertSame('', $this->sanitiser->sanitise(null));
        $this->assertSame('', $this->sanitiser->sanitise('   '));
    }

    #[Test]
    public function plain_text_extraction_separates_block_elements(): void
    {
        // strip_tags alone would weld these into "First paragraphSecond".
        $result = $this->sanitiser->toPlainText('<p>First paragraph</p><p>Second</p>');

        $this->assertSame('First paragraph Second', $result);
    }

    #[Test]
    public function plain_text_extraction_truncates_on_a_word_boundary(): void
    {
        $result = $this->sanitiser->toPlainText(
            '<p>Returns are accepted within thirty days of delivery for unused goods.</p>',
            30,
        );

        $this->assertLessThanOrEqual(31, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
        // A description that stops mid-word reads as broken in a search result.
        $this->assertStringNotContainsString('deliv…', $result);
    }

    #[Test]
    public function plain_text_extraction_decodes_entities(): void
    {
        $this->assertSame(
            'Terms & Conditions',
            $this->sanitiser->toPlainText('<h1>Terms &amp; Conditions</h1>'),
        );
    }
}
