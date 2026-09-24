<?php

namespace Maqiis\DocumentBuilder\Tests\Render\Block;

use Maqiis\DocumentBuilder\Media\ImageSourcePolicy;
use Maqiis\DocumentBuilder\Qr\QrCodeGenerator;
use Maqiis\DocumentBuilder\Render\Block\BlockRendererRegistry;
use Maqiis\DocumentBuilder\Render\RenderContext;
use Maqiis\DocumentBuilder\Schema\Block;
use Maqiis\DocumentBuilder\Schema\BlockPropSchema;
use Maqiis\DocumentBuilder\Schema\BlockType;
use Maqiis\DocumentBuilder\Variable\ArrayVariableResolver;
use PHPUnit\Framework\TestCase;

class MediaBlockRendererTest extends TestCase
{
    use RendersBlocks;

    private function echoingQrGenerator(): QrCodeGenerator
    {
        return new class implements QrCodeGenerator
        {
            public function toSvg(string $payload, float $sizeMm): string
            {
                return '<svg data-payload="'.htmlspecialchars($payload, ENT_QUOTES).'"></svg>';
            }
        };
    }

    public function test_image_renders_when_the_source_is_allowed(): void
    {
        $html = $this->renderBlock(BlockType::Image, [
            'src' => 'https://cdn.sekolah.id/ttd.png',
            'widthMm' => 40,
            'align' => 'center',
            'alt' => 'Tanda tangan',
        ], RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/'])));

        $this->assertStringContainsString('src="https://cdn.sekolah.id/ttd.png"', $html);
        $this->assertStringContainsString('width:40mm', $html);
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('alt="Tanda tangan"', $html);
    }

    public function test_image_shows_a_marker_when_the_source_is_rejected(): void
    {
        $html = $this->renderBlock(BlockType::Image, [
            'src' => 'http://127.0.0.1/secret.png',
        ], RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/'])));

        $this->assertStringNotContainsString('127.0.0.1', $html);
        $this->assertStringContainsString('Sumber gambar ditolak', $html);
    }

    public function test_image_shows_a_marker_when_the_source_is_empty(): void
    {
        $html = $this->renderBlock(BlockType::Image, ['src' => '']);

        $this->assertStringContainsString('db-marker', $html);
    }

    public function test_qr_renders_the_svg_from_the_generator(): void
    {
        $html = $this->renderBlock(
            BlockType::QrCode,
            ['payload' => 'https://sekolah.id/verif/123', 'sizeMm' => 25],
            RenderContext::sample()->withQr($this->echoingQrGenerator()),
        );

        $this->assertStringContainsString('<svg data-payload="https://sekolah.id/verif/123">', $html);
        $this->assertStringContainsString('width:25mm', $html);
    }

    public function test_qr_resolves_variables_in_the_payload(): void
    {
        $context = RenderContext::sample()
            ->withQr($this->echoingQrGenerator())
            ->withResolver(new ArrayVariableResolver(['letter' => ['token' => 'XYZ']]));

        $html = $this->renderBlock(BlockType::QrCode, ['payload' => 'verif/{{ letter.token }}'], $context);

        $this->assertStringContainsString('data-payload="verif/XYZ"', $html);
    }

    public function test_qr_shows_a_marker_when_no_generator_is_bound(): void
    {
        $html = $this->renderBlock(BlockType::QrCode, ['payload' => 'apa saja']);

        $this->assertStringContainsString('Pembangkit QR tidak tersedia', $html);
    }

    public function test_qr_shows_a_marker_when_the_payload_is_empty(): void
    {
        $html = $this->renderBlock(BlockType::QrCode, ['payload' => '']);

        $this->assertStringContainsString('db-marker', $html);
    }

    public function test_signature_renders_one_column_per_entry(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [
                ['place' => 'Bandung', 'date' => '14 September 2026', 'position' => 'Kepala Sekolah', 'name' => 'Ahmad', 'nip' => '198001012000'],
                ['place' => '', 'date' => '', 'position' => 'Sekretaris', 'name' => 'Fatimah', 'nip' => ''],
            ],
            'spaceMm' => 30,
        ]);

        // Dua kolom: setiap baris tanda tangan punya tepat dua sel.
        $this->assertSame(2, substr_count($html, 'db-signature__space'));
        $this->assertStringContainsString('Bandung, 14 September 2026', $html);
        $this->assertStringContainsString('Kepala Sekolah', $html);
        $this->assertStringContainsString('NIP. 198001012000', $html);
        $this->assertStringContainsString('height:30mm', $html);
        $this->assertStringContainsString('width:50%', $html);
    }

