<?php

namespace Maqiis\DocumentBuilder\Pdf;

use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Throwable;

/**
 * mpdf memaginasi sendiri dan tidak dapat memakai paginator JavaScript. Dengan font,
 * satuan, dan kotak halaman yang identik hasilnya sangat dekat dengan cetak browser,
 * tetapi pemenggalan baris bisa berbeda tipis.
 *
 * mpdf juga tidak bisa mengukur zona bertinggi "auto" sebelum merender, sehingga zona
 * seperti itu diberi ruang cadangan. Menetapkan tinggi zona secara numerik di schema
 * memberi kesetiaan tertinggi terhadap hasil cetak browser.
 */
final class MpdfEngine implements PdfEngine
{
    private ?int $lastPageCount = null;

    public function __construct(
        private readonly ?string $tempDir = null,
        private readonly float $autoHeaderReserveMm = 35.0,
        private readonly float $autoFooterReserveMm = 12.0,
        private readonly ?ImageResolver $images = null,
    ) {
        if (! class_exists(Mpdf::class)) {
            throw PdfRenderingException::engineUnavailable('mpdf', 'mpdf/mpdf');
        }
    }

    public function name(): string
    {
        return 'mpdf';
    }

    /** Jumlah halaman dokumen terakhir yang dirender, atau null bila belum pernah. */
    public function lastPageCount(): ?int
    {
        return $this->lastPageCount;
    }

    public function render(RenderedDocument $document): string
    {
        if (! FontRegistry::supportsMpdf($document->style()->fontFamily)) {
            throw PdfRenderingException::fontUnsupported($this->name(), $document->style()->fontFamily, 'gotenberg');
        }

        $page = $document->pageSetup();

        $headerReserve = $this->reserve($document->headerHeight(), $this->autoHeaderReserveMm, $document->headerHtml());
        $footerReserve = $this->reserve($document->footerHeight(), $this->autoFooterReserveMm, $document->footerHtml());

        try {
            $mpdf = new Mpdf($this->mpdfConfig($document, $page, $headerReserve, $footerReserve));

            $headerHtml = $document->headerBleedsToTop()
                ? $this->neutralizeTopBleed($document->headerHtmlForEngine())
                : $document->headerHtmlForEngine();

            $mpdf->SetHTMLHeader($this->zone($this->resolveImages($headerHtml)));
            $mpdf->SetHTMLFooter($this->zone($this->resolveImages($document->footerHtmlForEngine())));

            $mpdf->WriteHTML($document->resolvedCss(), HTMLParserMode::HEADER_CSS);
            $mpdf->WriteHTML(
                '<div class="doc-root"><div class="doc-flow">'.($this->resolveImages($document->bodyHtml()) ?? '').'</div></div>',
                HTMLParserMode::HTML_BODY,
            );

            $bytes = $mpdf->Output('', 'S');
            $this->lastPageCount = $mpdf->page;

            return $bytes;
        } catch (Throwable $e) {
            throw PdfRenderingException::engineFailed($this->name(), $e);
        }
    }

    /**
     * Dengan margin_header 0, kotak kop mpdf sudah mulai dari tepi kertas — margin
     * atas negatif yang benar untuk browser (lihat LetterheadImageRenderer) di sini
     * justru akan mendorong gambarnya ke luar halaman. Dinetralkan khusus untuk PDF;
     * browser tetap menerima HTML aslinya lewat headerHtml()/flowHtml().
     */
    private function neutralizeTopBleed(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return preg_replace(
            '/(class="db-letterhead-image__bleed" style="margin-top:)-[0-9.]+mm/',
            '${1}0',
            $html,
        );
    }

    /**
     * Mengganti src tiap <img> lewat ImageResolver, khusus untuk salinan HTML
     * yang diserahkan ke mpdf — supaya berkas di disk lokal bisa dibaca
     * langsung dari filesystem alih-alih di-fetch lewat HTTP saat merender.
     * Tanpa resolver terpasang (bawaan), HTML dikembalikan apa adanya.
     */
    private function resolveImages(?string $html): ?string
    {
        if ($html === null || $this->images === null) {
            return $html;
        }

        return preg_replace_callback(
            '/(<img\b[^>]*\bsrc=")([^"]*)(")/i',
            function (array $matches): string {
                $src = htmlspecialchars_decode($matches[2], ENT_QUOTES);
                $resolved = $this->images->resolve($src);

                return $matches[1].htmlspecialchars($resolved, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').$matches[3];
            },
            $html,
        );
    }

    /**
     * Font bawaan mpdf (times/helvetica/courier) tidak butuh apa-apa selain
     * namanya. Font berkas sendiri (Almarai) butuh 'fontDir'/'fontdata' —
     * mpdf tidak memproses @font-face di CSS sama sekali, jadi registrasi
     * lewat resolvedCss() tidak pernah bekerja; lihat FontRegistry.
     *
     * @return array<string,mixed>
     */
    private function mpdfConfig(RenderedDocument $document, PageSetup $page, float $headerReserve, float $footerReserve): array
    {
        $config = [
            'mode' => 'utf-8',
            'format' => [$page->widthMm(), $page->heightMm()],
            'margin_left' => $page->margin->left,
            'margin_right' => $page->margin->right,
            // Kop dan kaki hidup di area margin mpdf, jadi margin isi harus
            // digeser sebesar ruang yang mereka pakai.
            'margin_top' => $page->margin->top + $headerReserve,
            'margin_bottom' => $page->margin->bottom + $footerReserve,
            // mpdf memaku kop pada margin_header dan mengabaikan margin atas
            // negatif di dalamnya — bukan memangkasnya, benar-benar tidak
            // menerapkannya. Kop gambar full-bleed karena itu perlu
            // margin_header 0 supaya kotaknya sendiri sudah mulai dari tepi
            // kertas; lihat LetterheadImageRenderer.
            'margin_header' => $document->headerBleedsToTop() ? 0.0 : $page->margin->top,
            'margin_footer' => $page->margin->bottom,
            'tempDir' => $this->tempDir ?? sys_get_temp_dir().'/mpdf',
            'default_font' => FontRegistry::mpdfFamily($document->style()->fontFamily),
            'default_font_size' => $document->style()->fontSize,
        ];

        $customFont = FontRegistry::mpdfFontFiles($document->style()->fontFamily);

        if ($customFont !== null) {
            $config['fontDir'] = array_merge((new ConfigVariables)->getDefaults()['fontDir'], [$customFont['dir']]);
            $config['fontdata'] = (new FontVariables)->getDefaults()['fontdata'] + [
                FontRegistry::mpdfFamily($document->style()->fontFamily) => $customFont['files'],
            ];
        }

        return $config;
    }

    private function zone(?string $html): string
    {
        return $html === null ? '' : '<div class="doc-root">'.$html.'</div>';
    }

    private function reserve(float|string $declared, float $fallback, ?string $html): float
    {
        if ($html === null) {
            return 0.0;
        }

        return is_string($declared) ? $fallback : $declared;
    }
}
