<?php

namespace Maqiis\DocumentBuilder\Pdf;

use CURLFile;
use Maqiis\DocumentBuilder\Render\RenderedDocument;
use RuntimeException;

/**
 * Merender lewat Chromium sungguhan (via layanan Gotenberg), bukan engine PDF
 * yang memaginasi sendiri seperti mpdf. Dipakai saat sebuah font tidak bisa
 * dibaca benar oleh parser TTF mpdf (lihat catatan Arab di README) — Chromium
 * punya mesin shaping font sendiri, terlepas dari batasan itu.
 *
 * Karena Chromium yang merender, HTML yang dikirim persis fullHtml() yang
 * sama dipakai kanvas builder dan cetak browser — kesetiaan layar=cetak di
 * jalur ini datang gratis, bukan hasil penyesuaian kedua seperti MpdfEngine.
 *
 * Gotenberg sendiri adalah layanan HTTP terpisah (image Docker resmi
 * gotenberg/gotenberg), bukan library PHP — lihat dokumentasi instalasi
 * package untuk cara menjalankannya.
 */
final class GotenbergEngine implements PdfEngine
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function name(): string
    {
        return 'gotenberg';
    }

    public function render(RenderedDocument $document): string
    {
        if (! extension_loaded('curl')) {
            throw PdfRenderingException::engineUnavailable('gotenberg', 'ext-curl');
        }

        $htmlFile = tempnam(sys_get_temp_dir(), 'db-gotenberg-');

        if ($htmlFile === false) {
            throw PdfRenderingException::engineFailed($this->name(), new RuntimeException('Tidak bisa membuat berkas sementara.'));
        }

        file_put_contents($htmlFile, $this->readyHtml($document));

        try {
            return $this->convert($htmlFile, $document);
        } finally {
            @unlink($htmlFile);
        }
    }

    /**
     * fullHtml() dibuat untuk browser sungguhan: setelah paginasi selesai ia
     * memanggil window.print(), yang di dalam Chromium headless Gotenberg
     * tidak berarti apa-apa (tidak ada dialog cetak untuk dipicu). Baris itu
     * diganti penanda global yang dipoll Gotenberg lewat waitForExpression,
     * supaya PDF baru diambil setelah paginateDocument() benar-benar selesai.
     */
    private function readyHtml(RenderedDocument $document): string
    {
        return str_replace(
            'await paginateDocument();',
            'await paginateDocument();window.__documentBuilderReady = true;',
            $document->fullHtml(autoPrint: false),
        );
    }

    private function convert(string $htmlFile, RenderedDocument $document): string
    {
        $page = $document->pageSetup();
        $mmToInch = 1 / 25.4;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->baseUrl, '/').'/forms/chromium/convert/html',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Gotenberg-Trace: document-builder'],
            CURLOPT_POSTFIELDS => [
                'files' => new CURLFile($htmlFile, 'text/html', 'index.html'),
                // Dimensi kertas ditentukan di sini, bukan lewat preferCssPageSize:
                // ukuran halaman sesungguhnya sudah dipatok lewat CSS mm pada
                // .doc-page milik dokumen, jadi margin Chromium sendiri dinolkan
                // supaya tidak menambah bingkai kedua di luar yang sudah dihitung.
                'paperWidth' => (string) ($page->widthMm() * $mmToInch),
                'paperHeight' => (string) ($page->heightMm() * $mmToInch),
                'marginTop' => '0',
                'marginBottom' => '0',
                'marginLeft' => '0',
                'marginRight' => '0',
                'printBackground' => 'true',
                'preferCssPageSize' => 'false',
                'emulatedMediaType' => 'print',
                'waitForExpression' => 'window.__documentBuilderReady === true',
            ],
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw PdfRenderingException::engineFailed($this->name(), new RuntimeException($error ?: 'Permintaan ke Gotenberg gagal.'));
        }

        if ($status !== 200) {
            throw PdfRenderingException::engineFailed($this->name(), new RuntimeException(
                sprintf('Gotenberg mengembalikan status %d: %s', $status, substr((string) $body, 0, 500)),
            ));
        }

        return (string) $body;
    }
}
