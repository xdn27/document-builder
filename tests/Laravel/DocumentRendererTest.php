<?php

namespace Maqiis\DocumentBuilder\Tests\Laravel;

use Maqiis\DocumentBuilder\Laravel\DocumentRenderer;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Qr\NullQrCodeGenerator;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use PHPUnit\Framework\TestCase;

class DocumentRendererTest extends TestCase
{
    private function renderer(): DocumentRenderer
    {
        return new DocumentRenderer(
            new VariableRegistry,
            ImageSourcePolicy::permissive(),
            new NullQrCodeGenerator,
            new class implements ImageResolver
            {
                public function resolve(string $src): string
                {
                    return $src;
                }
            },
        );
    }

    private function template(string $watermark): Template
    {
        $raw = Template::blank()->toArray();
        $raw['watermark'] = ['text' => $watermark, 'opacity' => 0.25];

        return SchemaValidator::validate($raw);
    }

    public function test_uses_the_template_watermark_by_default(): void
    {
        $this->assertSame('DRAF', $this->renderer()->render($this->template('DRAF'))->watermark()->text);
    }

    public function test_a_render_time_watermark_overrides_the_template(): void
    {
        $watermark = $this->renderer()->render($this->template('DRAF'), watermark: 'RAHASIA')->watermark();

        $this->assertSame('RAHASIA', $watermark->text);
        $this->assertSame(0.25, $watermark->opacity, 'opasitas template tetap dipakai');
    }

    public function test_an_empty_render_time_watermark_turns_it_off(): void
    {
        $this->assertTrue($this->renderer()->render($this->template('DRAF'), watermark: '')->watermark()->isEmpty());
    }

    public function test_a_render_time_watermark_works_on_a_template_without_one(): void
    {
        $this->assertSame('SALINAN', $this->renderer()->render(Template::blank(), watermark: 'SALINAN')->watermark()->text);
    }
}
