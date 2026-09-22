<?php

/**
 * Alat verifikasi manual. Menghasilkan berkas HTML mandiri dari template contoh
 * supaya kanvas builder dan hasil print preview browser bisa dibandingkan langsung.
 *
 * Pemakaian: php packages/document-builder/tools/preview.php <template.json> <keluaran.html>
 */

require __DIR__.'/../../../vendor/autoload.php';

use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Qr\NullQrCodeGenerator;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Sanitize\HtmlSanitizer;
use Maqiis\DocumentBuilder\Schema\SchemaValidator;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;

$source = $argv[1] ?? __DIR__.'/../tests/fixtures/surat-satu-halaman.json';
$target = $argv[2] ?? sys_get_temp_dir().'/document-builder-preview.html';

$template = SchemaValidator::validate(json_decode((string) file_get_contents($source), true));

// Dirakit dengan tangan, bukan RenderContext::sample(): sample() memakai margin
// halaman baku sendiri, bukan punya template ini, dan akan salah menghitung
// geometri blok yang bergantung margin sungguhan (mis. kop gambar full-bleed).
$context = new RenderContext(
    $template->style,
    $template->page,
    new HtmlSanitizer,
    new VariableSyntax(new ArrayVariableResolver([
        'institution' => ['name' => 'Pondok Pesantren Nurul Ilmi'],
        'student' => ['name' => 'Fatimah Az-Zahra'],
        'letter' => ['number' => '001/SK/IX/2026'],
    ])),
    ImageSourcePolicy::permissive(),
    new NullQrCodeGenerator,
);

file_put_contents($target, (new HtmlRenderer)->render($template, $context)->fullHtml());

echo "Pratinjau ditulis ke {$target}\n";
