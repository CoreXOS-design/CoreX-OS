<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Services\Prospecting\PortalImageUrlResolver;
use PHPUnit\Framework\TestCase;

/**
 * AT-22 item 2/7 — og:image extraction from portal listing pages.
 *
 * The resolver recovers a re-downloadable image URL for the 4032 orphaned rows
 * whose thumbnail_source_url is null but whose portal_url is known. These cases
 * lock the parse against the real markup Property24 and PrivateProperty emit
 * (verified live during the investigation).
 */
final class PortalImageUrlResolverTest extends TestCase
{
    private PortalImageUrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PortalImageUrlResolver();
    }

    public function test_extracts_privateproperty_og_image(): void
    {
        $html = '<html><head><meta property="og:image" content="https://images.pp.co.za/listing/11730943/VobS8TMLklRsj5VSrEL6d0/1600/1066/contain/jpegorpng" /></head></html>';
        $this->assertSame(
            'https://images.pp.co.za/listing/11730943/VobS8TMLklRsj5VSrEL6d0/1600/1066/contain/jpegorpng',
            $this->resolver->extractOgImage($html)
        );
    }

    public function test_extracts_property24_og_image(): void
    {
        $html = '<meta property="og:image" content="https://images.prop24.com/370905422" />';
        $this->assertSame('https://images.prop24.com/370905422', $this->resolver->extractOgImage($html));
    }

    public function test_handles_reversed_attribute_order(): void
    {
        $html = '<meta content="https://images.prop24.com/999" property="og:image">';
        $this->assertSame('https://images.prop24.com/999', $this->resolver->extractOgImage($html));
    }

    public function test_handles_single_quotes_and_og_image_url(): void
    {
        $html = "<meta property='og:image:url' content='https://images.pp.co.za/listing/abc/1.jpg'>";
        $this->assertSame('https://images.pp.co.za/listing/abc/1.jpg', $this->resolver->extractOgImage($html));
    }

    public function test_decodes_html_entities_in_url(): void
    {
        $html = '<meta property="og:image" content="https://cdn.example.com/i?a=1&amp;b=2">';
        $this->assertSame('https://cdn.example.com/i?a=1&b=2', $this->resolver->extractOgImage($html));
    }

    public function test_returns_null_when_no_og_image(): void
    {
        $this->assertNull($this->resolver->extractOgImage('<html><head><title>No OG here</title></head></html>'));
        $this->assertNull($this->resolver->extractOgImage(''));
    }

    public function test_returns_null_for_empty_content(): void
    {
        $this->assertNull($this->resolver->extractOgImage('<meta property="og:image" content="">'));
    }
}
