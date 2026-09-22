<?php

namespace Maqiis\DocumentBuilder\Tests\Sanitize;

use Maqiis\DocumentBuilder\Sanitize\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_keeps_the_four_allowed_tags(): void
    {
        $this->assertSame(
            'Kepada <b>Bapak</b> <i>Ahmad</i><br /><u>Direktur</u>',
            $this->sanitizer->sanitize('Kepada <b>Bapak</b> <i>Ahmad</i><br><u>Direktur</u>'),
        );
    }

    public function test_removes_script_tag_and_its_contents(): void
    {
        $this->assertSame('Halo', $this->sanitizer->sanitize('Halo<script>alert(1)</script>'));
    }

    public function test_unwraps_disallowed_tags_but_keeps_their_text(): void
    {
        $this->assertSame('Halo dunia', $this->sanitizer->sanitize('<div>Halo <span>dunia</span></div>'));
    }

    public function test_strips_all_attributes_from_allowed_tags(): void
    {
        $this->assertSame(
            '<b>tebal</b>',
            $this->sanitizer->sanitize('<b onclick="alert(1)" style="color:red">tebal</b>'),
        );
    }

    public function test_escapes_bare_angle_brackets(): void
    {
        $this->assertSame('5 &lt; 7', $this->sanitizer->sanitize('5 < 7'));
    }

    public function test_preserves_indonesian_accented_characters(): void
    {
        $this->assertSame('Ma‘had Nurul Qur’an', $this->sanitizer->sanitize('Ma‘had Nurul Qur’an'));
    }

    public function test_neutralises_javascript_url_inside_anchor(): void
    {
        $this->assertSame('klik', $this->sanitizer->sanitize('<a href="javascript:alert(1)">klik</a>'));
    }

    public function test_handles_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    public function test_removes_html_comments(): void
    {
        $this->assertSame('Halo', $this->sanitizer->sanitize('Halo<!-- rahasia -->'));
    }
}
