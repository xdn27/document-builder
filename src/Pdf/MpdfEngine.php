<?php

namespace Maqiis\DocumentBuilder\Pdf;

use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Support\Mm;
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
    /** Kelas pembungkus QR berposisi tetap; lihat QrCodeRenderer. */
    private const FIXED_QR_CLASS = 'db-qrcode__wrap db-qrcode__wrap--fixed';

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

            // Watermark bawaan mpdf: diagonal 45°, huruf tebal keluarga dokumen,
            // dikecilkan sampai muat di sisi pendek kertas — paginator browser
            // meniru aturan yang sama (fitWatermarkSize di paginate-dom.mjs).
            if (! $document->watermark()->isEmpty()) {
                $mpdf->SetWatermarkText($document->watermark()->text, $document->watermark()->opacity);
                $mpdf->showWatermarkText = true;
            }

            $headerHtml = $document->headerBleedsToTop()
                ? $this->neutralizeTopBleed($document->headerHtmlForEngine())
                : $document->headerHtmlForEngine();

            $mpdf->SetHTMLHeader($this->headerZone($this->resolveImages($headerHtml), $document));
            $mpdf->SetHTMLFooter($this->footerZone($this->resolveImages($document->footerHtmlForEngine()), $document));

            $mpdf->WriteHTML($document->resolvedCss(), HTMLParserMode::HEADER_CSS);
            $this->writeBody($mpdf, $this->resolveImages($document->bodyHtmlForEngine()) ?? '');

            $bytes = $mpdf->Output('', 'S');
            $this->lastPageCount = $mpdf->page;

            return $bytes;
        } catch (Throwable $e) {
            throw PdfRenderingException::engineFailed($this->name(), $e);
        }
    }

    /**
     * Dengan margin_header 0, kotak kop mpdf sudah mulai dari tepi kertas.
     * mpdf mengabaikan margin-top pada elemen pertama di SetHTMLHeader, tetapi
     * menghormati padding-top. Dinetralkan khusus untuk PDF: margin-top dijadikan 0
     * dan jarak atas diterapkan lewat padding-top sesuai data-margin-top;
     * browser tetap menerima HTML aslinya lewat headerHtml()/flowHtml().
     */
    private function neutralizeTopBleed(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (str_contains($html, 'data-margin-top="')) {
            return preg_replace_callback(
                '/(class="db-letterhead-image__bleed"[^>]*style=")(?:margin-top:[^;"]*;?)([^"]*"\s+data-margin-top="([0-9.]+mm)")/',
                static fn (array $m): string => $m[1].'margin-top:0;padding-top:'.$m[3].';'.$m[2],
                $html,
            );
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

    /**
     * QR berposisi tetap dipindah ke tingkat atas HTML kop/kaki: mpdf hanya menghormati
     * top/left pada elemen `position:absolute` yang tidak bersarang di dalam wadah lain.
     * Di sana koordinatnya relatif ke tepi halaman, sama seperti `.doc-page` di browser,
     * dan ikut berulang di setiap halaman yang memakai kop/kaki itu.
     *
     * Blok berposisi tetap mengosongkan buffer kop/kaki mpdf (WriteFixedPosHTML), sehingga
     * isi mengalir yang ditulis sebelumnya hilang. Karena itu, begitu ada QR tetap, isi
     * mengalirnya sendiri ikut ditulis sebagai kotak berposisi tetap di tempat kop semestinya.
     */
    private function headerZone(?string $html, RenderedDocument $document): string
    {
        if ($html === null) {
            return '';
        }

        [$flow, $fixed] = $this->pullFixedQr($html);

        if ($fixed === []) {
            return '<div class="doc-root">'.$flow.'</div>';
        }

        $top = $document->headerBleedsToTop() ? 0.0 : $document->pageSetup()->margin->top;

        return $this->pinnedZone($flow, 'top:'.Mm::css($top), $document->pageSetup()).implode('', $fixed);
    }

    /**
     * mpdf menulis kaki dari bawah batas halaman lalu menggesernya ke atas setinggi isinya.
     * Akibatnya dua hal berbeda dari browser, dan keduanya diluruskan di sini:
     *
     * - Kaki bertinggi tetap menempel ke tepi bawah kotaknya, padahal browser mengisinya dari
     *   atas. Kaki seperti itu ditulis sebagai kotak berposisi tetap yang mulai di tepi atas
     *   kotak kaki.
     * - margin-bottom setiap blok dibuang (lihat withBottomMarginsAsPadding()).
     */
    private function footerZone(?string $html, RenderedDocument $document): string
    {
        if ($html === null) {
            return '';
        }

        [$flow, $fixed] = $this->pullFixedQr($html);
        $page = $document->pageSetup();
        $height = $document->footerHeight();

        if (! is_string($height)) {
            $top = $page->heightMm() - $page->margin->bottom - $height;

            return $this->pinnedZone($flow, 'top:'.Mm::css($top), $page).implode('', $fixed);
        }

        if ($fixed !== []) {
            return $this->pinnedZone($flow, 'bottom:'.Mm::css($page->margin->bottom), $page).implode('', $fixed);
        }

        return '<div class="doc-root">'.$this->withBottomMarginsAsPadding($flow).'</div>';
    }

    /** Isi zona sebagai kotak berposisi tetap selebar area isi; $anchor berupa `top:…` atau `bottom:…`. */
    private function pinnedZone(string $flow, string $anchor, PageSetup $page): string
    {
        return sprintf(
            '<div style="position:absolute;left:%s;width:%s;%s"><div class="doc-root">%s</div></div>',
            Mm::css($page->margin->left),
            Mm::css($page->contentWidthMm()),
            $anchor,
            $this->withBottomMarginsAsPadding($flow),
        );
    }

    /**
     * Di kaki dan di dalam kotak berposisi tetap, mpdf membuang margin-bottom setiap blok
     * (Mpdf::finishFlowingBlock() melewatinya selama InFooter), tetapi padding-bottom tetap
     * dihormati. Untuk <p> dan <div> tanpa garis tepi maupun latar, keduanya menghasilkan
     * jarak yang sama. Nilai negatif dibiarkan: padding tidak bisa negatif.
     */
    private function withBottomMarginsAsPadding(string $html): string
    {
        return preg_replace_callback(
            '/<(?:p|div)\b[^>]*>/i',
            static fn (array $m): string => (string) preg_replace('/\bmargin-bottom:(\s*[0-9][0-9.]*mm)/', 'padding-bottom:$1', $m[0]),
            $html,
        ) ?? $html;
    }

    /**
     * Isi dokumen ditulis per potongan blok. QR berposisi tetap digambar di tingkat atas
     * tepat setelah potongan yang memuat bloknya, sehingga jatuh di halaman yang sama
     * dengan bloknya, seperti paginator browser. Tanpa QR tetap, isi ditulis sekali jalan
     * persis seperti sebelumnya.
     */
    private function writeBody(Mpdf $mpdf, string $html): void
    {
        $blocks = str_contains($html, self::FIXED_QR_CLASS) ? $this->topLevelBlocks($html) : null;

        if ($blocks === null) {
            $this->writeFlow($mpdf, $html);

            return;
        }

        $chunk = '';

        foreach ($blocks as $block) {
            [$flow, $fixed] = $this->pullFixedQr($block);
            $chunk .= $flow;

            if ($fixed === []) {
                continue;
            }

            $this->writeFlow($mpdf, $chunk);
            $chunk = '';

            foreach ($fixed as $qr) {
                $mpdf->WriteHTML($qr, HTMLParserMode::HTML_BODY);
            }
        }

        $this->writeFlow($mpdf, $chunk);
    }

    private function writeFlow(Mpdf $mpdf, string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $mpdf->WriteHTML('<div class="doc-root"><div class="doc-flow">'.$html.'</div></div>', HTMLParserMode::HTML_BODY);
    }

    /**
     * Mengeluarkan pembungkus QR berposisi tetap dari $html.
     *
     * @return array{0: string, 1: list<string>} HTML tanpa QR tetap, dan daftar pembungkusnya
     */
    private function pullFixedQr(string $html): array
    {
        $found = [];
        $rest = preg_replace_callback(
            '#<div class="'.preg_quote(self::FIXED_QR_CLASS, '#').'"[^>]*>.*?</div>#s',
            static function (array $m) use (&$found): string {
                $found[] = $m[0];

                return '';
            },
            $html,
        );

        return [$rest ?? $html, $found];
    }

    /**
     * Memecah $html menjadi elemen `div` tingkat atas. Null bila tag tidak seimbang,
     * supaya pemanggil menulis isi sekali jalan.
     *
     * @return list<string>|null
     */
    private function topLevelBlocks(string $html): ?array
    {
        preg_match_all('#<div\b|</div>#i', $html, $tokens, PREG_OFFSET_CAPTURE);

        $blocks = [];
        $depth = 0;
        $start = 0;

        foreach ($tokens[0] as [$token, $offset]) {
            if (! str_starts_with($token, '</')) {
                $depth++;

                continue;
            }

            if (--$depth < 0) {
                return null;
            }

            if ($depth === 0) {
                $end = $offset + strlen($token);
                $blocks[] = substr($html, $start, $end - $start);
                $start = $end;
            }
        }

        if ($depth !== 0) {
            return null;
        }

        if ($start < strlen($html)) {
            $blocks[] = substr($html, $start);
        }

        return $blocks;
    }

    private function reserve(float|string $declared, float $fallback, ?string $html): float
    {
        if ($html === null) {
            return 0.0;
        }

        return is_string($declared) ? $fallback : $declared;
    }
}
