<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Media\ImageDimensions;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/**
 * Kop gambar yang membentang selebar kertas (full-bleed) atau memiliki margin/gap
 * tertentu terhadap sisi kertas (atas, kanan, kiri) dan jarak bawah terhadap isi dokumen.
 * Secara bawaan margin bernilai 0 mm sehingga menembus tepi kiri, kanan, dan atas kertas
 * — mengabaikan margin halaman di ketiga sisi itu. Tingginya selalu dihitung dari rasio
 * asli gambar dan lebar efektif kop, tidak pernah dari nilai yang disunting manual.
 *
 * Lebar SENGAJA tidak diberi nilai literal: dibiarkan "auto" supaya matematika
 * kotak CSS sendiri yang melebarkannya — margin kiri/kanan pada lebar auto
 * menghasilkan lebar efektif persis sama dengan (lebar kertas - margin kiri - margin kanan).
 * Lebar literal (mis. 210mm) pernah dicoba dan mpdf memangkasnya kembali ke batas margin
 * kanan yang asli, padahal margin kiri negatif tetap dihormati; asimetri itu hanya muncul
 * dengan lebar eksplisit dan hilang begitu lebar dibiarkan auto.
 *
 * Margin dihitung angka pastinya di PHP (bukan CSS var()+calc()): mpdf
 * tidak mendukung calc(), dan nilai literal per instance sudah pola yang dipakai
 * renderer lain di package ini.
 *
 * Margin atas membuat browser menempatkan kop pada jarak marginTopMm dari tepi atas kertas
 * (kotak halaman browser menaruh zona kop mulai dari garis margin, sehingga margin-top CSS
 * bernilai marginTopMm - marginHalamanAtas). mpdf memaku kop pada margin_header dan
 * mengabaikan margin negatif itu — bukan dipangkas, benar-benar diabaikan. MpdfEngine
 * mengatasinya sendiri: menetapkan margin_header ke 0 dan menetralkan margin atas ini khusus
 * untuk PDF ke nilai marginTopMm, dipandu RenderedDocument::headerBleedsToTop() dan atribut
 * data-margin-top. Lihat MpdfEngine untuk detailnya.
 *
 * Zona yang memuat blok ini wajib bertinggi "auto" — SchemaValidator memaksakannya.
 * Zona bertinggi tetap dipasangi overflow:hidden oleh paginator, dan itu akan
 * memotong bagian gambar yang sengaja menembus ke atas margin.
 *
 * @internal Detail implementasi, bebas berubah di rilis minor — lihat "API publik" di README.
 */
final class LetterheadImageRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::LetterheadImage;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $src = $context->source((string) $block->prop('src'));

        if ($src === null) {
            return $context->marker('Variabel gambar kop tidak dikenal');
        }

        if ($src === '') {
            return $context->marker('Gambar kop belum dipilih');
        }

        if (! $context->images->isAllowed($src)) {
            return $context->marker('Sumber gambar ditolak');
        }

        $marginTopMm = (float) $block->prop('marginTopMm');
        $marginRightMm = (float) $block->prop('marginRightMm');
        $marginBottomMm = (float) $block->prop('marginBottomMm');
        $marginLeftMm = (float) $block->prop('marginLeftMm');

        $effectiveWidth = max(0.0, $context->page->widthMm() - $marginLeftMm - $marginRightMm);
        $height = self::bleedHeightMm($effectiveWidth, $src, $context->imageResolver);

        if ($height === null) {
            return $context->marker('Gambar kop tidak dapat dibaca');
        }

        $margin = $context->page->margin;

        $style = sprintf(
            'margin-top:%s;margin-right:%s;margin-left:%s',
            Mm::css($marginTopMm - $margin->top),
            Mm::css($marginRightMm - $margin->right),
            Mm::css($marginLeftMm - $margin->left),
        );

        if ($marginBottomMm > 0.0) {
            $style .= sprintf(';margin-bottom:%s', Mm::css($marginBottomMm));
        }

        return sprintf(
            '<div class="db-letterhead-image__bleed" style="%s" data-margin-top="%s">'
                .'<img class="db-letterhead-image__img" src="%s" alt="%s" style="height:%s" />'
                .'</div>',
            $style,
            Mm::css($marginTopMm),
            $context->escape($src),
            $context->escape((string) $block->prop('alt')),
            Mm::css($height),
        );
    }

    /** Tinggi kop bila dibentangkan selebar ruang efektif kop, atau null bila gambar tidak dapat dibaca. */
    public static function bleedHeightMm(float $pageWidthMm, string $src, ?ImageResolver $resolver = null): ?float
    {
        $ratio = ImageDimensions::ratio($src, $resolver);

        return $ratio === null ? null : max(0.0, $pageWidthMm) / $ratio;
    }
}
