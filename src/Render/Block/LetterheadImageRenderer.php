<?php

namespace Maqiis\DocumentBuilder\Render\Block;

use Maqiis\DocumentBuilder\Media\ImageDimensions;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Support\Mm;

/**
 * Kop gambar yang menembus tepi kiri, kanan, dan atas kertas — mengabaikan margin
 * halaman di ketiga sisi itu. Tingginya selalu dihitung dari rasio asli gambar
 * dan lebar kertas, tidak pernah dari nilai yang disunting manual.
 *
 * Lebar SENGAJA tidak diberi nilai literal: dibiarkan "auto" supaya matematika
 * kotak CSS sendiri yang melebarkannya — margin kiri/kanan negatif pada lebar
 * auto menghasilkan lebar sama persis dengan kertas. Lebar literal (mis. 210mm)
 * pernah dicoba dan mpdf memangkasnya kembali ke batas margin kanan yang asli,
 * padahal margin kiri negatif tetap dihormati; asimetri itu hanya muncul dengan
 * lebar eksplisit dan hilang begitu lebar dibiarkan auto.
 *
 * Margin negatif dihitung angka pastinya di PHP (bukan CSS var()+calc()): mpdf
 * tidak mendukung calc(), dan nilai literal per instance sudah pola yang dipakai
 * renderer lain di package ini.
 *
 * Margin atas negatif membuat browser menembus tepi atas dengan benar (kotak
 * halaman browser menaruh zona kop mulai dari garis margin), tapi mpdf memaku
 * kop pada margin_header dan mengabaikan margin negatif itu — bukan dipangkas,
 * benar-benar diabaikan. MpdfEngine mengatasinya sendiri: menetapkan
 * margin_header ke 0 dan menetralkan margin atas ini khusus untuk PDF, dipandu
 * RenderedDocument::headerBleedsToTop(). Lihat MpdfEngine untuk detailnya.
 *
 * Zona yang memuat blok ini wajib bertinggi "auto" — SchemaValidator memaksakannya.
 * Zona bertinggi tetap dipasangi overflow:hidden oleh paginator, dan itu akan
 * memotong bagian gambar yang sengaja menembus ke atas margin.
 */
final class LetterheadImageRenderer implements BlockRenderer
{
    public function type(): BlockType
    {
        return BlockType::LetterheadImage;
    }

    public function render(Block $block, RenderContext $context): string
    {
        $src = trim((string) $block->prop('src'));

        if ($src === '') {
            return $context->marker('Gambar kop belum dipilih');
        }

        if (! $context->images->isAllowed($src)) {
            return $context->marker('Sumber gambar ditolak');
        }

        $height = self::bleedHeightMm($context->page->widthMm(), $src, $context->imageResolver);

        if ($height === null) {
            return $context->marker('Gambar kop tidak dapat dibaca');
        }

        $margin = $context->page->margin;

        return sprintf(
            '<div class="db-letterhead-image__bleed" style="margin-top:%s;margin-right:%s;margin-left:%s">'
                .'<img class="db-letterhead-image__img" src="%s" alt="%s" style="height:%s" />'
                .'</div>',
            Mm::css(-$margin->top),
            Mm::css(-$margin->right),
            Mm::css(-$margin->left),
            $context->escape($src),
            $context->escape((string) $block->prop('alt')),
            Mm::css($height),
        );
    }

    /** Tinggi kop bila dibentangkan selebar kertas, atau null bila gambar tidak dapat dibaca. */
    public static function bleedHeightMm(float $pageWidthMm, string $src, ?ImageResolver $resolver = null): ?float
    {
        $ratio = ImageDimensions::ratio($src, $resolver);

        return $ratio === null ? null : $pageWidthMm / $ratio;
    }
}
