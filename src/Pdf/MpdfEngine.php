<?php

namespace Maqiis\DocumentBuilder\Pdf;

use Maqiis\DocumentBuilder\Font\FontRegistry;
use Maqiis\DocumentBuilder\Media\ImageResolver;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Schema\ZoneRepeat;
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

    /** Nama isi kop per halaman di mpdf, dipilih lewat @page (header: html_<nama>). */
    private const FIRST_PAGE_NAME = 'dbfirst';

    private const REST_PAGES_NAME = 'dbrest';

    /**
     * Luapan yang masih dianggap muat di dasar halaman. Sama dengan FIT_TOLERANCE_MM milik
     * paginator browser (paginate-dom.mjs): tata letak kedua mesin berselisih sepersekian
     * milimeter, jadi batasnya harus sama longgarnya supaya keputusan pindah halaman sama.
     */
    private const FIT_TOLERANCE_MM = 0.3;

    private const NO_KEEP_TOGETHER_CSS = '.doc-block--avoid,.db-paragraph,.db-list__item{page-break-inside:auto}';

    /** Lebar kotak penanda butir daftar; harus sama dengan `.db-list__marker { width }` di document.css. */
    private const LIST_MARKER_WIDTH_MM = 6.0;

    private const TRANSPARENT_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

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

        try {
            $headerHtml = $this->resolveImages($document->headerBleedsToTop()
                ? $this->neutralizeTopBleed($document->headerHtmlForEngine())
                : $document->headerHtmlForEngine());
            $footerHtml = $this->resolveImages($document->footerHtmlForEngine());

            // Kop yang menembus tepi atas mulai dari tepi kertas, jadi bagian setinggi margin
            // atas halaman sudah tercakup margin itu sendiri.
            $headerReserve = $this->reserve(
                $document->headerHeight(),
                $this->autoHeaderReserveMm,
                $headerHtml,
                $document,
                $document->headerBleedsToTop() ? $page->margin->top : 0.0,
            );
            $footerReserve = $this->reserve($document->footerHeight(), $this->autoFooterReserveMm, $footerHtml, $document);

            $mpdf = new Mpdf($this->mpdfConfig($document, $page, $headerReserve, $footerReserve));

            // Watermark bawaan mpdf: diagonal 45°, huruf tebal keluarga dokumen,
            // dikecilkan sampai muat di sisi pendek kertas — paginator browser
            // meniru aturan yang sama (fitWatermarkSize di paginate-dom.mjs).
            if (! $document->watermark()->isEmpty()) {
                $mpdf->SetWatermarkText($document->watermark()->text, $document->watermark()->opacity);
                $mpdf->showWatermarkText = true;
            }

            $this->matchBrowserUnderline($mpdf, $document);

            $headerHtml = $this->listMarkersForEngine($headerHtml, $mpdf, $document);
            $footerHtml = $this->listMarkersForEngine($footerHtml, $mpdf, $document);

            $perPageZones = $document->headerRepeat() !== ZoneRepeat::All || $document->footerRepeat() !== ZoneRepeat::All;

            if (! $perPageZones) {
                $mpdf->SetHTMLHeader($this->headerZone($headerHtml, $document));
                $mpdf->SetHTMLFooter($this->footerZone($footerHtml, $document, $footerReserve));
            }

            $mpdf->WriteHTML($document->resolvedCss(), HTMLParserMode::HEADER_CSS);

            if ($perPageZones) {
                $this->definePerPageZones($mpdf, $document, $headerHtml, $footerHtml, $headerReserve, $footerReserve);
            }

            // Larangan potong ditangani writeBody(); mekanisme milik mpdf dimatikan.
            $mpdf->WriteHTML(self::NO_KEEP_TOGETHER_CSS, HTMLParserMode::HEADER_CSS);

            // Paginator browser menghitung "jarak sesudah" blok terakhir saat memutuskan blok itu
            // muat di halaman atau tidak; mpdf hanya menghitungnya bila berupa padding.
            $this->writeBody($mpdf, $this->withBottomMarginsAsPadding($this->tableHeadsForEngine(
                $this->listMarkersForEngine($this->resolveImages($document->bodyHtmlForEngine()), $mpdf, $document) ?? '',
            )), $document);

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
            'margin_bottom' => $page->margin->bottom + $footerReserve - self::FIT_TOLERANCE_MM,
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
            // Browser menerapkan kerning font; tanpa ini lebar baris di mpdf berselisih sampai
            // 2 mm pada teks tebal huruf besar, sehingga teks rata tengah dan kanan bergeser.
            'useKerning' => true,
            // Perataan kanan-kiri hanya lewat spasi antarkata, seperti browser. Bawaan mpdf membagi
            // 60% sisa ruang ke spasi antarhuruf (kata di tengah baris bergeser ±1 mm) dan ikut
            // meregangkan baris terakhir paragraf bila hampir penuh.
            'jSWord' => 1.0,
            'jSmaxChar' => 0,
            'jSmaxCharLast' => 0,
            'jSmaxWordLast' => 0,
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
    private function headerZone(?string $html, RenderedDocument $document, bool $pinned = false): string
    {
        if ($html === null) {
            return '';
        }

        [$flow, $fixed] = $this->pullFixedQr($html);

        if ($fixed === [] && ! $pinned) {
            return '<div class="doc-root">'.$this->zoneFlow($flow).'</div>';
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
    private function footerZone(?string $html, RenderedDocument $document, float $reserve, bool $pinned = false): string
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

        if ($fixed !== [] || $pinned) {
            // Kaki "auto" pun dijangkar dari atas, memakai tingginya yang sudah diukur: kotak
            // berjangkar bawah tanpa tinggi pasti dikecilkan mpdf supaya "muat" (teks menyusut 7%).
            $top = $page->heightMm() - $page->margin->bottom - $reserve;

            return $this->pinnedZone($flow, 'top:'.Mm::css($top), $page).implode('', $fixed);
        }

        return '<div class="doc-root">'.$this->zoneFlow($flow).'</div>';
    }

    /** Isi zona sebagai kotak berposisi tetap selebar area isi; $anchor berupa `top:…` atau `bottom:…`. */
    private function pinnedZone(string $flow, string $anchor, PageSetup $page): string
    {
        return sprintf(
            '<div style="position:absolute;left:%s;width:%s;%s"><div class="doc-root">%s</div></div>',
            Mm::css($page->margin->left),
            Mm::css($page->contentWidthMm()),
            $anchor,
            $this->zoneFlow($flow),
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
        return $this->withMarginsAsPadding($html, 'bottom');
    }

    /**
     * Isi kop/kaki untuk mpdf: margin atas dan bawah tiap blok ditulis sebagai padding. Selain
     * margin-bottom di kaki, mpdf juga membuang margin-top elemen pertama sebuah kop/kaki.
     */
    private function zoneFlow(string $flow): string
    {
        return $this->withMarginsAsPadding($this->withMarginsAsPadding($flow, 'top'), 'bottom');
    }

    private function withMarginsAsPadding(string $html, string $side): string
    {
        return preg_replace_callback(
            '/<(?:p|div)\b[^>]*>/i',
            static fn (array $m): string => (string) preg_replace('/\bmargin-'.$side.':(\s*[0-9][0-9.]*mm)/', 'padding-'.$side.':$1', $m[0]),
            $html,
        ) ?? $html;
    }

    /**
     * Isi dokumen ditulis blok demi blok, dan engine sendiri yang memutuskan kapan sebuah blok
     * pindah ke halaman berikutnya — sama seperti paginator browser:
     *
     * - Blok yang tidak boleh terpotong (tanda tangan, paragraf, butir daftar, dst.) diukur
     *   dulu; bila tidak muat di sisa halaman, halaman baru dibuka sebelum blok itu ditulis.
     *   Larangan potong milik mpdf (page-break-inside) sengaja tidak dipakai: mpdf
     *   menerapkannya dengan menulis blok dua kali, dan kop halaman baru ikut tertulis dua kali.
     * - QR berposisi tetap digambar di tingkat atas tepat setelah bloknya, sehingga jatuh di
     *   halaman yang sama dengan bloknya.
     */
    private function writeBody(Mpdf $mpdf, string $html, RenderedDocument $document): void
    {
        $blocks = $this->topLevelBlocks($html);

        if ($blocks === null) {
            $this->writeFlow($mpdf, $html);

            return;
        }

        $probe = null;

        foreach ($this->unbreakableUnits($blocks) as [$unit, $keepTogether]) {
            [$flow, $fixed] = $this->pullFixedQr($unit);

            if ($keepTogether && $mpdf->page > 0) {
                $probe ??= $this->probe($document);
                $height = (float) $probe->_getHtmlHeight('<div class="doc-root"><div class="doc-flow">'.$this->zoneFlow($flow).'</div></div>');

                // Halaman yang masih kosong tidak diganti: blok yang lebih tinggi dari satu
                // halaman tetap ditulis dan dipecah mpdf seperti biasa.
                if ($mpdf->y + $height > $mpdf->PageBreakTrigger + 0.01 && $mpdf->y > $mpdf->tMargin + 0.01) {
                    $mpdf->AddPage();
                }
            }

            $this->writeFlow($mpdf, $flow);

            foreach ($fixed as $qr) {
                $mpdf->WriteHTML($qr, HTMLParserMode::HTML_BODY);
            }
        }
    }

    /**
     * Blok tingkat atas dipecah menjadi satuan yang ditulis sekaligus, masing-masing dengan
     * tanda apakah ia harus utuh di satu halaman. Blok daftar dipecah per butir: paginator
     * browser memindahkan butir satu per satu, tidak pernah memotong di tengah butir.
     *
     * @param  list<string>  $blocks
     * @return list<array{0: string, 1: bool}>
     */
    private function unbreakableUnits(array $blocks): array
    {
        $units = [];

        foreach ($blocks as $block) {
            if (! preg_match('#^\s*<div class="doc-block ([^"]*)"[^>]*>#', $block, $open)) {
                $units[] = [$block, false];

                continue;
            }

            $classes = explode(' ', $open[1]);

            if (in_array('db-list', $classes, true) && preg_match_all('#<div class="db-list__item".*?</div>#s', $block, $items) > 1) {
                foreach ($items[0] as $item) {
                    $units[] = [$open[0].$item.'</div>', true];
                }

                continue;
            }

            $units[] = [$block, array_intersect(['doc-block--avoid', 'db-paragraph', 'db-list'], $classes) !== []];
        }

        return $units;
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

    /**
     * Kop/kaki yang hanya tampil di halaman pertama atau selain halaman pertama.
     *
     * mpdf memilih kop per halaman dengan benar lewat @page dan @page :first, tetapi tidak
     * untuk kaki: kaki halaman pertama selalu mengikuti aturan halaman berikutnya. Karena itu
     * kaki ditulis sebagai kotak berposisi tetap di dalam kop halaman yang bersangkutan, dan
     * mekanisme kaki mpdf tidak dipakai sama sekali di jalur ini.
     *
     * Margin isi mengikuti paginator browser: zona bertinggi tetap menyisihkan ruangnya di
     * semua halaman, zona "auto" tidak memakan ruang di halaman tempat ia tidak tampil.
     */
    private function definePerPageZones(Mpdf $mpdf, RenderedDocument $document, ?string $headerHtml, ?string $footerHtml, float $headerReserve, float $footerReserve): void
    {
        $page = $document->pageSetup();
        $css = '';

        foreach ([self::REST_PAGES_NAME => 2, self::FIRST_PAGE_NAME => 1] as $name => $pageNumber) {
            $header = $headerHtml !== null && $document->headerRepeat()->appearsOn($pageNumber);
            $footer = $footerHtml !== null && $document->footerRepeat()->appearsOn($pageNumber);

            $zones = ($header ? $this->headerZone($headerHtml, $document, pinned: true) : '')
                .($footer ? $this->footerZone($footerHtml, $document, $footerReserve, pinned: true) : '');

            $mpdf->DefHTMLHeaderByName($name, $zones === '' ? '<div></div>' : $zones);

            $css .= sprintf(
                '@page%s{margin-top:%s;margin-bottom:%s;header:html_%s;}',
                $pageNumber === 1 ? ' :first' : '',
                Mm::css($page->margin->top + ($header || ! is_string($document->headerHeight()) ? $headerReserve : 0.0)),
                Mm::css($page->margin->bottom + ($footer || ! is_string($document->footerHeight()) ? $footerReserve : 0.0) - self::FIT_TOLERANCE_MM),
                $name,
            );
        }

        $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
    }

    /**
     * Ketebalan garis bawah disamakan dengan browser. Keduanya memakai metrik font, tetapi
     * browser membulatkannya ke bawah ke piksel CSS utuh (minimal 1 px), sedangkan mpdf
     * memakainya apa adanya: pada huruf tebal 12pt garis mpdf 0,40 mm, browser 0,27 mm.
     * Metrik font di mpdf diganti dengan nilai hasil pembulatan itu pada ukuran huruf dokumen.
     */
    private function matchBrowserUnderline(Mpdf $mpdf, RenderedDocument $document): void
    {
        $family = FontRegistry::mpdfFamily($document->style()->fontFamily);
        $sizePt = $document->style()->fontSize;
        $sizePx = $sizePt * 96 / 72;

        foreach (['', 'B', 'I', 'BI'] as $style) {
            try {
                $mpdf->AddFont($family, $style);
            } catch (Throwable) {
                continue;
            }

            $key = $family.$style;

            if (! isset($mpdf->fonts[$key]['ut']) || ! $mpdf->fonts[$key]['ut']) {
                continue;
            }

            $pixels = max(1.0, floor($mpdf->fonts[$key]['ut'] / 1000 * $sizePx));
            $mpdf->fonts[$key]['ut'] = $pixels * 0.75 / $sizePt * 1000;
        }
    }

    /**
     * Penanda butir daftar berlebar tetap. Di browser penandanya `inline-block` selebar
     * LIST_MARKER_WIDTH_MM; mpdf tidak mengenal inline-block, sehingga teks butir menempel ke
     * penanda dan pemenggalan barisnya berbeda. Lebar penanda diukur dengan metrik font mpdf
     * lalu sisanya diisi gambar transparan selebar itu.
     */
    private function listMarkersForEngine(?string $html, Mpdf $mpdf, RenderedDocument $document): ?string
    {
        if ($html === null || ! str_contains($html, 'db-list__marker')) {
            return $html;
        }

        $mpdf->SetFont(FontRegistry::mpdfFamily($document->style()->fontFamily), '', $document->style()->fontSize);

        return preg_replace_callback(
            '#<span class="db-list__marker">([^<]*)</span>#',
            static function (array $m) use ($mpdf): string {
                $text = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $gap = max(0.0, self::LIST_MARKER_WIDTH_MM - (float) $mpdf->GetStringWidth($text));

                return $m[0].sprintf('<img src="%s" style="width:%smm;height:0.1mm" alt="" />', self::TRANSPARENT_PIXEL, round($gap, 3));
            },
            $html,
        ) ?? $html;
    }

    /**
     * mpdf mengulang <thead> di setiap halaman tanpa syarat. Tabel yang tidak meminta
     * pengulangan (data-repeat-header="0") diberi baris kepalanya sebagai <tbody> biasa,
     * supaya hanya muncul sekali seperti di browser.
     */
    private function tableHeadsForEngine(string $html): string
    {
        return preg_replace_callback(
            '#<table class="db-table__table[^>]*data-repeat-header="0"[^>]*>.*?</table>#s',
            static fn (array $m): string => str_replace(
                ['<thead class="db-table__head">', '</thead>'],
                ['<tbody class="db-table__head">', '</tbody>'],
                $m[0],
            ),
            $html,
        ) ?? $html;
    }

    /**
     * Ruang yang disisihkan untuk kop atau kaki di margin halaman mpdf. Tinggi numerik dari
     * schema dipakai apa adanya; zona "auto" diukur, supaya isi mulai dan berakhir tepat di
     * tepi zona seperti di browser. $fallback hanya dipakai bila pengukuran gagal.
     */
    private function reserve(float|string $declared, float $fallback, ?string $html, RenderedDocument $document, float $alreadyCoveredMm = 0.0): float
    {
        if ($html === null) {
            return 0.0;
        }

        if (! is_string($declared)) {
            return $declared;
        }

        $measured = $this->measureZone($html, $document);

        return $measured === null ? $fallback : max(0.0, $measured - $alreadyCoveredMm);
    }

    /**
     * Tinggi isi mengalir sebuah zona (tanpa QR berposisi tetap), diukur pada instance mpdf
     * tersendiri supaya dokumen yang sedang disusun tidak tersentuh. Null bila gagal diukur.
     */
    private function measureZone(string $html, RenderedDocument $document): ?float
    {
        [$flow] = $this->pullFixedQr($html);

        try {
            return (float) $this->probe($document)->_getHtmlHeight('<div class="doc-root">'.$this->zoneFlow($flow).'</div>');
        } catch (Throwable) {
            return null;
        }
    }

    /** Instance mpdf tersendiri untuk mengukur tinggi HTML tanpa menyentuh dokumen yang sedang disusun. */
    private function probe(RenderedDocument $document): Mpdf
    {
        $probe = new Mpdf($this->mpdfConfig($document, $document->pageSetup(), 0.0, 0.0));
        $probe->WriteHTML($document->resolvedCss().self::NO_KEEP_TOGETHER_CSS, HTMLParserMode::HEADER_CSS);

        return $probe;
    }
}