    public function test_signature_omits_the_nip_line_when_empty(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'name' => 'Ahmad', 'nip' => '']],
        ]);

        $this->assertStringNotContainsString('NIP.', $html);
    }

    public function test_signature_shows_place_alone_when_there_is_no_date(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => 'Bandung', 'date' => '', 'position' => '', 'name' => '', 'nip' => '']],
        ]);

        $this->assertStringContainsString('>Bandung<', $html);
        $this->assertStringNotContainsString('Bandung,', $html);
    }

    public function test_signature_space_is_a_table_row_so_mpdf_keeps_its_height(): void
    {
        // mpdf mengabaikan tinggi, padding, dan margin elemen blok di dalam sel tabel,
        // sehingga ruang tanda tangan berupa div hilang di PDF. Tinggi baris tabel dihormati.
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'name' => 'Ahmad', 'nip' => '']],
            'spaceMm' => 25,
        ]);

        $this->assertMatchesRegularExpression('#<tr><td class="db-signature__space" style="height:25mm;text-align:right"></td></tr>#', $html);
        $this->assertStringNotContainsString('<div class="db-signature__space"', $html);
    }

    public function test_signature_names_share_one_row_across_columns(): void
    {
        // Jabatan yang panjang di satu kolom tidak boleh menggeser nama di kolom lain.
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [
                ['place' => '', 'date' => '', 'position' => 'Kepala Sekolah', 'name' => 'Ahmad', 'nip' => ''],
                ['place' => '', 'date' => '', 'position' => 'Sekretaris Yayasan Pendidikan Islam', 'name' => 'Fatimah', 'nip' => ''],
            ],
        ]);

        $this->assertMatchesRegularExpression('#<tr>[^\n]*?>Ahmad</span></td>[^\n]*?>Fatimah</span></td></tr>#', $html);
    }

    public function test_signature_renders_an_uploaded_image_inside_the_space_cell(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'signature' => 'data:image/png;base64,iVBORw0KGgo=', 'name' => 'Ahmad', 'nip' => '']],
            'spaceMm' => 25,
        ]);

        $this->assertMatchesRegularExpression(
            '#<td class="db-signature__space" style="height:25mm;text-align:right"><img class="db-signature__image" src="data:image/png;base64,iVBORw0KGgo=" alt="" style="height:25mm" /></td>#',
            $html,
        );
    }

    public function test_signature_space_stays_empty_without_a_signature_image(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'signature' => '', 'name' => 'Ahmad', 'nip' => '']],
        ]);

        $this->assertStringNotContainsString('db-signature__image', $html);
    }

    public function test_signature_image_shows_a_marker_when_the_source_is_rejected(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'signature' => 'http://169.254.169.254/ttd.png', 'name' => 'Ahmad', 'nip' => '']],
        ], RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/'])));

        $this->assertStringNotContainsString('169.254.169.254', $html);
        $this->assertStringContainsString('Sumber gambar ditolak', $html);
    }

    public function test_signature_omits_rows_that_no_column_uses(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'name' => 'Ahmad', 'nip' => '']],
        ]);

        $this->assertStringNotContainsString('db-signature__dateline', $html);
        $this->assertStringNotContainsString('db-signature__nip', $html);
    }

    public function test_signature_must_not_be_split_across_pages(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'x', 'name' => 'y', 'nip' => '']],
        ]);

        $this->assertStringContainsString('data-break-inside="avoid"', $html);
    }

    public function test_block_prop_schema_exposes_default_values(): void
    {
        $defaults = BlockPropSchema::defaults(BlockType::Paragraph);

        $this->assertSame('justify', $defaults['align']);
        $this->assertEqualsWithDelta(3.0, $defaults['spaceAfterMm'], 0.001);
        $this->assertSame('', $defaults['text']);
    }

    public function test_letterhead_image_shows_a_marker_when_the_source_is_empty(): void
    {
        $html = $this->renderBlock(BlockType::LetterheadImage, ['src' => '']);

        $this->assertStringContainsString('db-marker', $html);
    }

    public function test_letterhead_image_shows_a_marker_when_the_source_is_rejected(): void
    {
        $html = $this->renderBlock(BlockType::LetterheadImage, [
            'src' => 'http://169.254.169.254/kop.png',
        ], RenderContext::sample()->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/'])));

        $this->assertStringContainsString('Sumber gambar ditolak', $html);
    }

    public function test_letterhead_image_shows_a_marker_when_the_data_uri_cannot_be_decoded(): void
    {
        // Lolos pola data URI, tapi bukan byte gambar sungguhan.
        $html = $this->renderBlock(BlockType::LetterheadImage, [
            'src' => 'data:image/png;base64,'.base64_encode('bukan gambar'),
        ]);

        $this->assertStringContainsString('Gambar kop tidak dapat dibaca', $html);
    }

    public function test_letterhead_image_bleeds_past_the_page_margins_with_height_from_ratio(): void
    {
        // 400x80 -> rasio 5:1. Halaman contoh A4 (210mm) bermargin 20mm semua sisi.
        $html = $this->renderBlock(BlockType::LetterheadImage, [
            'src' => $this->pngDataUri(400, 80),
            'alt' => 'Kop surat',
        ]);

        $this->assertStringContainsString('margin-top:-20mm;margin-right:-20mm;margin-left:-20mm', $html);
        $this->assertStringContainsString('style="height:42mm"', $html);
        $this->assertStringNotContainsString('width:', $html);
        $this->assertStringContainsString('alt="Kop surat"', $html);
        $this->assertStringContainsString('data-break-inside="avoid"', $html);
    }

    public function test_letterhead_image_applies_custom_margins_with_adjusted_dimensions(): void
    {
        // 400x80 -> rasio 5:1. Halaman contoh A4 (210mm) bermargin 20mm semua sisi.
        // Margin kop: atas 5mm, kanan 10mm, bawah 8mm, kiri 15mm.
        // Lebar efektif = 210 - 10 - 15 = 185mm.
        // Tinggi = 185 / 5 = 37mm.
        // Margin CSS:
        // top: 5 - 20 = -15mm
        // right: 10 - 20 = -10mm
        // left: 15 - 20 = -5mm
        // bottom: 8mm
        $html = $this->renderBlock(BlockType::LetterheadImage, [
            'src' => $this->pngDataUri(400, 80),
            'alt' => 'Kop bermargin',
            'marginTopMm' => 5.0,
            'marginRightMm' => 10.0,
            'marginBottomMm' => 8.0,
            'marginLeftMm' => 15.0,
        ]);

        $this->assertStringContainsString('margin-top:-15mm;margin-right:-10mm;margin-left:-5mm;margin-bottom:8mm', $html);
        $this->assertStringContainsString('data-margin-top="5mm"', $html);
        $this->assertStringContainsString('style="height:37mm"', $html);
    }

    private function pngDataUri(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    public function test_every_block_type_has_a_registered_renderer(): void
    {
        $registry = BlockRendererRegistry::default();

        foreach (BlockType::cases() as $type) {
            $html = $registry->render(
                new Block('blk', $type, BlockPropSchema::defaults($type)),
                RenderContext::sample(),
            );

            $this->assertStringNotContainsString(
                'Blok tidak dikenal',
                $html,
                sprintf('Tipe blok %s belum punya renderer terdaftar.', $type->value),
            );
        }
    }

    public function test_letterhead_image_resolves_a_variable_source(): void
    {
        $context = RenderContext::sample()->withResolver(new ArrayVariableResolver([
            'school' => ['letterhead' => $this->pngDataUri(400, 80)],
        ]));

        $html = $this->renderBlock(BlockType::LetterheadImage, ['src' => '{{ school.letterhead }}'], $context);

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('style="height:42mm"', $html);
        $this->assertStringNotContainsString('db-marker', $html);
    }

    public function test_letterhead_image_shows_a_marker_for_an_unknown_variable(): void
    {
        $html = $this->renderBlock(BlockType::LetterheadImage, ['src' => '{{ school.letterhead }}']);

        $this->assertStringContainsString('Variabel gambar kop tidak dikenal', $html);
    }

    public function test_variable_source_must_still_pass_the_image_policy(): void
    {
        // Nilai variabel tidak boleh menjadi jalan pintas melewati daftar izin.
        $context = RenderContext::sample()
            ->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/']))
            ->withResolver(new ArrayVariableResolver(['school' => ['letterhead' => 'http://169.254.169.254/kop.png']]));

        $html = $this->renderBlock(BlockType::LetterheadImage, ['src' => '{{ school.letterhead }}'], $context);

        $this->assertStringNotContainsString('169.254.169.254', $html);
        $this->assertStringContainsString('Sumber gambar ditolak', $html);
    }

    public function test_image_resolves_a_variable_source_and_escapes_it_once(): void
    {
        $context = RenderContext::sample()
            ->withImages(new ImageSourcePolicy(['https://cdn.sekolah.id/']))
            ->withResolver(new ArrayVariableResolver(['student' => ['photo' => 'https://cdn.sekolah.id/foto.png?v=1&w=200']]));

        $html = $this->renderBlock(BlockType::Image, ['src' => '{{ student.photo }}'], $context);

        $this->assertStringContainsString('src="https://cdn.sekolah.id/foto.png?v=1&amp;w=200"', $html);
    }

    public function test_image_shows_a_marker_for_an_unknown_variable(): void
    {
        $html = $this->renderBlock(BlockType::Image, ['src' => '{{ student.photo }}']);

        $this->assertStringContainsString('Variabel gambar tidak dikenal', $html);
    }

    public function test_signature_resolves_a_variable_image(): void
    {
        $context = RenderContext::sample()->withResolver(new ArrayVariableResolver([
            'employee' => ['signature' => 'data:image/png;base64,iVBORw0KGgo='],
        ]));

        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'signature' => '{{ employee.signature }}', 'name' => 'Ahmad', 'nip' => '']],
        ], $context);

        $this->assertStringContainsString('class="db-signature__image" src="data:image/png;base64,iVBORw0KGgo="', $html);
    }

    public function test_signature_shows_a_marker_for_an_unknown_variable(): void
    {
        $html = $this->renderBlock(BlockType::Signature, [
            'columns' => [['place' => '', 'date' => '', 'position' => 'Kepala', 'signature' => '{{ employee.signature }}', 'name' => 'Ahmad', 'nip' => '']],
        ]);

        $this->assertStringContainsString('Variabel tanda tangan tidak dikenal', $html);
    }
}
