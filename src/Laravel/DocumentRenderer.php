<?php

namespace Maqiis\DocumentBuilder\Laravel;

use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Sanitize\HtmlSanitizer;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\BuiltinVariables;
use Maqiis\DocumentBuilder\Variable\VariableRegistry;
use Maqiis\DocumentBuilder\Variable\VariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;

/**
 * Satu-satunya jalur render: halaman builder, halaman cetak, dan ekspor PDF
 * semuanya lewat sini. Tidak ada view Blade yang menyusun ulang surat, karena di
 * situlah divergensi antara yang dilihat dan yang dicetak bermula.
 *
 * Yang membuat kelas ini bisa dipakai aplikasi mana pun: VariableRegistry
 * datang dari container, bukan dari kelas katalog milik satu aplikasi. Tiap
 * aplikasi mem-bind katalognya sendiri (spec §8.2); yang tidak mem-bind apa pun
 * mendapat registry kosong dari provider, bukan galat.
 *
 * BuiltinVariables membungkus resolver apa pun yang dipakai — contoh maupun
 * data cetak — supaya `{{ today.long }}` dan kawan-kawan terisi juga saat
 * mencetak, bukan hanya di kanvas. Nilai dari resolver aplikasi tetap menang.
 */
final class DocumentRenderer
{
    public function __construct(
        private readonly VariableRegistry $variables,
        private readonly ImageSourcePolicy $images,
        private readonly QrCodeGenerator $qr,
        private readonly ImageResolver $imageResolver,
        private readonly ?BuiltinVariables $builtins = null,
    ) {}

    public function render(Template $template, ?VariableResolver $resolver = null, bool $editable = false): RenderedDocument
    {
        $resolver ??= $this->variables->sampleResolver();

        if ($this->builtins !== null) {
            $resolver = $this->builtins->resolver($resolver);
        }

        $context = new RenderContext(
            $template->style,
            $template->page,
            new HtmlSanitizer,
            new VariableSyntax($resolver),
            $this->images,
            $this->qr,
            $this->imageResolver,
            $editable,
        );

        return (new HtmlRenderer)->render($template, $context);
    }
}
