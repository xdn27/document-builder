<?php

/**
 * Menghasilkan halaman HTML mandiri dari sebuah schema — separuh PHP dari
 * verify-print, juga berguna untuk membandingkan kanvas dan print preview.
 *
 *   php tools/preview.php - < template.json > keluaran.html      (dipakai verify-print)
 *   php tools/preview.php template.json keluaran.html            (pemakaian manual)
 *   php tools/preview.php                                        (fixture contoh ke direktori temp)
 */

// Tiga letak yang mungkin: package berdiri sendiri, terpasang di
// vendor/maqiis/document-builder, atau di packages/ sebuah monorepo.
foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../autoload.php', __DIR__.'/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Qr\NullQrCodeGenerator;
use Maqiis\DocumentBuilder\Render\HtmlRenderer;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Sanitize\HtmlSanitizer;
use Maqiis\DocumentBuilder\Schema\SchemaValidationException;
use Maqiis\DocumentBuilder\Schema\Template;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;

$streaming = ($argv[1] ?? null) === '-';
$source = $streaming ? 'php://stdin' : ($argv[1] ?? __DIR__.'/../tests/fixtures/surat-satu-halaman.json');
$target = $streaming ? null : ($argv[2] ?? sys_get_temp_dir().'/document-builder-preview.html');

$raw = json_decode((string) file_get_contents($source), true);

if (! is_array($raw)) {
    fwrite(STDERR, "Masukan bukan JSON schema yang sah.\n");
    exit(1);
}

try {
    // Jalur baca: migrator + validasi struktural, tanpa batas ukuran.
    $template = Template::fromArray($raw);
} catch (SchemaValidationException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

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

$html = (new HtmlRenderer)->render($template, $context)->fullHtml();

if ($target === null) {
    echo $html;
    exit(0);
}

file_put_contents($target, $html);
echo "Pratinjau ditulis ke {$target}\n";
