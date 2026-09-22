<?php

namespace Maqiis\DocumentBuilder\Render;

use Maqiis\DocumentBuilder\Asset\AssetLoader;
use Maqiis\DocumentBuilder\Schema\DocumentStyle;
use Maqiis\DocumentBuilder\Schema\PageSetup;
use Maqiis\DocumentBuilder\Schema\ZoneRepeat;
use Maqiis\DocumentBuilder\Variable\VariableSyntax;

/**
 * Dokumen hasil render disajikan dalam dua rupa sekaligus. Engine yang memaginasi
 * sendiri (mpdf, dompdf) memakai headerHtml/bodyHtml/footerHtml; engine berbasis
 * browser memakai fullHtml() yang sudah memuat paginator. Interface PdfEngine tidak
 * perlu berubah untuk mengakomodasi keduanya.
 */
final class RenderedDocument
{
    public function __construct(
        private readonly PageSetup $pageSetup,
        private readonly DocumentStyle $style,
        private readonly string $layoutCss,
        private readonly ?string $headerHtml,
        private readonly string $bodyHtml,
        private readonly ?string $footerHtml,
        private readonly ZoneRepeat $headerRepeat,
        private readonly ZoneRepeat $footerRepeat,
        private readonly float|string $headerHeight,
        private readonly float|string $footerHeight,
        /**
         * Cadangan tinggi yang dihitung dari isi kop/kaki, dipakai hanya oleh engine
         * yang memaginasi sendiri saat tinggi zona "auto". Browser tidak memerlukannya
         * karena ia mengukur tinggi sungguhan lewat DOM.
         */
        private readonly ?float $headerHeightHint = null,
        private readonly ?float $footerHeightHint = null,
    ) {}

    public function pageSetup(): PageSetup
    {
        return $this->pageSetup;
    }

    public function style(): DocumentStyle
    {
        return $this->style;
    }

    public function css(): string
    {
        return $this->layoutCss."\n".AssetLoader::css();
    }

    public function headerHtml(): ?string
    {
        return $this->headerHtml;
    }

    public function bodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function footerHtml(): ?string
    {
        return $this->footerHtml;
    }

    public function headerRepeat(): ZoneRepeat
    {
        return $this->headerRepeat;
    }

    public function footerRepeat(): ZoneRepeat
    {
        return $this->footerRepeat;
    }

    public function headerHeight(): float|string
    {
        return is_string($this->headerHeight) && $this->headerHeightHint !== null
            ? $this->headerHeightHint
            : $this->headerHeight;
    }

    public function footerHeight(): float|string
    {
        return is_string($this->footerHeight) && $this->footerHeightHint !== null
            ? $this->footerHeightHint
            : $this->footerHeight;
    }

    /**
     * Satu-satunya sumber cadangan tinggi kop yang dihitung (bukan dideklarasikan
     * penyusun) hari ini adalah kop gambar full-bleed, jadi sinyal ini juga berarti
     * "kop menembus margin atas". MpdfEngine memakainya untuk menetralkan margin
     * atas negatif blok itu dan menurunkan margin_header ke 0 — lihat
     * LetterheadImageRenderer untuk alasan mpdf butuh perlakuan berbeda dari browser.
     */
    public function headerBleedsToTop(): bool
    {
        return $this->headerHeightHint !== null;
    }

    /**
     * CSS untuk engine yang tidak mengenal custom property. Setiap var() diganti
     * nilai literalnya, dan aturan yang hanya relevan di layar dibuang.
     */
    public function resolvedCss(): string
    {
        $variables = PageLayoutCss::variables($this->pageSetup, $this->style);

        $css = preg_replace_callback(
            '/var\((--db-[a-z-]+)\)/',
            static fn (array $m): string => $variables[$m[1]] ?? 'initial',
            AssetLoader::css(),
        );

        // Blok @media screen tidak berlaku untuk PDF dan hanya membingungkan parser mpdf.
        $css = (string) preg_replace('/@media\s+screen\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', '', (string) $css);

        // Krom halaman dibuat paginator di browser; engine yang memaginasi sendiri
        // tidak pernah merendernya. Dibuang supaya tidak ada flexbox yang tersisa.
        $css = (string) preg_replace('/\/\* db:krom-halaman-mulai.*?db:krom-halaman-selesai \*\//s', '', $css);

        return '.doc-flow{width:'.$variables['--db-content-w'].'}'
            .'.doc-root{font-family:'.$variables['--db-font-family']
            .';font-size:'.$variables['--db-font-size']
            .';line-height:'.$variables['--db-line-height'].'}'
            .$css;
    }

    public function headerHtmlForEngine(): ?string
    {
        return $this->withEngineTokens($this->headerHtml);
    }

    public function footerHtmlForEngine(): ?string
    {
        return $this->withEngineTokens($this->footerHtml);
    }

    /** Bentuk mengalir — masukan paginator. */
    public function flowHtml(): string
    {
        return '<div class="doc-root"><div class="doc-flow">'
            .$this->zone('header', $this->headerHtml, $this->headerRepeat, $this->headerHeight)
            .$this->zone('body', $this->bodyHtml, ZoneRepeat::All, 'auto')
            .$this->zone('footer', $this->footerHtml, $this->footerRepeat, $this->footerHeight)
            .'</div></div>';
    }

    /** Dokumen HTML mandiri: CSS dan paginator disisipkan inline, tanpa permintaan jaringan. */
    public function fullHtml(bool $autoPrint = false): string
    {
        $script = AssetLoader::paginatorJs()."\nawait paginateDocument();";

        if ($autoPrint) {
            $script .= "\nwindow.print();";
        }

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Dokumen</title><style>'.$this->css().'</style></head><body>'
            .$this->flowHtml()
            .'<script type="module">'.$script.'</script>'
            .'</body></html>';
    }

    /** mpdf memakai token sendiri untuk nomor halaman. */
    private function withEngineTokens(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return str_replace(
            [
                '<span class="'.VariableSyntax::PAGE_MARKER_CLASS.'"></span>',
                '<span class="'.VariableSyntax::PAGES_MARKER_CLASS.'"></span>',
            ],
            ['{PAGENO}', '{nbpg}'],
            $html,
        );
    }

    private function zone(string $name, ?string $html, ZoneRepeat $repeat, float|string $height): string
    {
        return sprintf(
            '<div class="doc-zone doc-zone--%s" data-zone="%s" data-repeat="%s" data-height="%s">%s</div>',
            $name,
            $name,
            $repeat->value,
            is_string($height) ? $height : rtrim(rtrim(number_format($height, 3, '.', ''), '0'), '.'),
            $html ?? '',
        );
    }
}
